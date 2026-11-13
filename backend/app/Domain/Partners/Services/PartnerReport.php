<?php

namespace App\Domain\Partners\Services;

use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Services\DispatchService;
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
 */
class PartnerReport
{
    public function __construct(private readonly Settings $settings, private readonly DispatchService $dispatch, private readonly RouteHistory $routes) {}

    /** @return array<string, mixed> */
    public function day(string $date): array
    {
        [$from, $to] = BusinessTime::dateRange($date, $date);
        $orders = Order::with(['items:id,order_id,quantity,quantity_cancelled', 'payments:id,order_id,method,status,provider_reference,amount,captured_amount,paid_at'])
            ->whereBetween('placed_at', [$from, $to])->orderByDesc('placed_at')->limit(1000)->get();

        $rows = $orders->map(fn (Order $o) => $this->row($o))->values();
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
    private function row(Order $o): array
    {
        $sadad = $o->payments->firstWhere('method', 'sadad');
        $paid = (string) $o->paid_total;
        $sadadFee = $sadad && Money::of((string) $sadad->captured_amount)->isPositive() ? $this->sadadFee((string) $sadad->captured_amount) : '0.00';
        $delivery = (string) $o->delivery_fee_total;

        return [
            'id' => $o->id, 'number' => $o->number, 'status' => $o->status->value, 'placed_at' => BusinessTime::iso($o->placed_at),
            'customer' => $o->customer_name, 'phone' => $this->maskPhone($o->customer_phone),
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
    public function order(string $id): ?array
    {
        $o = Order::with(['items', 'payments.transactions', 'fulfillments.deliveries.driver:id,name'])->find($id);
        if (! $o) {
            return null;
        }
        $row = $this->row($o);
        $deliveries = $o->fulfillments->flatMap->deliveries;

        return $row + [
            'items' => $o->items->map(fn ($i) => ['sku' => $i->sku, 'name' => $i->name('en'), 'name_ar' => $i->name_ar, 'variant' => $i->variant_name_en, 'quantity' => (int) $i->quantity,
                'cancelled' => (int) $i->quantity_cancelled, 'unit_price' => (string) $i->unit_final_price, 'line_total' => (string) $i->line_total, 'image' => $i->image_path])->values(),
            'transactions' => $o->payments->flatMap->transactions->sortBy('created_at')->map(fn ($t) => ['type' => $t->type, 'status' => $t->status, 'reference' => $t->transaction_reference, 'amount' => $t->amount, 'at' => BusinessTime::iso($t->created_at), 'error' => $t->error])->values(),
            'deliveries' => $deliveries->map(fn (Delivery $d) => $this->deliveryRow($d))->values(),
            'destination' => Geo::validPoint($o->address_snapshot['latitude'] ?? null, $o->address_snapshot['longitude'] ?? null)
                ? ['lat' => (float) $o->address_snapshot['latitude'], 'lng' => (float) $o->address_snapshot['longitude']] : null,
        ];
    }

    /**
     * One delivery with its recorded path: route = the breadcrumbs reduced to at most 300 points, stops = where the driver stood still,
     * distance_m / duration_s / started_at / ended_at describe the whole recording.
     *
     * @return array<string, mixed>
     */
    private function deliveryRow(Delivery $d): array
    {
        $r = $this->routes->forDelivery($d->id);

        return [
            'id' => $d->id, 'status' => $d->status->value, 'driver' => $d->driver?->name, 'assigned_at' => BusinessTime::iso($d->assigned_at), 'picked_up_at' => BusinessTime::iso($d->picked_up_at),
            'delivered_at' => BusinessTime::iso($d->delivered_at), 'route' => $r['points'], 'stops' => $r['stops'], 'distance_m' => $r['distance_m'], 'duration_s' => $r['duration_s'],
            'started_at' => $r['started_at'], 'ended_at' => $r['ended_at'],
        ];
    }

    /**
     * The drivers on duty for the partners' live map: position fields as in every map response (lat, lng, heading, speed_kmh,
     * accuracy_m, at, age_seconds, stale), the vehicle and the order in hand. No phone numbers, battery or branches.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function fleet(): Collection
    {
        return $this->dispatch->fleet(null)->map(fn (array $d) => [
            'id' => $d['id'], 'name' => $d['name'], 'vehicle' => $d['vehicle'], 'on_duty' => $d['on_duty'],
            'lat' => $d['lat'], 'lng' => $d['lng'], 'heading' => $d['heading'], 'speed_kmh' => $d['speed_kmh'], 'accuracy_m' => $d['accuracy_m'],
            'at' => $d['at'], 'age_seconds' => $d['age_seconds'], 'stale' => $d['stale'], 'last_seen_at' => $d['last_seen_at'],
            'job' => $d['job'] ? ['order_number' => $d['job']['order_number'], 'status' => $d['job']['status'], 'destination' => $d['job']['destination']] : null,
        ])->values();
    }

    private function maskPhone(?string $phone): ?string
    {
        return $phone ? substr($phone, 0, 4).str_repeat('•', max(0, strlen($phone) - 7)).substr($phone, -3) : null;
    }
}
