<?php

namespace App\Domain\Fulfillment\Services;

use App\Domain\Branches\Models\DeliverySlot;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Support\AlternatePhone;
use App\Support\BusinessTime;
use App\Support\Money;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The driver's job, as the driver app reads it (`GET dispatch/driver/jobs`, `.../jobs/{id}`, `.../history`): what and where and when first, then
 * everything he needs before he leaves (who to call, the gate code, how heavy it is, how much cash).
 *
 *  - An OPEN job (not finished) carries the whole picture: the customer's notes and delivery instructions, who receives it (the person at the
 *    address is not always the account holder), the delivery slot's window, the items with weight and size, the "before you go" facts, the cash
 *    to collect and a one-line payment note. Each fact is only there when the data says so: a plain parcel has no "needs two people" in it.
 *  - A FINISHED job (history) is the short version: order number, area, time, status, amount, items. Without the customer's phone numbers, the
 *    recipient and the street once 24 hours have passed since it was delivered or failed (`redacted: true`): a driver has no reason to keep
 *    a customer's door and number on his phone for ever.
 *
 * No prices of the goods (the driver is not the cashier of anything but the cash he collects). Built for the driver's OWN jobs only: the callers
 * query by his id.
 */
final class DriverJobPresenter
{
    /** Hours after which a finished job loses the customer's phone numbers and street from the driver's history. */
    public const HISTORY_DETAIL_HOURS = 24;

    private const KG = ['kg' => 1.0, 'g' => 0.001, 'lb' => 0.45359237, 'lbs' => 0.45359237, 'oz' => 0.0283495];

    private const CM = ['cm' => 1.0, 'mm' => 0.1, 'm' => 100.0, 'in' => 2.54];

    /** The address parts that locate the door: dropped from an old history row. */
    private const DOOR = ['street_number', 'building_number', 'unit_number', 'landmark', 'instructions', 'notes', 'latitude', 'longitude', 'recipient_name', 'recipient_phone', 'label'];

    public function __construct(private readonly DispatchSettings $config) {}

    /** @return array<string, mixed> */
    public function job(Delivery $d): array
    {
        return $this->many(new Collection([$d]))[0];
    }

    /**
     * @param  Collection<int, Delivery>  $rows  with fulfillment.order, fulfillment.branch and fulfillment.items loaded
     * @return list<array<string, mixed>>
     */
    public function many(Collection $rows): array
    {
        $rows->each(fn (Delivery $d) => $d->fulfillment->loadMissing('order', 'branch', 'items'));
        $catalog = $this->catalog($rows);
        $slots = $this->slots($rows);

        return $rows->map(fn (Delivery $d) => $this->one($d, $catalog, $slots))->values()->all();
    }

    /**
     * @param  array{products: Collection<string, object>, variants: Collection<string, object>}  $catalog
     * @param  Collection<string, DeliverySlot>  $slots
     * @return array<string, mixed>
     */
    private function one(Delivery $d, array $catalog, Collection $slots): array
    {
        $f = $d->fulfillment;
        $o = $f->order;
        $finished = in_array($d->status->value, ['delivered', 'returned', 'cancelled'], true);
        $items = $this->items($f->items, $catalog);
        $address = (array) ($o->address_snapshot ?? []);

        $job = [
            'id' => $d->id, 'tracking_number' => $d->tracking_number, 'status' => $d->status->value, 'fulfillment_id' => $d->order_fulfillment_id,
            'order_id' => $o->id, 'seq' => (int) $o->progress_seq,
            'order_number' => $o->number, 'customer_name' => $o->customer_name, 'customer_phone' => $o->customer_phone,
            // The customer's second number, for the driver of THIS delivery (the callers only ask for the driver's own jobs).
            'alternate_phone' => AlternatePhone::visible($o->alternate_phone), 'address' => $address ?: $o->address_snapshot,
            'payment_method' => $o->payment_method, 'cod_amount' => $d->cod_amount, 'requires_proof' => $d->requires_proof,
            'branch' => ['id' => $d->branch_id, 'name' => $f->branch?->localized('name')],
            'scheduled_for' => BusinessTime::iso($d->scheduled_for), 'assigned_at' => BusinessTime::iso($d->assigned_at),
            'items' => $items['rows'],
            'arrived_at' => BusinessTime::iso($d->arrived_at),
            'can_decline' => $d->status->value === 'assigned',
            'redacted' => false,
        ];

        if ($finished) {
            return $this->redactIfOld($d, $job);
        }

        return $job + $this->context($d, $o, $address, $items, $slots->get($f->delivery_slot_id));
    }

    /**
     * What an open job adds: the notes, the recipient, the slot's window, the "before you go" facts and the cash.
     *
     * @param  array<string, mixed>  $address
     * @param  array{rows: list<array<string, mixed>>, weight: ?float, oversized: bool, assembly: bool}  $items
     * @return array<string, mixed>
     */
    private function context(Delivery $d, Order $o, array $address, array $items, ?DeliverySlot $slot): array
    {
        $recipientPhone = $this->text($address['recipient_phone'] ?? null);
        $recipientName = $this->text($address['recipient_name'] ?? null);
        // The person at the door is the account holder unless the order says otherwise (another phone number, or a name that is not his).
        $someoneElse = ($recipientPhone !== null && ! Phone::same($recipientPhone, $o->customer_phone))
            || ($recipientPhone === null && $recipientName !== null && mb_strtolower($recipientName) !== mb_strtolower(trim((string) $o->customer_name)));
        $weight = $items['weight'];
        $twoPeople = $items['oversized'] || ($weight !== null && $weight > $this->config->twoPersonWeightKg());
        $cod = $o->payment_method === 'cod' && Money::of($d->cod_amount)->isPositive();

        return [
            'recipient' => ['name' => $recipientName, 'phone' => $recipientPhone, 'is_account_holder' => ! $someoneElse],
            'instructions' => $this->text($address['instructions'] ?? null),
            'customer_notes' => $this->text($o->customer_notes),
            'landmark' => $this->text($address['landmark'] ?? null),
            'slot' => $slot !== null && $d->fulfillment->slot_date !== null ? $this->window($slot, $d->fulfillment->slot_date->toDateString()) : null,
            'total_weight_kg' => $weight !== null ? round($weight, 1) : null,
            'needs_two_people' => $twoPeople,
            'is_oversized' => $items['oversized'],
            'requires_assembly' => $items['assembly'],
            'cod' => ['due' => $cod, 'amount' => Money::of($d->cod_amount)->toDecimal(), 'currency' => 'QAR'],
            'payment_note' => match (true) {
                $cod => __('driver_day.payment.cash', ['amount' => Money::of($d->cod_amount)->format()]),
                $o->payment_method === 'cod' => __('driver_day.payment.nothing'),
                default => __('driver_day.payment.paid'),
            },
        ];
    }

    /** @return array{start: ?string, end: ?string} the slot's window on its day, in Qatar time */
    private function window(DeliverySlot $slot, string $date): array
    {
        $day = CarbonImmutable::parse($date, BusinessTime::zone());

        return ['start' => BusinessTime::iso($day->setTimeFromTimeString($slot->starts_at)), 'end' => BusinessTime::iso($day->setTimeFromTimeString($slot->ends_at))];
    }

    /**
     * A finished job older than a day loses the numbers and the street; the area, the order number, the time, the status and the amount stay.
     *
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    private function redactIfOld(Delivery $d, array $job): array
    {
        $at = $d->delivered_at ?? $d->failed_at ?? $d->returned_at ?? $d->updated_at;
        if ($at === null || $at->greaterThan(now()->subHours(self::HISTORY_DETAIL_HOURS))) {
            return $job;
        }
        $address = array_diff_key((array) ($job['address'] ?? []), array_flip(self::DOOR));

        return array_merge($job, ['customer_phone' => null, 'alternate_phone' => null, 'address' => $address, 'redacted' => true]);
    }

    /**
     * What is in the parcel, with the facts a driver carries it by: name, variant, SKU and the quantity still to deliver (cancelled units and lines
     * the branch removed or swapped out left out), weight per unit and size when the catalogue knows them, oversized and assembly flags.
     * `order_item_id` is the line's id for the adjustments API (same value as `id`); `adjustment` says what the branch did to the line.
     *
     * @param  Collection<int, OrderItem>  $lines
     * @param  array{products: Collection<string, object>, variants: Collection<string, object>}  $catalog
     * @return array{rows: list<array<string, mixed>>, weight: ?float, oversized: bool, assembly: bool}
     */
    private function items(Collection $lines, array $catalog): array
    {
        $total = null;
        $oversized = false;
        $assembly = false;
        $rows = [];
        foreach ($lines as $i) {
            $qty = max(0, (int) $i->quantity - (int) $i->quantity_cancelled);
            if ($qty < 1) {
                continue;
            }
            $product = $i->product_id ? $catalog['products']->get($i->product_id) : null;
            $variant = $i->product_variant_id ? $catalog['variants']->get($i->product_variant_id) : null;
            $kg = $this->kg($variant?->weight ?? $product?->weight, $product?->weight_unit);
            $size = $this->size($variant, $product);
            $big = (bool) ($product?->is_oversized ?? false);
            $assemble = (bool) ($product?->requires_assembly ?? false);
            $oversized = $oversized || $big;
            $assembly = $assembly || $assemble;
            $total = $kg !== null ? ($total ?? 0.0) + $kg * $qty : $total;
            $rows[] = ['id' => $i->id, 'order_item_id' => $i->id, 'name' => $i->name(), 'variant_name' => $i->variantName(), 'sku' => $i->sku, 'quantity' => $qty,
                'adjustment' => $i->adjustment_kind ? ['kind' => $i->adjustment_kind, 'original_quantity' => (int) ($i->original_quantity ?? $i->quantity)] : null,
                'weight_kg' => $kg !== null ? round($kg, 2) : null, 'dimensions_cm' => $size, 'is_oversized' => $big, 'requires_assembly' => $assemble];
        }

        return ['rows' => $rows, 'weight' => $total, 'oversized' => $oversized, 'assembly' => $assembly];
    }

    private function kg(mixed $weight, ?string $unit): ?float
    {
        if (! is_numeric($weight) || (float) $weight <= 0) {
            return null;
        }

        return (float) $weight * (self::KG[strtolower((string) $unit)] ?? 1.0);
    }

    /** @return array{length: float, width: float, height: float}|null centimetres, only when the catalogue knows all three */
    private function size(?object $variant, ?object $product): ?array
    {
        $unit = self::CM[strtolower((string) ($product?->dimension_unit ?? 'cm'))] ?? 1.0;
        $dim = [];
        foreach (['length', 'width', 'height'] as $side) {
            $v = $variant?->{$side} ?? $product?->{$side} ?? null;
            if (! is_numeric($v) || (float) $v <= 0) {
                return null;
            }
            $dim[$side] = round((float) $v * $unit, 1);
        }

        return $dim;
    }

    private function text(mixed $v): ?string
    {
        $t = is_string($v) ? trim($v) : '';

        return $t === '' ? null : $t;
    }

    /**
     * The catalogue facts of every line of every job in one go (two queries, however many jobs).
     *
     * @param  Collection<int, Delivery>  $rows
     * @return array{products: Collection<string, object>, variants: Collection<string, object>}
     */
    private function catalog(Collection $rows): array
    {
        $lines = $rows->flatMap(fn (Delivery $d) => $d->fulfillment->items);
        $productIds = $lines->pluck('product_id')->filter()->unique()->values()->all();
        $variantIds = $lines->pluck('product_variant_id')->filter()->unique()->values()->all();

        return [
            'products' => $productIds === [] ? collect() : DB::table('products')->whereIn('id', $productIds)
                ->get(['id', 'weight', 'weight_unit', 'length', 'width', 'height', 'dimension_unit', 'requires_assembly', 'is_oversized'])->keyBy('id'),
            'variants' => $variantIds === [] ? collect() : DB::table('product_variants')->whereIn('id', $variantIds)->get(['id', 'weight', 'length', 'width', 'height'])->keyBy('id'),
        ];
    }

    /**
     * @param  Collection<int, Delivery>  $rows
     * @return Collection<string, DeliverySlot>
     */
    private function slots(Collection $rows): Collection
    {
        $ids = $rows->map(fn (Delivery $d) => $d->fulfillment->delivery_slot_id)->filter()->unique()->values()->all();

        return $ids === [] ? new Collection : DeliverySlot::query()->whereIn('id', $ids)->get()->keyBy('id');
    }
}
