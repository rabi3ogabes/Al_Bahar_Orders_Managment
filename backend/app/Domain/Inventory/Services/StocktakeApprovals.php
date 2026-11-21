<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Inventory\Models\StocktakingSession;
use App\Domain\Operations\Services\StaffAlerts;
use App\Domain\Settings\Services\Settings;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The rule behind "the person who counted does not approve the count" (roadmap 4.6), and the alerts around it.
 *
 *  - The people who COUNTED a session are whoever recorded a quantity on any of its lines and whoever finished the count. None of them may approve it
 *    (or send it back) while the rule is on, owners and super admins included. The rule is on until an admin switches it off in Settings > Stock
 *    (for a shop with a single person); the change is a normal audited setting.
 *  - A count with no difference does not need approval. A count whose differences are worth no more than "Post without approval up to" (QAR, at cost) is
 *    posted when it is finished; 0 (the default) means every difference needs a second person. A count whose value is unknown (an item with no cost) is
 *    never waved through.
 *  - The value of a difference is |counted - expected| x cost, the cost being the branch's average cost, else the variant's cost, else the product's.
 */
class StocktakeApprovals
{
    public const RULE_KEY = 'stocktake.second_person_required';

    public const AUTO_KEY = 'stocktake.auto_post_up_to';

    public const EVENT_WAITING = 'staff.stocktake_waiting';

    public const EVENT_RETURNED = 'staff.stocktake_returned';

    public function __construct(private readonly Settings $settings, private readonly AccessControl $acl, private readonly StaffAlerts $alerts) {}

    /** Is a second person needed (the default)? Off only when an admin said so. */
    public function secondPersonRequired(): bool
    {
        return (bool) $this->settings->get(self::RULE_KEY, true);
    }

    /** Differences worth no more than this are posted when the count is finished (QAR; zero = never). */
    public function autoPostUpTo(): Money
    {
        $value = $this->settings->get(self::AUTO_KEY, '0');

        return is_numeric($value) && (float) $value > 0 ? Money::of(number_format(min((float) $value, 100000), 2, '.', '')) : Money::zero();
    }

    /**
     * What a count would change: the lines whose count differs from what was expected, the units they add up to, the net change, and what it is worth.
     * `value` is null when some difference has no known cost.
     *
     * @return array{lines: int, units: int, net: int, value: Money|null, unpriced: int}
     */
    public function summary(StocktakingSession $session): array
    {
        $row = DB::table('stocktaking_items as si')
            ->join('product_variants as v', 'v.id', '=', 'si.product_variant_id')
            ->leftJoin('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('branch_inventory as bi', fn ($j) => $j->on('bi.product_variant_id', '=', 'si.product_variant_id')->where('bi.branch_id', $session->branch_id))
            ->where('si.stocktaking_session_id', $session->id)->whereNotNull('si.counted_quantity')->whereColumn('si.counted_quantity', '<>', 'si.expected_quantity')
            ->selectRaw('count(*)::int as lines, coalesce(sum(abs(si.counted_quantity - si.expected_quantity)), 0)::int as units, coalesce(sum(si.counted_quantity - si.expected_quantity), 0)::int as net,
                count(*) filter (where coalesce(bi.average_cost, v.cost_price, p.cost_price) is null)::int as unpriced,
                round(coalesce(sum(abs(si.counted_quantity - si.expected_quantity) * coalesce(bi.average_cost, v.cost_price, p.cost_price, 0)), 0), 2) as value')
            ->first();
        $unpriced = (int) ($row->unpriced ?? 0);

        return ['lines' => (int) ($row->lines ?? 0), 'units' => (int) ($row->units ?? 0), 'net' => (int) ($row->net ?? 0),
            'value' => $unpriced > 0 ? null : Money::of((string) ($row->value ?? '0')), 'unpriced' => $unpriced];
    }

    /**
     * Does a count with these differences post by itself when it is finished?
     *
     * @param  array{lines: int, units: int, net: int, value: Money|null, unpriced: int}  $summary
     */
    public function postsByItself(array $summary): bool
    {
        if ($summary['lines'] === 0) {
            return true;
        }
        $limit = $this->autoPostUpTo();

        return ! $limit->isZero() && $summary['value'] !== null && ! $summary['value']->greaterThan($limit);
    }

    /** @return list<string> the ids of the people who counted this session or finished it */
    public function counters(StocktakingSession $session): array
    {
        $ids = DB::table('stocktaking_items')->where('stocktaking_session_id', $session->id)->whereNotNull('counted_quantity')->whereNotNull('counted_by')
            ->distinct()->pluck('counted_by')->map(fn ($id) => (string) $id)->all();
        if ($session->submitted_by !== null) {
            $ids[] = (string) $session->submitted_by;
        }

        return array_values(array_unique($ids));
    }

    /** May this person approve (or send back) this count? False only for someone who counted it while the rule is on and there is something to approve. */
    public function mayApprove(User $actor, StocktakingSession $session, ?int $differences = null): bool
    {
        if (! $this->secondPersonRequired()) {
            return true;
        }
        $differences ??= $session->variance_lines ?? $this->summary($session)['lines'];

        return $differences === 0 || ! in_array((string) $actor->id, $this->counters($session), true);
    }

    /** The approvers of a count's branch who did not count it (the branch's own team with the right, and company-wide holders such as the owner). @return Collection<int, User> */
    public function approvers(StocktakingSession $session): Collection
    {
        $global = User::query()->where('type', 'staff')->where('status', 'active')
            ->where(fn ($q) => $q->where('is_super_admin', true)->orWhereIn('id', DB::table('user_roles')->whereNull('branch_id')->select('user_id')))->get()
            ->filter(fn (User $u) => $this->acl->allows($u, 'stocktaking.approve', $session->branch_id));
        $people = $this->alerts->recipients($session->branch_id, ['stocktaking.approve'])->concat($global)->unique('id');
        $counted = $this->secondPersonRequired() ? $this->counters($session) : [];

        return $people->reject(fn (User $u) => in_array((string) $u->id, $counted, true))->values();
    }

    /** Tells the approvers a count waits for them. @return int how many people were reached */
    public function announceWaiting(StocktakingSession $session): int
    {
        $branch = $session->branch()->first(['id', 'code', 'name_en', 'name_ar']);
        $by = $session->submitted_by ? User::whereKey($session->submitted_by)->value('name') : null;
        $value = $session->variance_value !== null ? Money::of((string) $session->variance_value) : null;

        return $this->alerts->send($this->approvers($session), self::EVENT_WAITING,
            fn (string $locale) => [
                'title' => __('stock_planning.alert.waiting_title', ['number' => $session->number], $locale),
                'body' => __('stock_planning.alert.waiting_body', ['branch' => (string) $branch?->localized('name', $locale), 'lines' => (int) $session->variance_lines,
                    'value' => $value === null ? __('stock_planning.value_unknown', [], $locale) : $value->format($locale), 'by' => $by ?? '—'], $locale),
            ],
            ['session_id' => $session->id, 'number' => $session->number, 'branch_id' => $session->branch_id], route('admin.stocktaking.show', $session, false), push: ! self::quietNow());
    }

    /** Tells the people who counted that the approver sent the count back, with the reason. @return int how many people were reached */
    public function announceReturned(StocktakingSession $session, User $by, string $reason): int
    {
        $ids = array_values(array_diff($this->counters($session), [(string) $by->id]));
        $people = User::query()->whereIn('id', $ids)->where('status', 'active')->get();

        return $this->alerts->send($people, self::EVENT_RETURNED,
            fn (string $locale) => [
                'title' => __('stock_planning.alert.returned_title', ['number' => $session->number], $locale),
                'body' => __('stock_planning.alert.returned_body', ['by' => $by->name, 'reason' => $reason], $locale),
            ],
            ['session_id' => $session->id, 'number' => $session->number, 'branch_id' => $session->branch_id], route('admin.stocktaking.show', $session, false), push: ! self::quietNow());
    }

    /** 22:00 to 08:00 shop time: alerts that nobody is waiting on minute by minute go to the inbox without waking a phone. */
    public static function quietNow(): bool
    {
        $hour = (int) BusinessTime::now()->format('G');

        return $hour >= 22 || $hour < 8;
    }
}
