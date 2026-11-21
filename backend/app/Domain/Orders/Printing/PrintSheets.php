<?php

namespace App\Domain\Orders\Printing;

use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Services\DriverJobPresenter;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Inventory\Models\BranchInventory;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\OrderNote;
use App\Domain\Orders\Models\SubstitutionRequest;
use App\Domain\Payments\Services\CodSplit;
use App\Support\BusinessTime;
use App\Support\Code128;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * What one printed page of a picking list or a delivery note says, as plain data for the Blade views (documents/picking-list, documents/delivery-note).
 * One sheet per part. Everything a batch needs is loaded in a fixed number of queries (the lines' catalogue facts, the bins, the other parts of the
 * orders, the open replacement questions, the notes), however many parts there are.
 *
 * The customer's language leads (`lead`); the labels of the page always carry both languages (see {@see PrintText}).
 */
final class PrintSheets
{
    /** The longest staff note list kept on a picking list (the newest ones). */
    private const MAX_NOTES = 4;

    public function __construct(
        private readonly DriverJobPresenter $facts,
        private readonly PrintSettings $settings,
        private readonly AccessControl $acl,
    ) {}

    /**
     * @param  Collection<int, OrderFulfillment>  $parts  with order, branch, items, slot and deliveries loaded
     * @param  array<string, int>  $copies  part id => the copy number of this print
     * @return list<array<string, mixed>>
     */
    public function build(PrintKind $kind, Collection $parts, array $copies, ?User $printedBy): array
    {
        if ($parts->isEmpty()) {
            return [];
        }
        $world = $this->world($kind, $parts) + ['viewer' => $printedBy];
        $printedAt = BusinessTime::now();

        return $parts->map(fn (OrderFulfillment $part) => $this->sheet($kind, $part, $world, [
            'copy' => $copies[$part->id] ?? 1,
            'printed_at' => $printedAt->format('d M Y, H:i'),
            'printed_by' => $printedBy?->name,
        ]))->values()->all();
    }

    /**
     * Everything the sheets share, loaded once for the whole batch.
     *
     * @param  Collection<int, OrderFulfillment>  $parts
     * @return array<string, mixed>
     */
    private function world(PrintKind $kind, Collection $parts): array
    {
        $orderIds = $parts->pluck('order_id')->unique()->values()->all();
        $variantIds = $parts->flatMap(fn (OrderFulfillment $p) => $p->items->pluck('product_variant_id'))->filter()->unique()->values()->all();
        $world = [
            'facts' => $this->facts->parcelFacts($parts),
            'siblings' => OrderFulfillment::query()->whereIn('order_id', $orderIds)->with('branch:id,code,name_en,name_ar')->orderBy('number')->orderBy('id')->get()->groupBy('order_id'),
            'support_phone' => $this->settings->supportPhone(),
        ];
        if ($kind === PrintKind::Picking) {
            $world['bins'] = $variantIds === [] ? collect() : BranchInventory::query()->whereIn('branch_id', $parts->pluck('branch_id')->unique()->values()->all())
                ->whereIn('product_variant_id', $variantIds)->get(['branch_id', 'product_variant_id', 'bin_location'])
                ->mapWithKeys(fn (BranchInventory $r) => [$r->branch_id.'|'.$r->product_variant_id => BinOrder::bin($r->bin_location)]);
            $world['waiting'] = SubstitutionRequest::query()->whereIn('order_id', $orderIds)->where('status', SubstitutionRequest::WAITING)
                ->with('original:id,name_en,name_ar')->orderBy('deadline_at')->orderBy('id')->get()->keyBy('replacement_order_item_id');
            $partIds = $parts->pluck('id')->all();
            $world['notes'] = OrderNote::query()->whereIn('order_id', $orderIds)->where(fn ($q) => $q->whereNull('order_fulfillment_id')->orWhereIn('order_fulfillment_id', $partIds))
                ->orderBy('created_at')->orderBy('id')->get(['order_id', 'order_fulfillment_id', 'body'])->groupBy('order_id');
        }

        return $world;
    }

    /**
     * @param  array<string, mixed>  $world
     * @param  array<string, mixed>  $print  copy, printed_at, printed_by
     * @return array<string, mixed>
     */
    private function sheet(PrintKind $kind, OrderFulfillment $part, array $world, array $print): array
    {
        $order = $part->order;
        $siblings = $world['siblings']->get($order->id, collect());
        $facts = $world['facts'][$part->id];
        $delivery = $part->activeDelivery();
        $branch = $part->branch;
        $number = (string) $part->number;
        $index = max(1, 1 + (int) $siblings->search(fn (OrderFulfillment $s) => $s->id === $part->id));

        $sheet = [
            'kind' => $kind->value,
            'lead' => $order->locale === 'ar' ? 'ar' : 'en',
            'paper' => $this->settings->paper($kind),
            'number' => $number,
            'barcode' => Code128::supports($number) ? 'data:image/svg+xml;base64,'.base64_encode(Code128::svg($number, 40)) : null,
            'order_number' => (string) $order->number,
            'placed_at' => BusinessTime::local($order->placed_at)?->format('d M Y, H:i'),
            'type' => $part->type,
            'status' => $part->status->value,
            'part_index' => $index,
            'part_count' => max(1, $siblings->count()),
            // The other parts are named only to a viewer who may see their branch (a branch never learns about another branch's parcel from its own paper).
            'other_parts' => $siblings->reject(fn (OrderFulfillment $s) => $s->id === $part->id || ! $this->acl->allows($world['viewer'], 'orders.view', $s->branch_id))
                ->map(fn (OrderFulfillment $s) => ['number' => $s->number, 'branch_en' => $s->branch->name_en, 'branch_ar' => $s->branch->name_ar, 'type' => $s->type])->values()->all(),
            'branch' => ['code' => $branch->code, 'name_en' => $branch->name_en, 'name_ar' => $branch->name_ar],
            'when' => $this->when($part, $delivery),
            'assembly' => $facts['assembly'],
            'two_people' => $facts['needs_two_people'],
            'weight_kg' => $facts['weight'] !== null ? round($facts['weight'], 1) : null,
            'copy' => $print['copy'],
            'printed_at' => $print['printed_at'],
            'printed_by' => $print['printed_by'],
        ];

        return $kind === PrintKind::Picking
            ? $sheet + $this->pickingLines($part, $facts, $world)
            : $sheet + $this->noteContent($part, $order, $delivery, $facts, $world);
    }

    /**
     * The picking list's own content: the lines in walking order, what the customer and the branch wrote, and the open replacement questions.
     *
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $world
     * @return array<string, mixed>
     */
    private function pickingLines(OrderFulfillment $part, array $facts, array $world): array
    {
        $items = $part->items->keyBy('id');
        $lines = [];
        foreach ($facts['rows'] as $row) {
            /** @var OrderItem $item */
            $item = $items[$row['order_item_id']];
            $wait = $world['waiting']->get($item->id);
            $replaces = $item->replaces_order_item_id ? $items->get($item->replaces_order_item_id) : null;
            $lines[] = [
                'id' => $item->id, 'sku' => $item->sku, 'name' => $item->name_en,
                'name_en' => $item->name_en, 'name_ar' => $item->name_ar, 'variant_en' => $item->variant_name_en, 'variant_ar' => $item->variant_name_ar,
                'qty' => $row['quantity'],
                'bin' => $item->product_variant_id ? ($world['bins'][$part->branch_id.'|'.$item->product_variant_id] ?? null) : null,
                'assembly' => (bool) $row['requires_assembly'], 'oversized' => (bool) $row['is_oversized'],
                'reduced_from' => ($row['adjustment']['kind'] ?? null) === 'reduced' && $row['adjustment']['original_quantity'] > $row['quantity'] ? $row['adjustment']['original_quantity'] : null,
                'replaces_en' => $replaces?->name_en, 'replaces_ar' => $replaces?->name_ar,
                'waiting_until' => $wait?->deadline_at ? BusinessTime::local($wait->deadline_at)?->format('H:i') : null,
                'waiting' => $wait !== null,
            ];
        }
        $lines = BinOrder::sort($lines);
        $staff = ($world['notes']->get($part->order_id) ?? collect())->filter(fn (OrderNote $n) => $n->order_fulfillment_id === null || $n->order_fulfillment_id === $part->id)
            ->pluck('body')->map(fn ($b) => trim((string) $b))->filter()->take(-self::MAX_NOTES)->values()->all();

        return [
            'lines' => $lines,
            'units' => array_sum(array_column($lines, 'qty')),
            'missing_bins' => count(array_filter($lines, fn (array $l) => $l['bin'] === null)),
            'waiting_customer' => collect($lines)->contains(fn (array $l) => $l['waiting']),
            'customer_notes' => $this->text($part->order->customer_notes),
            'staff_notes' => $staff,
        ];
    }

    /**
     * The delivery note's own content: who receives it and where, the lines, and what the driver collects.
     *
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $world
     * @return array<string, mixed>
     */
    private function noteContent(OrderFulfillment $part, Order $order, ?Delivery $delivery, array $facts, array $world): array
    {
        $a = (array) ($order->address_snapshot ?? []);
        $lead = $order->locale === 'ar' ? 'ar' : 'en';
        $recipientName = $this->text($a['recipient_name'] ?? null) ?? $order->customer_name;
        $recipientPhone = $this->text($a['recipient_phone'] ?? null) ?? $order->customer_phone;
        $pay = $this->payment($part, $order, $delivery);
        $showPrices = $pay['mode'] === 'cod' || $this->settings->notePricesAlways();
        $items = $part->items->keyBy('id');
        $lines = [];
        foreach ($facts['rows'] as $row) {
            /** @var OrderItem $item */
            $item = $items[$row['order_item_id']];
            $lines[] = [
                'sku' => $item->sku, 'name_en' => $item->name_en, 'name_ar' => $item->name_ar, 'variant_en' => $item->variant_name_en, 'variant_ar' => $item->variant_name_ar,
                'qty' => $row['quantity'], 'assembly' => (bool) $row['assembly_ordered'],
                'unit' => $showPrices ? Money::of((string) $item->unit_final_price)->toDecimal() : null,
                'total' => $showPrices ? Money::of((string) $item->line_total)->toDecimal() : null,
                'assembly_total' => $showPrices && Money::of((string) $item->assembly_total)->isPositive() ? Money::of((string) $item->assembly_total)->toDecimal() : null,
            ];
        }
        $lat = $a['latitude'] ?? null;
        $lng = $a['longitude'] ?? null;
        $branchPhone = trim((string) $part->branch->phone);

        return [
            'recipient' => ['name' => $recipientName, 'phone' => $recipientPhone, 'ordered_by' => $recipientName !== $order->customer_name ? $order->customer_name : null],
            'address' => $this->addressLine($a, $lead),
            'landmark' => $this->text($a['landmark'] ?? null),
            'instructions' => $this->text($a['instructions'] ?? null),
            'customer_notes' => $this->text($order->customer_notes),
            'map_url' => is_numeric($lat) && is_numeric($lng) ? 'https://www.google.com/maps/search/?api=1&query='.round((float) $lat, 6).','.round((float) $lng, 6) : null,
            'lines' => $lines,
            'units' => array_sum(array_column($lines, 'qty')),
            'show_prices' => $showPrices,
            'pay' => $pay,
            'help_phone' => $branchPhone !== '' ? $branchPhone : $world['support_phone'],
            'help_branch_en' => $part->branch->name_en, 'help_branch_ar' => $part->branch->name_ar,
        ];
    }

    /**
     * What the driver collects at this door. Cash on delivery: the part's own share of what is still due (the delivery's `cod_amount`, kept up to date
     * by CodSplit, or worked out the same way when there is no delivery yet). Anything else is paid: a card order that is not confirmed yet says so.
     *
     * @return array{mode: string, collect: string, order_total: string, paid: string, parcels: int, share: bool}
     */
    private function payment(OrderFulfillment $part, Order $order, ?Delivery $delivery): array
    {
        $currency = (string) ($order->currency ?: 'QAR');
        $cod = $order->payment_method === 'cod';
        $due = Money::zero($currency);
        if ($cod) {
            $due = $delivery !== null && ! in_array($delivery->status->value, ['cancelled', 'returned'], true)
                ? Money::of((string) $delivery->cod_amount, $currency) : CodSplit::expectedFor($order, $part);
        }
        $parcels = OrderFulfillment::query()->where('order_id', $order->id)->where('type', 'delivery')->count();
        $mode = match (true) {
            $cod && $due->isPositive() => 'cod',
            $cod => 'settled',
            in_array($order->payment_status->value, ['paid', 'partially_refunded', 'refunded'], true) => 'paid',
            default => 'unpaid',
        };

        return ['mode' => $mode, 'collect' => $due->toDecimal(), 'order_total' => Money::of((string) $order->grand_total, $currency)->toDecimal(),
            'paid' => Money::of((string) $order->paid_total, $currency)->toDecimal(), 'parcels' => $parcels, 'share' => $cod && $parcels > 1];
    }

    /**
     * The slot the customer chose, else the time of the delivery.
     *
     * @return array{date: ?string, from: ?string, to: ?string}|null
     */
    private function when(OrderFulfillment $part, ?Delivery $delivery): ?array
    {
        $slot = $part->slot;
        if ($slot !== null && $part->slot_date !== null) {
            return ['date' => $part->slot_date->format('D d M Y'), 'from' => substr((string) $slot->starts_at, 0, 5), 'to' => substr((string) $slot->ends_at, 0, 5)];
        }
        $at = BusinessTime::local($delivery?->scheduled_for);
        if ($at !== null) {
            return ['date' => $at->format('D d M Y'), 'from' => $at->format('H:i'), 'to' => null];
        }

        return $part->slot_date !== null ? ['date' => $part->slot_date->format('D d M Y'), 'from' => null, 'to' => null] : null;
    }

    /**
     * The address as one line in the customer's language: zone, street, building, unit, then the area and city as the customer wrote them.
     *
     * @param  array<string, mixed>  $a
     */
    private function addressLine(array $a, string $lead): string
    {
        $word = fn (string $key, mixed $v) => $this->text($v) === null ? null : __('print_docs.doc.addr_'.$key, [], $lead).' '.$this->text($v);

        return collect([$word('zone', $a['zone_number'] ?? null), $word('street', $a['street_number'] ?? null), $word('building', $a['building_number'] ?? null),
            $word('unit', $a['unit_number'] ?? null), $this->text($a['area'] ?? null), $this->text($a['city'] ?? null)])->filter()->implode($lead === 'ar' ? '، ' : ', ');
    }

    private function text(mixed $value): ?string
    {
        $t = is_string($value) ? trim($value) : '';

        return $t === '' ? null : $t;
    }
}
