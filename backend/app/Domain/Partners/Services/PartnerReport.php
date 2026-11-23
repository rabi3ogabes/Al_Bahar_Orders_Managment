<?php

namespace App\Domain\Partners\Services;

use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DriverLocation;
use App\Domain\Fulfillment\Services\DispatchService;
use App\Domain\Fulfillment\Services\DriverPosition;
use App\Domain\Fulfillment\Services\RouteHistory;
use App\Domain\Orders\Models\Order;
use App\Domain\Settings\Services\Settings;
use App\Support\BusinessTime;
use App\Support\Geo;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Read-only numbers for partners: one business day of orders with items, fees, SADAD fee and the net, plus route history.
 * Money stays in decimal strings converted with bcmath; nothing here writes.
 *
 * Net = amount paid − SADAD fee − delivery fee (the delivery fee is passed on to the courier side).
 * SADAD fee = paid × fee_percent / 100 + fee_fixed, from the admin settings (the gateway does not send its fee).
 *
 * With a PartnerScope (a per-partner link) the report is limited to that partner's branches and carries NO personal data: no customer
 * name or phone, and every drop-off point and every point of a recorded route is snapped to a 500 m grid (Geo::coarsen). Without a
 * scope (the old shared link) the report is exactly what it always was.
 */
class PartnerReport
{
    public function __construct(private readonly Settings $settings, private readonly DispatchService $dispatch, private readonly RouteHistory $routes) {}

    /** @return array<string, mixed> */
    public function day(string $date, ?PartnerScope $scope = null): array
    {
        [$from, $to] = BusinessTime::dateRange($date, $date);
        $orders = Order::with(['items:id,order_id,quantity,quantity_cancelled', 'payments:id,order_id,method,status,provider_reference,amount,captured_amount,paid_at'])
            ->whereBetween('placed_at', [$from, $to])->tap(fn ($q) => $this->limitToBranches($q, $scope))->orderByDesc('placed_at')->limit(1000)->get();

        $rows = $orders->map(fn (Order $o) => $this->row($o, $scope))->values();
        $sum = fn (string $k) => $rows->reduce(fn (Money $c, array $r) => $c->plus(Money::of((string) $r[$k])), Money::zero())->toDecimal();

        return [
            'date' => $date,
            'currency' => 'QAR',
            'totals' => [
                'orders' => $rows->count(), 'items' => $rows->sum('item_count'),
                'subtotal' => $sum('subtotal'), 'delivery_fee' => $sum('delivery_fee'), 'grand_total' => $sum('grand_total'),
                'paid' => $sum('paid'), 'sadad_fee' => $sum('sadad_fee'), 'net' => $sum('net'),
                'sadad_paid' => $rows->where('sadad.reference', '!=', null)->count(),
            ],
            'orders' => $rows,
            'sadad_fee_configured' => (float) $this->settings->get('payments.sadad.fee_percent', 0) > 0 || (float) $this->settings->get('payments.sadad.fee_fixed', 0) > 0,
        ];
    }

    /** @return array<string, mixed> */
    private function row(Order $o, ?PartnerScope $scope = null): array
    {
        $sadad = $o->payments->firstWhere('method', 'sadad');
        $paid = (string) $o->paid_total;
        $sadadFee = $sadad && Money::of((string) $sadad->captured_amount)->isPositive() ? $this->sadadFee((string) $sadad->captured_amount) : '0.00';
        $delivery = (string) $o->delivery_fee_total;

        return [
            'id' => $o->id, 'number' => $o->number, 'status' => $o->status->value, 'placed_at' => BusinessTime::iso($o->placed_at),
            'customer' => $scope ? null : $o->customer_name, 'phone' => $scope ? null : $this->maskPhone($o->customer_phone),
            'item_count' => (int) $o->items->sum(fn ($i) => $i->quantity - $i->quantity_cancelled),
            'subtotal' => (string) $o->subtotal, 'discount' => (string) $o->discount_total, 'delivery_fee' => $delivery, 'service_fee' => (string) $o->service_fee_total,
            'tax' => (string) $o->tax_total, 'grand_total' => (string) $o->grand_total, 'paid' => $paid,
            'payment_method' => $o->payment_method ?? $o->payments->first()?->method, 'fulfillment_type' => $o->fulfillment_type,
            'payment_status' => $o->payment_status->value,
            'sadad' => $sadad ? ['reference' => $sadad->provider_reference, 'status' => $sadad->status->value, 'amount' => (string) $sadad->captured_amount, 'fee' => $sadadFee] : ['reference' => null, 'status' => null, 'amount' => null, 'fee' => '0.00'],
            'sadad_fee' => $sadadFee,
            'net' => $this->net($paid, $sadadFee, $delivery),
        ];
    }

    public function sadadFee(string $captured): string
    {
        $pct = (string) ($this->settings->get('payments.sadad.fee_percent', 0) ?: 0);
        $fixed = number_format((float) ($this->settings->get('payments.sadad.fee_fixed', 0) ?: 0), 2, '.', '');

        return Money::of($captured)->percent($pct)->plus(Money::of($fixed))->toDecimal();
    }

    public function net(string $paid, string $sadadFee, string $delivery): string
    {
        $p = Money::of($paid);
        if (! $p->isPositive()) {
            return '0.00';
        }

        return $p->minus(Money::of($sadadFee))->minus(Money::of($delivery))->toDecimal();
    }

    /** @return array<string, mixed>|null */
    public function order(string $id, ?PartnerScope $scope = null): ?array
    {
        $o = Order::with(['items', 'payments.transactions', 'fulfillments.deliveries.driver:id,name'])->tap(fn ($q) => $this->limitToBranches($q, $scope))->find($id);
        if (! $o) {
            return null;
        }
        $row = $this->row($o, $scope);
        $deliveries = $o->fulfillments->flatMap->deliveries;

        return $row + [
            'items' => $o->items->where('quantity', '>', 0)->map(fn ($i) => ['sku' => $i->sku, 'name' => $i->name('en'), 'name_ar' => $i->name_ar, 'variant' => $i->variant_name_en, 'quantity' => (int) $i->quantity,
                'cancelled' => (int) $i->quantity_cancelled, 'unit_price' => (string) $i->unit_final_price, 'line_total' => (string) $i->line_total, 'image' => $i->image_path])->values(),
            'transactions' => $o->payments->flatMap->transactions->sortBy('created_at')->map(fn ($t) => ['type' => $t->type, 'status' => $t->status, 'reference' => $t->transaction_reference, 'amount' => $t->amount, 'at' => BusinessTime::iso($t->created_at), 'error' => $t->error])->values(),
            'deliveries' => $deliveries->map(fn (Delivery $d) => $this->deliveryRow($d, $scope))->values(),
            'destination' => Geo::validPoint($o->address_snapshot['latitude'] ?? null, $o->address_snapshot['longitude'] ?? null)
                ? $this->place((float) $o->address_snapshot['latitude'], (float) $o->address_snapshot['longitude'], $scope) : null,
        ];
    }

    /**
     * One delivery with its recorded path: route = the breadcrumbs reduced to at most 300 points, stops = where the driver stood still,
     * distance_m / duration_s / started_at / ended_at describe the whole recording.
     *
     * @return array<string, mixed>
     */
    private function deliveryRow(Delivery $d, ?PartnerScope $scope = null): array
    {
        $r = $this->routes->forDelivery($d->id);

        return [
            'id' => $d->id, 'status' => $d->status->value, 'driver' => DriverPosition::firstName($d->driver?->name) ?: null, 'assigned_at' => BusinessTime::iso($d->assigned_at), 'picked_up_at' => BusinessTime::iso($d->picked_up_at),
            'delivered_at' => BusinessTime::iso($d->delivered_at), 'route' => $this->path($r['points'], $scope), 'stops' => $this->places($r['stops'], $scope), 'distance_m' => $r['distance_m'], 'duration_s' => $r['duration_s'],
            'started_at' => $r['started_at'], 'ended_at' => $r['ended_at'],
        ];
    }

    /**
     * The drivers on duty for the partners' live map: position fields as in every map response (lat, lng, heading, speed_kmh,
     * accuracy_m, at, age_seconds, stale), the vehicle and the order in hand. No phone numbers, battery or branches.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function fleet(?PartnerScope $scope = null): Collection
    {
        /** @var Collection<int, array<string, mixed>> $fleet */
        $fleet = $this->dispatch->fleet($scope?->branchIds)->map(fn (array $d) => [
            'id' => $d['id'], 'name' => DriverPosition::firstName($d['name']), 'vehicle' => $d['vehicle'], 'on_duty' => $d['on_duty'],
            'lat' => $d['lat'], 'lng' => $d['lng'], 'heading' => $d['heading'], 'speed_kmh' => $d['speed_kmh'], 'accuracy_m' => $d['accuracy_m'],
            'at' => $d['at'], 'age_seconds' => $d['age_seconds'], 'stale' => $d['stale'], 'last_seen_at' => $d['last_seen_at'],
            'job' => $d['job'] ? ['order_number' => $d['job']['order_number'], 'status' => $d['job']['status'], 'destination' => $this->destination($d['job']['destination'], $scope)] : null,
        ])->values();

        return $fleet;
    }

    /**
     * Every delivery that has driver positions recorded on a business day, for the route replay: each with its simplified path
     * (at most 120 points) and stops. Drivers appear by first name only. At most 40 trips, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function routes(string $date, ?PartnerScope $scope = null): array
    {
        [$from, $to] = BusinessTime::dateRange($date, $date);
        $ids = DriverLocation::whereNotNull('delivery_id')->whereBetween('recorded_at', [$from, $to])->distinct()->limit(40)->pluck('delivery_id');

        return Delivery::with(['driver:id,name', 'fulfillment.order:id,number'])->whereIn('id', $ids)
            ->when($scope?->branchIds !== null, fn ($q) => $q->whereHas('fulfillment', fn ($f) => $f->whereIn('branch_id', (array) $scope?->branchIds)))->get()
            ->sortBy(fn (Delivery $d) => $d->assigned_at?->getTimestamp() ?? 0)->values()
            ->map(function (Delivery $d) use ($scope) {
                $r = $this->routes->forDelivery($d->id, 120);

                return [
                    'id' => $d->id, 'order_number' => $d->fulfillment?->order?->number, 'status' => $d->status->value,
                    'driver' => DriverPosition::firstName($d->driver?->name) ?: null,
                    'route' => $this->path($r['points'], $scope), 'stops' => $this->places($r['stops'], $scope), 'distance_m' => $r['distance_m'], 'duration_s' => $r['duration_s'],
                    'started_at' => $r['started_at'], 'ended_at' => $r['ended_at'],
                ];
            })->all();
    }

    /** Only the orders that have a part at one of the partner's branches. @param \Illuminate\Database\Eloquent\Builder<Order> $q */
    private function limitToBranches($q, ?PartnerScope $scope): void
    {
        if ($scope?->branchIds !== null) {
            $q->whereHas('fulfillments', fn ($f) => $f->whereIn('branch_id', $scope->branchIds));
        }
    }

    /** @return array{lat: float, lng: float} */
    private function place(float $lat, float $lng, ?PartnerScope $scope): array
    {
        return $scope ? Geo::coarsen($lat, $lng) : ['lat' => $lat, 'lng' => $lng];
    }

    /**
     * @param  array<string, mixed>|null  $dest
     * @return array<string, mixed>|null
     */
    private function destination(?array $dest, ?PartnerScope $scope): ?array
    {
        return $dest !== null && $scope && isset($dest['lat'], $dest['lng']) ? array_merge($dest, $this->place((float) $dest['lat'], (float) $dest['lng'], $scope)) : $dest;
    }

    /**
     * A recorded path, rounded like every other private place: each point snapped to the grid, repeated cells folded into one.
     *
     * @param  list<array<string, mixed>>  $points
     * @return list<array<string, mixed>>
     */
    private function path(array $points, ?PartnerScope $scope): array
    {
        if (! $scope) {
            return $points;
        }
        $out = [];
        foreach ($points as $p) {
            $c = Geo::coarsen((float) $p['lat'], (float) $p['lng']);
            $last = $out[count($out) - 1] ?? null;
            if ($last === null || $last['lat'] !== $c['lat'] || $last['lng'] !== $c['lng']) {
                $out[] = array_merge($p, $c);
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $stops
     * @return list<array<string, mixed>>
     */
    private function places(array $stops, ?PartnerScope $scope): array
    {
        return $scope ? array_map(fn (array $s) => array_merge($s, Geo::coarsen((float) $s['lat'], (float) $s['lng'])), $stops) : $stops;
    }

    private function maskPhone(?string $phone): ?string
    {
        return $phone ? substr($phone, 0, 4).str_repeat('•', max(0, strlen($phone) - 7)).substr($phone, -3) : null;
    }
}
