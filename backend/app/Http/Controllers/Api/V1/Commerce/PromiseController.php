<?php

namespace App\Http\Controllers\Api\V1\Commerce;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\DeliveryZone;
use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Services\BasketPromise;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\UserAddress;
use App\Http\Controllers\Api\V1\ApiController;
use App\Support\BusinessTime;
use App\Support\DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * "When will I get it?" before the customer pays (docs/guides/DELIVERY_PROMISE_ASSEMBLY_AND_SHARING.md). Everything is computed on the server from the real
 * stock, opening hours, holidays and delivery-slot capacity; the client only shows `message` (already in the request's language) and the structured values.
 * It never writes, never reserves and never fails a purchase: no date is an honest "We will confirm your delivery date", not an error.
 *
 * @tags Delivery promise
 */
class PromiseController extends ApiController
{
    /** How long an identical question is answered from memory (seconds): slots and stock move, but not within half a minute. */
    private const CACHE_SECONDS = 30;

    public function __construct(private readonly BasketPromise $promise, private readonly CartService $carts) {}

    /**
     * One product on its page: its chosen variant (or the default one) times `quantity`, from the customer's showroom or the best one.
     * Public and cacheable for a minute (the answer depends only on the query, never on who asks).
     */
    public function product(Request $request, string $id): JsonResponse
    {
        $f = $request->validate([
            'variant_id' => 'nullable|uuid', 'quantity' => 'nullable|integer|min:1|max:'.config('platform.checkout.max_quantity_per_line'),
            'assembly' => 'nullable|boolean', ...$this->whereRules(),
        ]);
        $product = Product::active()->with(['variants' => fn ($q) => $q->where('status', 'active')])->findOrFail($id);
        $variant = isset($f['variant_id']) ? $product->variants->firstWhere('id', $f['variant_id']) : ($product->variants->firstWhere('is_default', true) ?? $product->variants->first());
        if ($variant === null) {
            throw DomainException::make('item_unavailable_simple', 404);
        }
        $variant->setRelation('product', $product);
        $where = $this->where($f, null);
        $data = $this->cached('product', [$variant->id, $f['quantity'] ?? 1, (bool) ($f['assembly'] ?? false), $this->whereKey($f)],
            fn () => $this->promise->forVariant($variant, (int) ($f['quantity'] ?? 1), (bool) ($f['assembly'] ?? false), $where));

        return response()->json(['data' => $data])->header('Cache-Control', 'public, max-age=60')->header('Vary', 'Accept-Language');
    }

    /**
     * The whole basket. A guest (or an app that has not signed in) sends `lines`; a signed-in customer may send them too, or leave them out and get
     * the promise for their own server cart (with its assembly choices, branch and address). A saved address is only used when it is the caller's own.
     */
    public function basket(Request $request): JsonResponse
    {
        $user = $request->user('sanctum');
        $f = $request->validate([
            'lines' => ($user instanceof User ? 'nullable' : 'required').'|array|max:100',
            'lines.*.variant_id' => 'required|uuid', 'lines.*.quantity' => 'required|integer|min:1|max:'.config('platform.checkout.max_quantity_per_line'),
            'lines.*.assembly' => 'nullable|boolean', 'allow_split' => 'nullable|boolean', ...$this->whereRules(),
        ]);

        if (isset($f['lines'])) {
            $lines = array_map(fn (array $l) => ['variant_id' => (string) $l['variant_id'], 'quantity' => (int) $l['quantity'], 'assembly' => (bool) ($l['assembly'] ?? false)], $f['lines']);
            $where = $this->where($f, $user instanceof User ? $user : null);
            // Guests are cached (the answer depends only on what they sent); a signed-in caller's address is theirs alone.
            $data = $user instanceof User ? $this->promise->forLines($lines, $where)
                : $this->cached('basket', [$lines, $this->whereKey($f), $f['allow_split'] ?? true], fn () => $this->promise->forLines($lines, $where));

            return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
        }

        return $this->own($request, $user, $f);
    }

    /** The promise for the signed-in customer's own cart (the same answer as `POST /cart/promise` without lines). */
    public function cart(Request $request): JsonResponse
    {
        $f = $request->validate($this->whereRules() + ['allow_split' => 'nullable|boolean']);

        return $this->own($request, $request->user(), $f);
    }

    /** @param array<string, mixed> $f */
    private function own(Request $request, User $user, array $f): JsonResponse
    {
        $cart = $this->carts->current($user)->load('items');
        $lines = $cart->items->map(fn ($i) => ['variant_id' => $i->product_variant_id, 'quantity' => (int) $i->quantity, 'assembly' => (bool) $i->assembly])->values()->all();
        $where = $this->where(array_filter($f, fn ($v) => $v !== null) + ['fulfillment_type' => $cart->fulfillment_type, 'branch_id' => $cart->branch_id, 'address_id' => $cart->address_id], $user);

        return response()->json(['data' => $this->promise->forLines($lines, $where)])->header('Cache-Control', 'no-store');
    }

    /** @return array<string, string> */
    private function whereRules(): array
    {
        return [
            'fulfillment_type' => 'nullable|in:delivery,pickup', 'branch_id' => 'nullable|uuid', 'address_id' => 'nullable|uuid', 'delivery_zone_id' => 'nullable|uuid',
            'zone_number' => 'nullable|string|max:16', 'latitude' => 'nullable|numeric|between:-90,90|required_with:longitude', 'longitude' => 'nullable|numeric|between:-180,180|required_with:latitude',
        ];
    }

    /**
     * Where the customer is, as the promise reads it. Never trusted beyond finding a zone and a branch: a branch id that is not an active branch is
     * ignored, and a saved address counts only when it belongs to the signed-in caller.
     *
     * @param  array<string, mixed>  $f
     * @return array{type: string, branch_id: ?string, zone: ?DeliveryZone, latitude: ?float, longitude: ?float, allow_split: bool}
     */
    private function where(array $f, ?User $user): array
    {
        $address = $user !== null && ! empty($f['address_id']) ? UserAddress::where('user_id', $user->id)->find($f['address_id']) : null;
        $lat = isset($f['latitude']) ? (float) $f['latitude'] : $address?->latitude;
        $lng = isset($f['longitude']) ? (float) $f['longitude'] : $address?->longitude;
        $zone = $this->promise->zone($address?->delivery_zone_id ?? ($f['delivery_zone_id'] ?? null), $address?->zone_number ?? ($f['zone_number'] ?? null), $lat, $lng);
        $branchId = ! empty($f['branch_id']) && Branch::active()->whereKey($f['branch_id'])->exists() ? $f['branch_id'] : null;

        return ['type' => ($f['fulfillment_type'] ?? 'delivery') === 'pickup' ? 'pickup' : 'delivery', 'branch_id' => $branchId, 'zone' => $zone,
            'latitude' => $lat, 'longitude' => $lng, 'allow_split' => (bool) ($f['allow_split'] ?? true)];
    }

    /** @param array<string, mixed> $f */
    private function whereKey(array $f): array
    {
        return [$f['fulfillment_type'] ?? 'delivery', $f['branch_id'] ?? null, $f['delivery_zone_id'] ?? null, $f['zone_number'] ?? null,
            isset($f['latitude']) ? round((float) $f['latitude'], 3) : null, isset($f['longitude']) ? round((float) $f['longitude'], 3) : null];
    }

    /**
     * The same question within half a minute is answered from memory. The key holds the language and the current Qatar minute, so a change of day,
     * hour or language is never served stale.
     *
     * @param  list<mixed>  $key
     * @param  callable(): array<string, mixed>  $compute
     * @return array<string, mixed>
     */
    private function cached(string $kind, array $key, callable $compute): array
    {
        $stamp = BusinessTime::now()->format('YmdHi');

        return Cache::remember('promise:'.$kind.':'.sha1(json_encode([$key, app()->getLocale(), $stamp])), self::CACHE_SECONDS, $compute);
    }
}
