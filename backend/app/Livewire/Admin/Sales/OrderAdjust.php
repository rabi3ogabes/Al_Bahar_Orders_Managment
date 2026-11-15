<?php

namespace App\Livewire\Admin\Sales;

use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Models\OrderRevision;
use App\Domain\Orders\Services\OrderAdjustmentService;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\DomainException;
use App\Support\Money;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Adjust items" for one branch part of an order that is being prepared: per item keep, reduce, replace or remove; a reason; a live preview of
 * the new total; confirm. The same OrderAdjustmentService as the staff app's API (the dashboard can never do more than the app), so the customer
 * is told, the invoice is revised and any refund is requested for approval exactly the same way.
 */
class OrderAdjust extends Component
{
    use InteractsWithAdmin;

    #[Locked]
    public string $fulfillmentId;

    /** Inside an order side window the window opens as a modal over it; on the order page it is a side window. */
    #[Locked]
    public bool $drawer = false;

    public bool $show = false;

    /**
     * What staff chose per order line.
     *
     * @var array<string, array{action: string, to: int, qty: int, variant: ?string, sub: ?array<string, mixed>}>
     */
    public array $lines = [];

    public string $reason = 'out_of_stock';

    public string $note = '';

    /** The line whose replacement search is open. */
    public ?string $searching = null;

    public string $search = '';

    public function mount(string $fulfillmentId, bool $drawer = false): void
    {
        $this->fulfillmentId = $fulfillmentId;
        $this->drawer = $drawer;
    }

    private function fulfillment(): OrderFulfillment
    {
        return OrderFulfillment::findOrFail($this->fulfillmentId);
    }

    public function open(OrderAdjustmentService $svc): void
    {
        $f = $this->fulfillment();
        $this->authorizeBranch('orders.edit', $f->branch_id);
        $context = $svc->context($f, auth()->user());
        if (! $context['can_adjust']) {
            $this->toast(__('adjust_admin.blocked'), 'error');
            $this->dispatch('order-adjusted'); // the page behind was stale: refresh it

            return;
        }
        $this->lines = collect($context['items'])->mapWithKeys(fn (array $i) => [$i['order_item_id'] => ['action' => 'keep', 'to' => max(1, $i['quantity'] - 1), 'qty' => $i['quantity'], 'variant' => null, 'sub' => null]])->all();
        $this->reason = 'out_of_stock';
        $this->note = '';
        $this->searching = null;
        $this->search = '';
        $this->resetValidation();
        $this->show = true;
    }

    public function choose(string $id, string $action): void
    {
        abort_unless(isset($this->lines[$id]) && in_array($action, ['keep', 'reduce', 'replace', 'remove'], true), 422);
        $this->lines[$id]['action'] = $action;
        $this->searching = $action === 'replace' && $this->lines[$id]['sub'] === null ? $id : ($this->searching === $id ? null : $this->searching);
        $this->search = $this->searching === $id ? '' : $this->search;
    }

    public function step(string $id, int $delta, int $max): void
    {
        abort_unless(isset($this->lines[$id]), 422);
        $key = $this->lines[$id]['action'] === 'replace' ? 'qty' : 'to';
        $low = 1;
        $high = $key === 'to' ? max(1, $max - 1) : $max;
        $this->lines[$id][$key] = max($low, min($high, (int) $this->lines[$id][$key] + $delta));
    }

    public function openSearch(string $id): void
    {
        abort_unless(isset($this->lines[$id]), 422);
        $this->searching = $id;
        $this->search = '';
    }

    public function closeSearch(): void
    {
        $this->searching = null;
    }

    public function pick(string $id, string $variantId, OrderAdjustmentService $svc): void
    {
        abort_unless(isset($this->lines[$id]), 422);
        $found = collect($svc->substitutes($this->fulfillment(), auth()->user(), '', 50))->firstWhere('variant_id', $variantId)
            ?? collect($svc->substitutes($this->fulfillment(), auth()->user(), $this->search, 50))->firstWhere('variant_id', $variantId);
        if ($found === null) {
            $this->toast(__('errors.substitute_unavailable'), 'error');

            return;
        }
        // What the customer pays per unit: the substitute's current price, but never more than the unit it replaces.
        $original = collect($svc->context($this->fulfillment(), auth()->user())['items'])->firstWhere('order_item_id', $id);
        $pays = Money::of($found['unit_price']['amount'])->min(Money::of($original['unit_price']['amount'] ?? $found['unit_price']['amount']));
        $this->lines[$id]['variant'] = $found['variant_id'];
        $this->lines[$id]['sub'] = ['name' => $found['name'], 'variant_name' => $found['variant_name'], 'price' => $pays->format(), 'capped' => $pays->lessThan(Money::of($found['unit_price']['amount'])),
            'list_price' => $found['unit_price']['formatted'], 'available' => $found['available']];
        $this->lines[$id]['action'] = 'replace';
        $this->searching = null;
    }

    /** @return list<array<string, mixed>> the choices as the service takes them: items left as they are are not changes, a replacement without a substitute is not complete. */
    private function changes(bool $complete = true): array
    {
        $out = [];
        foreach ($this->lines as $id => $l) {
            $out[] = match ($l['action']) {
                'reduce' => ['order_item_id' => $id, 'action' => 'reduce', 'new_quantity' => (int) $l['to']],
                'remove' => ['order_item_id' => $id, 'action' => 'remove'],
                'replace' => $l['variant'] ? ['order_item_id' => $id, 'action' => 'replace', 'variant_id' => $l['variant'], 'quantity' => (int) $l['qty']] : null,
                default => null,
            };
        }

        return array_values(array_filter($out));
    }

    public function confirm(OrderAdjustmentService $svc): void
    {
        $f = $this->fulfillment();
        $this->authorizeBranch('orders.edit', $f->branch_id);
        if (collect($this->lines)->contains(fn ($l) => $l['action'] === 'replace' && ! $l['variant'])) {
            $this->addError('lines', __('adjust_admin.pick_replacement'));

            return;
        }
        $changes = $this->changes();
        if ($changes === []) {
            $this->addError('lines', __('adjust_admin.nothing_changed'));

            return;
        }
        $this->validate(['reason' => 'required|in:'.implode(',', OrderRevision::REASONS), 'note' => 'nullable|string|max:500'], [], ['reason' => __('adjust_admin.reason'), 'note' => __('adjust_admin.note')]);
        $result = null;
        try {
            $result = $svc->adjust($f, auth()->user(), $changes, $this->reason, $this->note, preview: false);
        } catch (DomainException $e) {
            $this->toast($e->getMessage(), 'error');
            // Someone changed the order meanwhile (a repeat, another screen): show what it is now.
            $this->dispatch('order-adjusted');
            $this->show = false;

            return;
        }
        $refund = $result['refund']['amount']['formatted'] ?? null;
        $this->toast($refund ? __('adjust_admin.done_refund', ['amount' => $refund]) : __('adjust_admin.done'));
        $this->show = false;
        $this->lines = [];
        $this->dispatch('order-adjusted');
    }

    public function render(OrderAdjustmentService $svc)
    {
        $f = $this->fulfillment();
        $order = Order::findOrFail($f->order_id);
        $context = $this->show ? $svc->context($f, auth()->user()) : null;

        $preview = null;
        $problem = null;
        $changes = $this->show ? $this->changes() : [];
        if ($changes !== []) {
            try {
                $preview = $svc->adjust($f, auth()->user(), $changes, $this->reason, null, preview: true);
            } catch (DomainException $e) {
                $problem = $e->getMessage();
            }
        }

        return view('livewire.admin.sales.order-adjust', [
            'f' => $f, 'order' => $order, 'context' => $context, 'preview' => $preview, 'problem' => $problem,
            'results' => $this->show && $this->searching !== null ? $svc->substitutes($f, auth()->user(), $this->search, 12) : [],
            'reasons' => OrderRevision::REASONS,
            'ready' => $preview !== null && collect($this->lines)->doesntContain(fn ($l) => $l['action'] === 'replace' && ! $l['variant']),
        ]);
    }
}
