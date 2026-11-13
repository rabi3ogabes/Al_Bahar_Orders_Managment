<?php

namespace App\Http\Controllers\Api\V1\Commerce;

use App\Domain\Checkout\Services\CartService;
use App\Domain\Fulfillment\Services\PickupService;
use App\Domain\Fulfillment\Services\TrackingService;
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
use App\Support\DomainException;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** @tags Orders */
class OrderController extends ApiController
{
    public function __construct(private readonly OrderPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $page = Order::where('user_id', $this->user()->id)->with('items')
            // ?status=a,b (or status[]=a&status[]=b) filters by any of the given statuses.
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', array_values(array_filter(array_map('trim', is_array($s) ? $s : explode(',', (string) $s))))))
            ->orderByDesc('placed_at')->paginate($this->perPage($request));

        return response()->json(['data' => $page->getCollection()->map(fn ($o) => $this->presenter->summary($o)),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->presenter->detail($this->own($id))]);
    }

    /**
     * Live tracking for the customer's own order: while it is on the road (and for a few minutes after delivery) the driver's
     * first name, vehicle and last position with its age, plus the destination, the branch and an arrival estimate. Realtime
     * moves arrive on private-orders.{id} (driver.moved); this is the first paint and the fallback poll.
     */
    public function tracking(string $id, TrackingService $tracking): JsonResponse
    {
        return response()->json(['data' => $tracking->forOrder($this->own($id))]);
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
                    throw new DomainException('item_unavailable_simple');
                }
                $carts->add($this->user(), $item->product_variant_id, $item->quantity);
            } catch (DomainException) {
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
        $key = $this->idempotencyKey($request);
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
        abort_if($pass === null, 409, __('checkout.pickup_not_ready'));

        return response()->json(['data' => $pass + ['branch' => ['id' => $f->branch->id, 'name' => $f->branch->localized('name')], 'fulfillment_number' => $f->number]]);
    }

    public function requestReturn(Request $request, string $id): JsonResponse
    {
        $order = $this->own($id);
        $data = $request->validate(['items' => 'required|array|min:1|max:50', 'items.*.order_item_id' => 'required|uuid|distinct', 'items.*.quantity' => 'required|integer|min:1|max:100000',
            'reason_code' => 'required|in:damaged,wrong_item,not_as_described,changed_mind,other', 'reason' => 'nullable|string|max:1000']);

        // Under the order's row lock, counting what earlier open requests already claim: a double submit or a second
        // request can never ask to return more than was handed over.
        $rr = DB::transaction(function () use ($order, $data) {
            Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $order->load('items.fulfillment');
            $claimed = [];
            foreach (ReturnRequest::where('order_id', $order->id)->whereIn('status', ['requested', 'approved'])->get() as $open) {
                foreach ((array) $open->items as $line) {
                    $claimed[$line['order_item_id'] ?? ''] = ($claimed[$line['order_item_id'] ?? ''] ?? 0) + (int) ($line['quantity'] ?? 0);
                }
            }
            $branchId = null;
            foreach ($data['items'] as $line) {
                $item = $order->items->firstWhere('id', $line['order_item_id']);
                abort_unless($item && OrderPresenter::returnWindowOpen($item->fulfillment)
                    && $line['quantity'] <= $item->quantity_handed_over - $item->quantity_returned - ($claimed[$item->id] ?? 0), 422, __('checkout.return_not_allowed'));
                $branchId ??= $item->branch_id;
            }

            return ReturnRequest::create(['number' => DocumentNumber::make('RT'), 'order_id' => $order->id, 'user_id' => $this->user()->id, 'branch_id' => $branchId,
                'reason_code' => $data['reason_code'], 'reason' => $data['reason'] ?? null, 'items' => $data['items']]);
        });

        return response()->json(['data' => ['id' => $rr->id, 'number' => $rr->number, 'status' => $rr->status]], 201);
    }

    private function own(string $id): Order
    {
        return Order::where('user_id', $this->user()->id)->findOrFail($id);
    }
}
