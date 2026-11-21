<?php

namespace App\Http\Controllers\Api\V1\Commerce;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Services\UpsellService;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Checkout\Services\CheckoutService;
use App\Domain\Checkout\Services\QuoteService;
use App\Domain\Orders\Services\OrderPresenter;
use App\Domain\Payments\Services\PaymentService;
use App\Http\Controllers\Api\V1\ApiController;
use App\Support\AlternatePhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** @tags Cart & Checkout */
class CheckoutController extends ApiController
{
    public function __construct(private readonly CartService $carts) {}

    public function cart(): JsonResponse
    {
        return response()->json(['data' => $this->carts->present($this->carts->current($this->user()))]);
    }

    /** "You may also need": the admin-chosen upsells of what is in the cart (never what is already in it), max 8. */
    public function suggestions(UpsellService $upsells): JsonResponse
    {
        $cart = $this->carts->current($this->user());
        $branch = $cart->branch_id ? Branch::active()->find($cart->branch_id) : null;

        return response()->json(['data' => $upsells->forCart($cart, $branch)]);
    }

    public function addItem(Request $request): JsonResponse
    {
        $data = $request->validate(['variant_id' => 'required|uuid|exists:product_variants,id', 'quantity' => 'required|integer|min:1|max:'.config('platform.checkout.max_quantity_per_line'),
            'assembly' => 'sometimes|boolean']); // assembly and installation for the line (only products that offer it)

        return response()->json(['data' => $this->carts->present($this->carts->add($this->user(), $data['variant_id'], (int) $data['quantity'], array_key_exists('assembly', $data) ? (bool) $data['assembly'] : null))], 201);
    }

    public function updateItem(Request $request, string $id): JsonResponse
    {
        // A line changes its quantity, its assembly choice, or both in one call (at least one of the two).
        $data = $request->validate(['quantity' => 'required_without:assembly|integer|min:0|max:'.config('platform.checkout.max_quantity_per_line'), 'assembly' => 'required_without:quantity|boolean']);
        $cart = null;
        if (array_key_exists('assembly', $data) && (int) ($data['quantity'] ?? 1) > 0) {
            $cart = $this->carts->setAssembly($this->user(), $id, (bool) $data['assembly']);
        }
        if (array_key_exists('quantity', $data)) {
            $cart = $this->carts->update($this->user(), $id, (int) $data['quantity']);
        }

        return response()->json(['data' => $this->carts->present($cart)]);
    }

    public function removeItem(string $id): JsonResponse
    {
        return response()->json(['data' => $this->carts->present($this->carts->remove($this->user(), $id))]);
    }

    public function updateCart(Request $request): JsonResponse
    {
        $data = $request->validate(['branch_id' => 'nullable|uuid', 'fulfillment_type' => 'sometimes|in:delivery,pickup', 'address_id' => 'nullable|uuid',
            'coupon_code' => 'nullable|string|max:48', 'loyalty_points' => 'sometimes|integer|min:0']);

        return response()->json(['data' => $this->carts->present($this->carts->setOptions($this->user(), $data))]);
    }

    public function merge(Request $request): JsonResponse
    {
        $request->validate(['guest_token' => 'required|string|max:200']);

        return response()->json(['data' => $this->carts->present($this->carts->mergeGuest($this->user(), $request->input('guest_token')))]);
    }

    public function quote(Request $request, QuoteService $quotes): JsonResponse
    {
        $options = $this->options($request);
        $quote = $quotes->quote($this->user(), $this->carts->current($this->user()), $options);

        return response()->json(['data' => $quote->toArray()]);
    }

    /** Place the order. Requires the Idempotency-Key header; retries with the same key return the original order. */
    public function place(Request $request, CheckoutService $checkout, PaymentService $payments, OrderPresenter $presenter): JsonResponse
    {
        $key = $this->idempotencyKey($request);
        $options = $this->options($request);
        $request->validate(['quote_hash' => 'required|string|size:64', 'customer_notes' => 'nullable|string|max:1000', 'payment_method' => 'required|in:sadad,cod,pay_at_pickup']);
        $this->carts->current($this->user()); // ensure the cart exists
        $result = $checkout->place($this->user(), $options, $request->input('quote_hash'), $key, $request->input('customer_notes'));

        return response()->json(['data' => [
            'order' => $presenter->detail($result['order']->fresh()),
            'payment' => $payments->action($result['payment']->fresh()),
            'replayed' => $result['replayed'],
        ]], $result['replayed'] ? 200 : 201);
    }

    private function options(Request $request): array
    {
        return $request->validate([
            'fulfillment_type' => 'sometimes|in:delivery,pickup', 'address_id' => 'nullable|uuid', 'branch_id' => 'nullable|uuid',
            'pickup_slot_id' => 'nullable|uuid', 'pickup_date' => 'nullable|date_format:Y-m-d', 'delivery_slot_id' => 'nullable|uuid',
            'delivery_date' => 'nullable|date_format:Y-m-d', 'payment_method' => 'nullable|in:sadad,cod,pay_at_pickup', 'coupon_code' => 'nullable|string|max:48',
            'loyalty_points' => 'nullable|integer|min:0', 'allow_split' => 'nullable|boolean', 'express' => 'nullable|boolean',
            // The customer's second number for the driver (delivery only). Checked and normalised by QuoteService, which answers in plain words.
            // With the dashboard switch off the two fields are not even read: an older app that still sends them is ignored, never rejected.
            ...(AlternatePhone::enabled() ? ['alternate_phone' => 'nullable|string|max:32', 'no_alternate_phone' => 'nullable|boolean'] : []),
            'latitude' => 'nullable|numeric|between:-90,90|required_with:longitude', 'longitude' => 'nullable|numeric|between:-180,180|required_with:latitude',
        ]);
    }
}
