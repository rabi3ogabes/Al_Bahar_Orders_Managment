<?php

namespace App\Domain\Deals\Services;

use App\Domain\Checkout\Data\AppliedDiscount;
use App\Domain\Checkout\Data\Quote;
use App\Domain\Checkout\Data\QuoteLine;
use App\Domain\Deals\Data\LineDeal;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\DealProduct;
use App\Domain\Deals\Support\DealPricing;
use App\Domain\Identity\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Decides, for a quote, which units get the deal price. It runs BEFORE PromotionEngine (units that got a deal price are not discounted twice).
 *
 * For every product that is in a running deal, the units that get the price are
 *     min(units in the cart, limit per order, what this customer may still buy, units the deal still has)
 * and the rest are charged at the normal price: nobody is blocked from buying more. The saving per unit is the normal unit price minus the deal price
 * (DealPricing); units go to the lines that save the most first, so the choice is the same on every run. The result is one AppliedDiscount per deal
 * (source `deal`) and a LineDeal on every covered line, also when it gets none of it, so the cart can say why ("limit 2 per order").
 *
 * Nothing is reserved here: a quote never holds anything. DealService::claim() takes the units when the order is placed.
 */
class DealEngine
{
    public function __construct(private readonly DealService $deals) {}

    /**
     * @param  list<QuoteLine>  $lines
     * @return list<AppliedDiscount> one per deal that saved something
     */
    public function apply(array $lines, ?User $user): array
    {
        foreach ($lines as $line) {
            $line->deal = null;
        }
        $productIds = array_values(array_unique(array_map(fn (QuoteLine $l) => $l->productId(), $lines)));
        if ($productIds === []) {
            return [];
        }
        $rows = $this->runningRows($productIds);
        $used = $user ? $this->deals->usedBy($user->id, $rows->pluck('id')->all()) : [];

        $discounts = [];
        foreach ($rows as $row) {
            $deal = $row->deal;
            // The lines of this product that the deal makes cheaper, best saving first.
            $candidates = [];
            foreach ($lines as $line) {
                if ($line->productId() !== $row->product_id) {
                    continue;
                }
                $normal = $line->price->final();
                $price = DealPricing::unitPrice($deal, $row, $normal);
                if ($price !== null) {
                    $candidates[] = ['line' => $line, 'saving' => $normal->minus($price)];
                }
            }
            if ($candidates === []) {
                continue;
            }
            usort($candidates, fn (array $a, array $b) => [$b['saving']->minor, $a['line']->key] <=> [$a['saving']->minor, $b['line']->key]);

            $wanted = array_sum(array_map(fn (array $c) => $c['line']->quantity, $candidates));
            [$allowed, $note] = $this->allowance($deal, $row, $wanted, $user ? ($used[$row->id] ?? 0) : null);

            $left = $allowed;
            $allocations = [];
            $total = Money::zero();
            foreach ($candidates as ['line' => $line, 'saving' => $saving]) {
                $units = min($left, $line->quantity);
                $left -= $units;
                $line->deal = new LineDeal($deal, $row, $units, $line->quantity, $saving, $units < $line->quantity ? $note : null);
                $line->discount = $line->dealSavings();
                if ($units > 0) {
                    $allocations[$line->key] = $line->dealSavings();
                    $total = $total->plus($line->dealSavings());
                }
            }
            if ($total->isPositive()) {
                $discounts[$deal->id] = isset($discounts[$deal->id])
                    ? AppliedDiscount::forDeal($deal, $discounts[$deal->id]->amount->plus($total), $discounts[$deal->id]->allocations + $allocations)
                    : AppliedDiscount::forDeal($deal, $total, $allocations);
            }
        }
        $this->recentlyEnded($lines, $productIds);

        return array_values($discounts);
    }

    /**
     * How many of `$wanted` units this customer may buy at the deal price, and which limit stopped the rest (null when none did).
     *
     * @param  int|null  $used  units the customer already bought in this deal product (null = no signed-in customer)
     * @return array{0: int, 1: ?string}
     */
    public function allowance(Deal $deal, DealProduct $row, int $wanted, ?int $used): array
    {
        $caps = [
            LineDeal::LIMIT_PER_ORDER => $row->perOrderLimit($deal),
            LineDeal::LIMIT_PER_CUSTOMER => ($limit = $row->perCustomerLimit($deal)) !== null && $used !== null ? max(0, $limit - $used) : null,
            LineDeal::SOLD_OUT => $row->remaining(),
        ];
        $allowed = $wanted;
        $note = null;
        foreach ($caps as $name => $cap) {
            if ($cap !== null && $cap < $allowed) {
                $allowed = $cap;
                $note = $name;
            }
        }

        return [$allowed, $note];
    }

    /** The deal a quote's units were counted for, in one short string: what changes when a deal ended, sold out or hit a limit between quote and order. */
    public function fingerprint(Quote $quote): string
    {
        $parts = [];
        foreach ($quote->lines() as $line) {
            if ($line->deal !== null && $line->deal->units > 0) {
                $parts[] = implode('|', [$line->variant->id, $line->branch->id, $line->deal->deal->id, $line->deal->units, $line->deal->unitSavings->minor]);
            }
        }
        sort($parts);

        return $parts === [] ? '' : sha1(implode(';', $parts));
    }

    /** Remember what a quote promised (30 minutes), so placing it can tell "a deal changed" from any other change. */
    public function remember(Quote $quote): void
    {
        $fingerprint = $this->fingerprint($quote);
        if ($fingerprint !== '' && $quote->hash !== null) {
            Cache::put('deal-quote:'.$quote->hash, $fingerprint, now()->addMinutes(30));
        }
    }

    /** True when the quote the customer confirmed had deal units and the fresh quote does not give the same ones. */
    public function changedSince(string $confirmedHash, Quote $fresh): bool
    {
        $before = Cache::get('deal-quote:'.$confirmedHash);

        return is_string($before) && $before !== $this->fingerprint($fresh);
    }

    /**
     * The open deal rows of these products that a customer can buy from now (running), one per product (the one ending first if data is odd).
     *
     * @param  list<string>  $productIds
     * @return Collection<int, DealProduct>
     */
    private function runningRows(array $productIds)
    {
        return DealProduct::query()->whereIn('product_id', $productIds)->whereHas('deal', fn ($q) => $q->running())->with('deal')->get()
            ->sortBy(fn (DealProduct $r) => [$r->deal->ends_at->getTimestamp(), $r->id])->unique('product_id')->values();
    }

    /**
     * A line whose product had a deal that ended a moment ago says so (note "ended"), so a customer who was in checkout when it ended is told why
     * the price changed instead of just seeing a new total.
     *
     * @param  list<QuoteLine>  $lines
     * @param  list<string>  $productIds
     */
    private function recentlyEnded(array $lines, array $productIds): void
    {
        $covered = array_map(fn (QuoteLine $l) => $l->productId(), array_filter($lines, fn (QuoteLine $l) => $l->deal !== null));
        $rest = array_values(array_diff($productIds, $covered));
        if ($rest === []) {
            return;
        }
        $since = now()->subMinutes(DealService::RECENTLY_ENDED_MINUTES);
        $rows = DealProduct::query()->whereIn('product_id', $rest)->whereHas('deal', fn ($q) => $q->where('is_active', true)
            ->where(fn ($w) => $w->where('ended_at', '>', $since)->orWhere(fn ($x) => $x->whereNull('ended_at')->where('ends_at', '>', $since)->where('ends_at', '<=', now()))))
            ->with('deal')->get()->sortByDesc(fn (DealProduct $r) => $r->deal->ended_at ?? $r->deal->ends_at)->unique('product_id');
        foreach ($rows as $row) {
            foreach ($lines as $line) {
                if ($line->deal === null && $line->productId() === $row->product_id) {
                    $line->deal = new LineDeal($row->deal, $row, 0, $line->quantity, Money::zero(), LineDeal::ENDED);
                }
            }
        }
    }
}
