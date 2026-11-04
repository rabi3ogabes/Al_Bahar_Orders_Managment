<?php

namespace App\Http\Controllers\Api\V1\Commerce;

use App\Domain\Checkout\Services\CartService;
use App\Domain\Fulfillment\Services\PickupService;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\ReturnRequest;
use App\Domain\Orders\Services\InvoiceService;
use App\Domain\Orders\Services\OrderPresenter;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Services\PaymentService;
use App\Http\Controllers\Api\V1\ApiController;
use App\Support\BusinessTime;
use App\Support\DocumentNumber;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** @tags Orders */
class OrderController extends ApiController
{
    public function __construct(private readonly OrderPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $page = Order::where('user_id', $this->user()->id)->with('items')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('placed_at')->paginate($this->perPage($request));

        return response()->json(['data' => $page->getCollection()->map(fn ($o) => $this->presenter->summary($o)),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->presenter->detail($this->own($id))]);
    }

    public function timeline(string $id): JsonResponse
    {
        return response()->json(['data' => $this->presenter->timeline($this->own($id))]);
    }

    public function cancel(Request $request, string $id, OrderService $orders): JsonResponse
    {
        $data = $request->validate(['reason_code' => 'required|in:changed_mind,ordered_by_mistake,found_better_price,delivery_too_long,payment_issue,other', 'reason' => 'nullable|string|max:500']);
        $result = $orders->requestCancellation($this->own($id), $this->user(), $data['reason_code'], $data['reason'] ?? null);

        return response()->json(['data' => ['status' => $result['status'], 'order' => $this->presenter->detail($this->own($id))]]);
    }

    public function reorder(string $id, CartService $carts): JsonResponse
    {
        $order = $this->own($id)->load('items.variant.product');
        $skipped = [];
        foreach ($order->items as $item) {
            try {
                if (! $item->variant) {
                    throw new \App\Support\DomainException('item_unavailable_simple');
                }
                $carts->add($this->user(), $item->product_variant_id, $item->quantity);
            } catch (\App\Support\DomainException) {
                $skipped[] = ['name' => $item->name(), 'sku' => $item->sku];
            }
        }

        return response()->json(['data' => ['cart' => $carts->present($carts->current($this->user())), 'skipped' => $skipped]]);
    }

    public function invoice(string $id, InvoiceService $invoices)
    {
        $order = $this->own($id);
        abort_if($order->status->value === 'pending_payment', 409, __('checkout.invoice_unavailable'));

        return $invoices->download($order);
    }

    public function pay(Request $request, string $id, PaymentService $payments): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(preg_match('/^[A-Za-z0-9\-_]{16,128}$/', $key), 400, __('checkout.idempotency_required'));
        $payment = $payments->retry($this->own($id), $key);

        return response()->json(['data' => $payments->action($payment)], 201);
    }

    public function payment(string $id): JsonResponse
    {
        $p = Payment::where('user_id', $this->user()->id)->findOrFail($id);

        return response()->json(['data' => ['id' => $p->id, 'order_id' => $p->order_id, 'status' => $p->status->value, 'method' => $p->method,
            'amount' => Money::of($p->amount)->jsonSerialize(), 'paid_at' => BusinessTime::iso($p->paid_at), 'failure_reason' => $p->status->value === 'failed' ? __('checkout.payment_failed') : null]]);
    }

    public function pickupPass(string $id, string $fulfillmentId, PickupService $pickup): JsonResponse
    {
        $order = $this->own($id);
        $f = $order->fulfillments()->with(['pickup', 'branch'])->findOrFail($fulfillmentId);
        $pass = $f->pickup ? $pickup->pass($f->pickup) : null;
        abort_unless($pass, 409, __('checkout.pickup_not_ready'));

        return response()->json(['data' => $pass + ['branch' => ['id' => $f->branch->id, 'name' => $f->branch->localized('name')], 'fulfillment_number' => $f->number]]);
    }

    public function requestReturn(Request $request, string $id): JsonResponse
    {
        $order = $this->own($id)->load('items.fulfillment');
        $data = $request->validate(['items' => 'required|array|min:1', 'items.*.order_item_id' => 'required|uuid', 'items.*.quantity' => 'required|integer|min:1',
            'reason_code' => 'required|in:damaged,wrong_item,not_as_described,changed_mind,other', 'reason' => 'nullable|string|max:1000']);
        $branchId = null;
        foreach ($data['items'] as $line) {
            $item = $order->items->firstWhere('id', $line['order_item_id']);
            abort_unless($item && in_array($item->fulfillment->status->value, ['delivered', 'picked_up'], true)
                && $line['quantity'] <= $item->quantity_handed_over - $item->quantity_returned, 422, __('checkout.return_not_allowed'));
            $branchId ??= $item->branch_id;
        }
        $rr = ReturnRequest::create(['number' => DocumentNumber::make('RT'), 'order_id' => $order->id, 'user_id' => $this->user()->id, 'branch_id' => $branchId,
            'reason_code' => $data['reason_code'], 'reason' => $data['reason'] ?? null, 'items' => $data['items']]);

        return response()->json(['data' => ['id' => $rr->id, 'number' => $rr->number, 'status' => $rr->status]], 201);
    }

    private function own(string $id): Order
    {
        return Order::where('user_id', $this->user()->id)->findOrFail($id);
    }
}
