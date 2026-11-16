<?php

namespace App\Livewire\Admin\Sales;

use App\Domain\Checkout\Services\AbandonedCartService;
use App\Domain\Orders\Models\Cart;
use App\Domain\Orders\Models\CartItem;
use App\Support\BusinessTime;
use App\Support\DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * The "Cart of <customer>" side window of the abandoned orders page: lines, add item, coupon, totals and the actions.
 *
 * Nothing here decides anything: every action goes to AbandonedCartService, which re-checks permission, branch scope, that the cart is still
 * abandoned and (with the stamp of what this window last showed) that nobody changed it meanwhile. A refusal becomes a plain toast and the
 * window shows the cart as it is now.
 */
trait AbandonedCartActions
{
    /** The cart in the window; mirrored in `?cart=` so reload, sharing and Back work. Only the server sets it (after the visibility check). */
    #[Locked]
    #[Url(as: 'cart', except: '')]
    public string $cartId = '';

    public bool $showCart = false;

    /** Fingerprint of the cart as this window last showed it (set on every render); an edit is refused when the cart no longer matches it. */
    #[Locked]
    public string $stamp = '';

    public string $couponInput = '';

    public bool $addOpen = false;

    public string $addSearch = '';

    private function svc(): AbandonedCartService
    {
        return app(AbandonedCartService::class);
    }

    /** A page opened on `?cart=<id>` starts with the window open, when the viewer may see that cart. */
    public function mountAbandonedCartActions(): void
    {
        if ($this->cartId === '') {
            return;
        }
        $this->showCart = $this->cartViewable($this->cartId);
        if (! $this->showCart) {
            $this->cartId = '';
        }
    }

    private function cartViewable(string $id): bool
    {
        try {
            $this->svc()->find(auth()->user(), $id);

            return true;
        } catch (DomainException|AuthorizationException) {
            return false;
        }
    }

    public function openCart(string $id): void
    {
        if (! $this->cartViewable($id)) {
            $this->toast(__('abandoned_admin.err_cart_unavailable'), 'error');

            return;
        }
        $this->resetWindowInput();
        $this->cartId = $id;
        $this->showCart = true;
    }

    public function closeCart(): void
    {
        $this->showCart = false;
        $this->cartId = '';
        $this->resetWindowInput();
    }

    /** ✕, Esc, scrim and Back of the window all end here (wire:model.live): drop the id so the address bar is clean again. */
    public function updatedShowCart(bool $open): void
    {
        if (! $open) {
            $this->closeCart();
        }
    }

    private function resetWindowInput(): void
    {
        $this->reset('couponInput', 'addOpen', 'addSearch');
        $this->resetErrorBag();
    }

    public function toggleAdd(): void
    {
        $this->addOpen = ! $this->addOpen;
        $this->addSearch = '';
    }

    // ── Lines ────────────────────────────────────────────────────────────────

    public function bump(string $itemId, int $delta): void
    {
        $item = Str::isUuid($itemId) ? CartItem::where('cart_id', $this->cartId)->find($itemId) : null;
        if (! $item) {
            $this->toast(__('abandoned_admin.err_cart_changed'), 'warning');

            return;
        }
        $this->setQuantity($itemId, $item->quantity + $delta);
    }

    /** A typed quantity: at least 1 (removing a line is its own button, with Undo). */
    public function setQuantity(string $itemId, int $quantity): void
    {
        $this->guard(fn () => $this->svc()->setQuantity(auth()->user(), $this->cartId, $itemId, max(1, $quantity), $this->stamp));
    }

    public function removeLine(string $itemId): void
    {
        $item = Str::isUuid($itemId) ? CartItem::with('variant.product.translations')->where('cart_id', $this->cartId)->find($itemId) : null;
        if (! $item) {
            $this->toast(__('abandoned_admin.err_cart_changed'), 'warning');

            return;
        }
        $r = $this->guard(fn () => $this->svc()->removeItem(auth()->user(), $this->cartId, $itemId, $this->stamp));
        if (is_array($r) && $r['removed']) {
            $this->dispatch('toast', type: 'success', message: __('abandoned_admin.line_removed', ['name' => $item->variant->product->trans('name')]),
                undoLabel: __('admin.undo'), undoEvent: 'abandoned-undo-remove', undoParams: ['id' => $this->cartId, 'variantId' => $r['removed']['variant_id'], 'quantity' => $r['removed']['quantity']]);
        }
    }

    #[On('abandoned-undo-remove')]
    public function undoRemove(string $id, string $variantId, int $quantity): void
    {
        $this->guard(fn () => $this->svc()->addItem(auth()->user(), $id, $variantId, max(1, $quantity)), __('abandoned_admin.line_restored'));
    }

    public function addVariant(string $variantId): void
    {
        $this->guard(fn () => $this->svc()->addItem(auth()->user(), $this->cartId, $variantId, 1, $this->stamp), __('abandoned_admin.line_added'));
    }

    // ── Coupon ───────────────────────────────────────────────────────────────

    public function applyCoupon(): void
    {
        $code = trim($this->couponInput);
        $this->resetErrorBag('couponInput');
        if ($code === '') {
            $this->addError('couponInput', __('abandoned_admin.coupon_empty'));

            return;
        }
        if ($this->guard(fn () => $this->svc()->setCoupon(auth()->user(), $this->cartId, $code, $this->stamp), __('abandoned_admin.coupon_applied', ['code' => strtoupper($code)]))) {
            $this->couponInput = '';
        }
    }

    public function removeCoupon(): void
    {
        $this->resetErrorBag('couponInput');
        $this->guard(fn () => $this->svc()->setCoupon(auth()->user(), $this->cartId, null, $this->stamp), __('abandoned_admin.coupon_removed'));
    }

    // ── Reminder, discard, place the order ───────────────────────────────────

    public function remind(string $id): void
    {
        $r = $this->guard(fn () => $this->svc()->remind(auth()->user(), $id));
        if (! is_array($r)) {
            return;
        }
        if ($r['status'] === 'sent') {
            $this->toast(__('abandoned_admin.reminder_sent', ['channels' => collect($r['channels'])->map(fn (string $c) => (string) __('abandoned_admin.channel_'.$c))->implode(', ')]));
        } else {
            $this->toast(__('abandoned_admin.reminder_'.$r['status'], ['limit' => $r['limit'], 'time' => $r['next_at'] ? BusinessTime::display($r['next_at'], 'd M, H:i') : '']), 'warning');
        }
    }

    public function discard(string $id): void
    {
        if ($this->guard(fn () => $this->svc()->discard(auth()->user(), $id))) {
            $this->dispatch('toast', type: 'success', message: __('abandoned_admin.discarded'), undoLabel: __('admin.undo'), undoEvent: 'abandoned-undo-discard', undoParams: ['id' => $id]);
            if ($id === $this->cartId) {
                $this->closeCart();
            }
        }
    }

    #[On('abandoned-undo-discard')]
    public function undoDiscard(string $id): void
    {
        $this->guard(fn () => $this->svc()->restore(auth()->user(), $id), __('abandoned_admin.restored'));
    }

    /** "Place order for customer": the cart becomes a staff draft and the order cart panel opens at the checkout step. */
    public function placeOrder(): void
    {
        $draft = $this->guard(fn () => $this->svc()->placeForCustomer(auth()->user(), $this->cartId));
        if ($draft instanceof Cart) {
            $this->closeCart();
            $this->dispatch('order-cart-updated');
            $this->dispatch('open-order-cart', step: 'checkout');
        }
    }

    // ── Plumbing ─────────────────────────────────────────────────────────────

    /**
     * Run a service action. A refusal is a plain toast (an out-of-date window gets a warning and re-reads the cart on the next render);
     * a coupon the customer's account cannot use becomes the inline message under the coupon box. Returns the result, true for a void
     * action, or null when it was refused.
     */
    private function guard(callable $action, ?string $success = null): mixed
    {
        try {
            $result = $action();
            if ($success) {
                $this->toast($success);
            }

            return $result ?? true;
        } catch (ValidationException $e) {
            $this->addError('couponInput', (string) $e->validator->errors()->first());
        } catch (DomainException $e) {
            $this->toast($e->getMessage(), in_array($e->errorCode, ['cart_changed', 'cart_active_again'], true) ? 'warning' : 'error');
        } catch (AuthorizationException) {
            $this->toast(__('abandoned_admin.err_forbidden'), 'error');
        }

        return null;
    }
}
