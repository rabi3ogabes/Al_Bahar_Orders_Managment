<?php

namespace App\Domain\Reports\Services;

use App\Domain\Settings\Services\Settings;
use App\Support\BusinessTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Did that offer pay for itself?" (docs/guides/OWNER_PACK_AND_CUSTOMER_VALUE.md): for every coupon, promotion, customer journey and sent campaign, what
 * the orders that came through it were worth, what it cost in discount, and one number that says whether the discount was worth giving.
 *
 * THE DEFINITIONS (the page shows the same words):
 *  - Orders: the orders placed in the period that count as sales ({@see SalesBasis}: not cancelled, not waiting for online payment) and came through the
 *    offer: a coupon or promotion that was applied to them; a journey message that brought the customer back (the journey's own attribution window); a
 *    campaign that was sent to the customer, up to N days before the order (the last campaign wins, so an order is credited to one campaign only).
 *  - Net revenue: what those orders were booked at, after every discount, with delivery and tax (the dashboard's sales figure, summed over the orders).
 *  - Discount given: the discount the offer itself took off (what the coupon usage rows say). A campaign or journey with no coupon gave no discount.
 *  - Revenue before discount: net revenue plus the discount.
 *  - Return on discount: net revenue divided by the discount given ("for every 1 QAR we gave away, N QAR of sales came in").
 *  - New customers: customers whose very first order of all was one of these orders. Returning: the rest.
 *  - The verdict: "not enough data yet" below a minimum number of orders; "no discount given" when nothing was given away; otherwise strong (5 or more),
 *    fair (2 to 5) or weak (under 2).
 *
 * What this cannot know: which of those orders would have been placed anyway. An offer given to loyal customers shows a high return because they
 * were going to buy; read it as the most the offer could have earned, not as proof that it earned that.
 */
final class OfferReturn
{
    public const KINDS = ['coupon', 'promotion', 'journey', 'campaign'];

    public const MIN_ORDERS_DEFAULT = 5;

    public const CAMPAIGN_DAYS_DEFAULT = 7;

    public const STRONG_AT = 5.0;

    public const FAIR_AT = 2.0;

    public function __construct(private readonly Settings $settings) {}

    public function minOrders(): int
    {
        $v = $this->settings->get('offer_return.min_orders', self::MIN_ORDERS_DEFAULT);

        return is_numeric($v) && (int) $v >= 1 && (int) $v <= 1000 ? (int) $v : self::MIN_ORDERS_DEFAULT;
    }

    public function campaignDays(): int
    {
        $v = $this->settings->get('offer_return.campaign_days', self::CAMPAIGN_DAYS_DEFAULT);

        return is_numeric($v) && (int) $v >= 1 && (int) $v <= 60 ? (int) $v : self::CAMPAIGN_DAYS_DEFAULT;
    }

    /**
     * @return array{overall: array<string, mixed>, offers: list<array<string, mixed>>}
     */
    public function report(string $from, string $to): array
    {
        [$start, $end] = BusinessTime::dateRange($from, $to);

        // What each order of the period was booked at is worked out ONCE (the heavy part: a split order is divided between its branches) into a
        // temporary table that lives for this transaction only, and every kind of offer reads it. Worked out per kind it would be four more passes.
        return DB::transaction(function () use ($start, $end) {
            $shares = DB::query()->fromSub(SalesBasis::orderShares($start, $end), 's')->selectRaw('s.order_id, sum(s.share) as booked')->groupBy('s.order_id');
            DB::statement('drop table if exists offer_booked');
            DB::statement('create temporary table offer_booked on commit drop as '.$shares->toSql(), $shares->getBindings());
            $booked = DB::table('offer_booked')->select(['order_id', 'booked']);

            $offers = [];
            foreach (['coupon' => $this->coupons(), 'promotion' => $this->promotions(), 'journey' => $this->journeys(), 'campaign' => $this->campaigns($start, $end)] as $kind => $attribution) {
                foreach ($this->aggregate($attribution, $booked) as $row) {
                    $offers[] = $this->shape($kind, $row);
                }
            }
            usort($offers, fn (array $a, array $b) => [$b['booked_minor'], $a['label_en']] <=> [$a['booked_minor'], $b['label_en']]);

            $all = DB::table('coupon_usage as cu')->where('cu.status', 'applied')->groupBy('cu.order_id')
                ->selectRaw("'all' as offer_key, 'all' as label_en, 'all' as label_ar, cu.order_id, max(cu.user_id::text)::uuid as user_id, sum(cu.discount_amount) as discount");
            $overall = $this->aggregate($all, $booked)->first();

            return ['overall' => $this->shape('all', $overall ?? (object) ['offer_key' => 'all', 'label_en' => 'all', 'label_ar' => 'all', 'orders' => 0, 'customers' => 0, 'new_customers' => 0, 'booked' => '0', 'discount' => '0']),
                'offers' => $offers];
        });
    }

    /** The verdict of one offer: not_enough | no_cost | strong | fair | weak. */
    public static function verdict(int $orders, Money $discount, ?float $multiple, int $minOrders): string
    {
        return match (true) {
            $orders < $minOrders => 'not_enough',
            $discount->isZero() || $multiple === null => 'no_cost',
            $multiple >= self::STRONG_AT => 'strong',
            $multiple >= self::FAIR_AT => 'fair',
            default => 'weak',
        };
    }

    /**
     * Groups an attribution (offer_key, label_en, label_ar, order_id, user_id, discount) into one row per offer, limited to the orders of the period that
     * count as sales (the join to $booked): a cancelled order, or one outside the period, never appears.
     *
     * @return Collection<int, object>
     */
    private function aggregate(Builder $attribution, Builder $booked): Collection
    {
        $rows = DB::query()->fromSub($attribution, 'a')->joinSub($booked, 'b', 'b.order_id', '=', 'a.order_id')->join('orders as o', 'o.id', '=', 'a.order_id')
            ->selectRaw("a.offer_key, a.label_en, a.label_ar, a.order_id, a.user_id, a.discount, b.booked,
                (o.user_id is not null and not exists (select 1 from orders p where p.user_id = o.user_id and p.placed_at < o.placed_at
                    and p.status not in ('cancelled', 'pending_payment'))) as is_new");

        return DB::query()->fromSub($rows, 'x')->selectRaw('x.offer_key, max(x.label_en) as label_en, max(x.label_ar) as label_ar, count(distinct x.order_id) as orders,
                count(distinct x.user_id) as customers, count(distinct x.user_id) filter (where x.is_new) as new_customers,
                coalesce(sum(x.booked), 0)::numeric(14,2) as booked, coalesce(sum(x.discount), 0)::numeric(14,2) as discount')
            ->groupBy('x.offer_key')->orderBy('x.offer_key')->get();
    }

    /** @return array<string, mixed> */
    private function shape(string $kind, object $r): array
    {
        $booked = Money::of((string) $r->booked);
        $discount = Money::of((string) $r->discount);
        $before = $booked->plus($discount);
        $multiple = $discount->isZero() ? null : round($booked->minor / $discount->minor, 1);
        $orders = (int) $r->orders;
        $customers = (int) $r->customers;
        $new = min((int) $r->new_customers, $customers);

        return ['key' => (string) $r->offer_key, 'kind' => $kind, 'label_en' => (string) $r->label_en, 'label_ar' => (string) ($r->label_ar ?: $r->label_en), 'orders' => $orders,
            'customers' => $customers, 'new_customers' => $new, 'returning_customers' => $customers - $new, 'booked' => $booked->toDecimal(), 'booked_minor' => $booked->minor,
            'discount' => $discount->toDecimal(), 'before_discount' => $before->toDecimal(), 'multiple' => $multiple,
            'discount_rate' => $before->minor > 0 && ! $discount->isZero() ? round($discount->minor * 100 / $before->minor, 1) : null,
            'verdict' => self::verdict($orders, $discount, $multiple, $this->minOrders())];
    }

    /** Shared coupon codes (a code anyone can use), one offer per code. A personal code is counted in its promotion and in the journey that sent it. */
    private function coupons(): Builder
    {
        return DB::table('coupon_usage as cu')->join('coupons as c', 'c.id', '=', 'cu.coupon_id')->where('cu.status', 'applied')->whereNull('c.assigned_user_id')
            ->selectRaw("'coupon:' || c.id::text as offer_key, c.code as label_en, c.code as label_ar, cu.order_id, cu.user_id, cu.discount_amount as discount");
    }

    /** Every promotion that was applied to an order: its coupons (shared or personal) and its automatic use. */
    private function promotions(): Builder
    {
        return DB::table('coupon_usage as cu')->join('promotions as p', 'p.id', '=', 'cu.promotion_id')->where('cu.status', 'applied')
            ->selectRaw("'promotion:' || p.id::text as offer_key, p.name_en as label_en, p.name_ar as label_ar, cu.order_id, cu.user_id, cu.discount_amount as discount");
    }

    /** Orders a journey brought back (its own attribution), with the discount of the coupon its message carried, when the customer used it. */
    private function journeys(): Builder
    {
        return DB::table('journey_runs as jr')->join('journeys as j', 'j.id', '=', 'jr.journey_id')
            ->leftJoin('journey_deliveries as jd', 'jd.id', '=', 'jr.attributed_delivery_id')
            ->leftJoin('coupons as c', 'c.code', '=', 'jd.coupon_code')
            ->leftJoin('coupon_usage as cu', fn ($j) => $j->on('cu.coupon_id', '=', 'c.id')->on('cu.order_id', '=', 'jr.order_id')->where('cu.status', 'applied'))
            ->where('jr.status', 'converted')->whereNotNull('jr.order_id')
            ->selectRaw("'journey:' || j.id::text as offer_key, j.name as label_en, j.name as label_ar, jr.order_id, jr.user_id, coalesce(cu.discount_amount, 0) as discount");
    }

    /**
     * Orders placed by customers a campaign was sent to, up to N days after it was sent: the LAST campaign sent before the order is the one credited.
     * A campaign carries no coupon of its own, so it records no discount; its cost is not tracked.
     */
    private function campaigns(CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        $days = $this->campaignDays();
        $matches = DB::table('marketing_campaigns as mc')
            ->join('marketing_campaign_recipients as r', fn ($j) => $j->on('r.campaign_id', '=', 'mc.id')->where('r.state', 'sent'))
            ->join('orders as o', fn ($j) => $j->on('o.user_id', '=', 'r.user_id')->whereColumn('o.placed_at', '>=', 'mc.sent_at')
                ->whereRaw("o.placed_at < mc.sent_at + make_interval(days => {$days})"))
            ->whereNotNull('mc.sent_at')->whereBetween('mc.sent_at', [$start->subDays($days), $end])->whereBetween('o.placed_at', [$start, $end])
            ->selectRaw('distinct on (o.id) mc.id as campaign_id, mc.name as name, o.id as order_id, o.user_id as user_id')->orderBy('o.id')->orderByDesc('mc.sent_at');

        return DB::query()->fromSub($matches, 'm')
            ->selectRaw("'campaign:' || m.campaign_id::text as offer_key, m.name as label_en, m.name as label_ar, m.order_id, m.user_id, 0 as discount");
    }
}
