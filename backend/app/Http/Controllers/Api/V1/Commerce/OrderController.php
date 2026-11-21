<?php

namespace App\Http\Controllers\Api\V1\Commerce;

use App\Domain\Checkout\Services\CartService;
use App\Domain\Fulfillment\Services\PickupService;
use App\Domain\Fulfillment\Services\TrackingService;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\ReturnRequest;
use App\Domain\Orders\Services\InvoiceService;
use App\Domain\Orders\Services\OrderPresenter;
use App\Domain\Orders\Services\OrderProgress;
use App\Domain\Orders\Services\OrderService;
use App\Domain\Orders\Services\ReturnClaimPresenter;
use App\Domain\Orders\Services\ReturnClaims;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Services\PaymentService;
use App\Http\Controllers\Api\V1\ApiController;
use App\Support\BusinessTime;
use App\Support\DomainException;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
     * The live order card for each of the caller's own orders that is in progress or finished in the last 30 minutes
     * (newest first, at most 10): the same object the "order_progress" push carries, in the caller's language. The app
     * calls it on start and resume to rebuild the notifications it may have missed.
     */
    public function activeProgress(OrderProgress $progress): JsonResponse
    {
        return response()->json(['data' => $progress->activeFor($this->user()->id, app()->getLocale())]);
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
        // Lines the branch removed or swapped out (quantity 0) are not part of what the customer received: a replacement is, and is added.
        foreach ($order->items->where('quantity', '>', 0) as $item) {
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

    /**
     * Send a return or damage claim (JSON, or multipart with `photos[]` up to five). A damaged or wrong item needs at least one photo. The same
     * `Idempotency-Key` (or an identical claim a moment later) answers with the claim already made (200) instead of a second one.
     */
    public function requestReturn(Request $request, ReturnClaims $claims, ReturnClaimPresenter $presenter, string $id): JsonResponse
    {
        $order = $this->own($id);
        abort_unless(OrderPresenter::returnsEnabled(), 403, __('checkout.returns_disabled'));
        $data = $request->validate(['items' => 'required|array|min:1|max:50', 'items.*.order_item_id' => 'required|uuid|distinct', 'items.*.quantity' => 'required|integer|min:1|max:100000',
            'reason_code' => ['required', Rule::in([...ReturnRequest::TYPES, ...ReturnRequest::LEGACY_TYPES])], 'reason' => 'nullable|string|max:500',
            'photos' => 'nullable|array|max:'.ReturnRequest::MAX_PHOTOS, 'photos.*' => 'file|mimetypes:image/jpeg,image/png,image/webp|max:8192']);

        $result = $claims->submit($order, $this->user(), $data, array_values($request->file('photos', [])), $request->header('Idempotency-Key'));

        return response()->json(['data' => $presenter->detail($result['claim']->fresh())], $result['replayed'] ? 200 : 201);
    }

    private function own(string $id): Order
    {
        abort_unless(Str::isUuid($id), 404); // a malformed id is "not found", not a database error

        return Order::where('user_id', $this->user()->id)->findOrFail($id);
    }
}
