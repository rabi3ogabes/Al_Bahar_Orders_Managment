<?php

namespace App\Livewire\Admin\Sales;

use App\Domain\Branches\Services\SlotService;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Checkout\Services\StaffOrderService;
use App\Domain\Orders\Models\Cart;
use App\Domain\Orders\Models\Order;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\BusinessTime;
use App\Support\Money;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The order cart side panel for admin-assisted orders. One component, two skins:
 *  - mode "overlay": a drawer (bottom sheet on phones) available from anywhere in the dashboard (mounted in the admin layout)
 *  - mode "docked": the sticky column of the "New order" page
 * Steps: cart → checkout → done, plus the list of held drafts. All state lives in the staff draft cart (server side);
 * every action is authorised again by StaffOrderService.
 */
class OrderCart extends Component
{
    use InteractsWithAdmin;

    public string $mode = 'overlay';

    public bool $open = false;

    /** cart | checkout | drafts | done */
    public string $step = 'cart';

    public string $customerSearch = '';

    public bool $showNewCustomer = false;

    /** @var array{name: string, phone: string, locale: string} */
    public array $newCustomer = ['name' => '', 'phone' => '', 'locale' => 'ar'];

    public bool $showAddress = false;

    /** @var array<string, string> */
    public array $address = ['recipient_name' => '', 'recipient_phone' => '', 'zone_number' => '', 'street_number' => '', 'building_number' => '', 'unit_number' => '', 'landmark' => ''];

    public string $coupon = '';

    public string $notes = '';

    public string $internalNote = '';

    public string $idempotencyKey = '';

    public ?string $discardId = null;

    /** @var array<string, mixed>|null */
    public ?array $placed = null;

    public function mount(string $mode = 'overlay', string $step = 'cart'): void
    {
        $this->mode = $mode === 'docked' ? 'docked' : 'overlay';
        $this->step = in_array($step, ['cart', 'checkout'], true) ? $step : 'cart';
        $this->idempotencyKey = (string) Str::uuid();
        if ($this->mode === 'docked') {
            abort_unless($this->svc()->canCreate(auth()->user()), 403);
        }
    }

    private function svc(): StaffOrderService
    {
        return app(StaffOrderService::class);
    }

    private function draft(): ?Cart
    {
        return $this->svc()->current(auth()->user());
    }

    /** Run a draft action; creates the draft first when `$create` (e.g. picking a customer before any product). */
    private function act(callable $fn, bool $create = false, ?string $success = null): mixed
    {
        $draft = $this->draft() ?? ($create ? $this->attempt(fn () => $this->svc()->create(auth()->user())) : null);
        if (! $draft instanceof Cart) {
            return null;
        }
        $result = $this->attempt(fn () => $fn($draft), $success);
        $this->dispatch('order-cart-updated');

        return $result;
    }

    // ── Open / close (overlay) ───────────────────────────────────────────────

    #[On('open-order-cart')]
    public function openPanel(string $step = 'cart'): void
    {
        if ($this->mode !== 'overlay' || ! $this->svc()->canCreate(auth()->user())) {
            return;
        }
        $this->step = in_array($step, ['cart', 'checkout', 'drafts'], true) ? $step : 'cart';
        $this->open = true;
    }

    public function closePanel(): void
    {
        $this->open = false;
        $this->discardId = null;
        if ($this->step === 'done') {
            $this->step = 'cart';
            $this->placed = null;
        }
    }

    #[On('order-cart-updated')]
    public function refresh(): void
    {
        // re-render only
    }

    public function go(string $step): void
    {
        if (in_array($step, ['cart', 'checkout', 'drafts'], true)) {
            $this->step = $step;
            $this->discardId = null;
        }
    }

    // ── Customer ─────────────────────────────────────────────────────────────

    public function pickCustomer(string $id): void
    {
        $this->act(fn (Cart $d) => $this->svc()->setCustomer(auth()->user(), $d, $id), create: true);
        $this->reset('customerSearch', 'showNewCustomer');
    }

    public function clearCustomer(): void
    {
        $this->act(fn (Cart $d) => $this->svc()->setCustomer(auth()->user(), $d, null));
    }

    public function saveNewCustomer(): void
    {
        $r = $this->attempt(fn () => $this->svc()->createCustomer(auth()->user(), $this->newCustomer));
        if (! $r) {
            return;
        }
        $this->toast($r['existed'] ? __('neworder_admin.customer_existing', ['name' => $r['user']->name]) : __('neworder_admin.customer_created', ['name' => $r['user']->name]));
        $this->pickCustomer($r['user']->id);
        $this->reset('newCustomer');
        $this->newCustomer['locale'] = 'ar';
    }

    // ── Lines ────────────────────────────────────────────────────────────────

    public function bump(string $itemId, int $delta): void
    {
        $item = $this->draft()?->items()->find($itemId);
        if ($item) {
            $this->act(fn (Cart $d) => $this->svc()->update(auth()->user(), $d, $itemId, max(0, $item->quantity + $delta)));
        }
    }

    public function setQuantity(string $itemId, int $quantity): void
    {
        $this->act(fn (Cart $d) => $this->svc()->update(auth()->user(), $d, $itemId, max(0, $quantity)));
    }

    public function removeLine(string $itemId): void
    {
        $item = $this->draft()?->items()->with('variant.product.translations')->find($itemId);
        if (! $item) {
            return;
        }
        $this->act(fn (Cart $d) => $this->svc()->remove(auth()->user(), $d, $itemId));
        $this->dispatch('toast', type: 'success', message: __('neworder_admin.line_removed', ['name' => $item->variant->product->trans('name')]),
            undoLabel: __('neworder_admin.undo'), undoEvent: 'order-cart-readd', undoParams: ['variantId' => $item->product_variant_id, 'quantity' => $item->quantity]);
    }

    #[On('order-cart-readd')]
    public function readd(string $variantId, int $quantity = 1): void
    {
        $this->act(fn (Cart $d) => $this->svc()->add(auth()->user(), $d, $variantId, max(1, $quantity)), create: true);
    }

    #[On('order-cart-unadd')]
    public function unadd(string $variantId, int $quantity = 1): void
    {
        $item = $this->draft()?->items()->where('product_variant_id', $variantId)->first();
        if ($item) {
            $this->setQuantity($item->id, $item->quantity - $quantity);
        }
    }

    public function addSuggestion(string $variantId): void
    {
        $this->readd($variantId, 1);
    }

    // ── Options ──────────────────────────────────────────────────────────────

    public function setBranch(string $branchId): void
    {
        $this->act(fn (Cart $d) => $this->svc()->setOptions(auth()->user(), $d, ['branch_id' => $branchId ?: null]), create: true);
    }

    public function setFulfillment(string $type): void
    {
        $this->act(fn (Cart $d) => $this->svc()->setOptions(auth()->user(), $d, ['fulfillment_type' => $type]));
    }

    public function setAddress(string $addressId): void
    {
        $this->act(fn (Cart $d) => $this->svc()->setOptions(auth()->user(), $d, ['address_id' => $addressId]));
    }

    public function saveAddress(): void
    {
        $ok = $this->act(fn (Cart $d) => $this->svc()->addAddress(auth()->user(), $d, $this->address), success: __('neworder_admin.address_added'));
        if ($ok) {
            $this->reset('address', 'showAddress');
            $this->address = ['recipient_name' => '', 'recipient_phone' => '', 'zone_number' => '', 'street_number' => '', 'building_number' => '', 'unit_number' => '', 'landmark' => ''];
        }
    }

    public function setPayment(string $method): void
    {
        $this->act(fn (Cart $d) => $this->svc()->setOptions(auth()->user(), $d, ['payment_method' => $method]));
    }

    public function pickSlot(string $slotId, string $date): void
    {
        $key = $this->draft()?->fulfillment_type === 'pickup' ? 'pickup' : 'delivery';
        $this->act(fn (Cart $d) => $this->svc()->setOptions(auth()->user(), $d, [$key.'_slot_id' => $slotId, $key.'_date' => $date]));
    }

    public function pickDate(string $date): void
    {
        $key = $this->draft()?->fulfillment_type === 'pickup' ? 'pickup' : 'delivery';
        $this->act(fn (Cart $d) => $this->svc()->setOptions(auth()->user(), $d, [$key.'_date' => $date, $key.'_slot_id' => null]));
    }

    public function applyCoupon(): void
    {
        $code = trim($this->coupon);
        $this->resetErrorBag('coupon');
        try {
            $this->act(fn (Cart $d) => $this->svc()->setOptions(auth()->user(), $d, ['coupon_code' => $code ?: null]));
            $this->coupon = '';
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError('coupon', $e->validator->errors()->first());
        }
    }

    public function removeCoupon(): void
    {
        $this->act(fn (Cart $d) => $this->svc()->setOptions(auth()->user(), $d, ['coupon_code' => null]));
    }

    public function updatedNotes(): void
    {
        $this->act(fn (Cart $d) => $this->svc()->setOptions(auth()->user(), $d, ['notes' => $this->notes]));
    }

    public function updatedInternalNote(): void
    {
        $this->act(fn (Cart $d) => $this->svc()->setOptions(auth()->user(), $d, ['internal_note' => $this->internalNote]));
    }

    // ── Place ────────────────────────────────────────────────────────────────

    public function placeOrder(string $quoteHash): void
    {
        if ($this->step === 'done') {
            return;
        }
        $res = $this->act(fn (Cart $d) => $this->svc()->place(auth()->user(), $d, $quoteHash, $this->idempotencyKey));
        if (! $res) {
            return;
        }
        $order = $res['order'];
        $this->placed = ['id' => $order->id, 'number' => $order->number, 'method' => $order->payment_method, 'total' => Money::of($order->grand_total)->format(),
            'link' => $res['payment_link'], 'customer' => $order->customer_name, 'sent' => []];
        $this->step = 'done';
        $this->idempotencyKey = (string) Str::uuid();
        $this->reset('notes', 'internalNote', 'coupon');
        $this->toast(__('neworder_admin.order_placed', ['number' => $order->number]));
    }

    public function sendLink(): void
    {
        $order = $this->placed ? Order::with('user')->find($this->placed['id']) : null;
        if (! $order) {
            return;
        }
        $sent = $this->attempt(fn () => $this->svc()->sendPaymentLink(auth()->user(), $order));
        if (is_array($sent)) {
            $this->placed['sent'] = $sent;
            $this->toast(__('neworder_admin.link_sent'));
        }
    }

    public function newOrder(): void
    {
        $this->placed = null;
        $this->step = 'cart';
        $this->dispatch('order-cart-updated');
    }

    // ── Held orders ──────────────────────────────────────────────────────────

    public function startDraft(): void
    {
        $this->attempt(fn () => $this->svc()->create(auth()->user()));
        $this->step = 'cart';
        $this->dispatch('order-cart-updated');
    }

    public function resume(string $id): void
    {
        $ok = $this->attempt(fn () => $this->svc()->setCurrent(auth()->user(), Cart::where('status', Cart::STAFF_DRAFT)->findOrFail($id)));
        if ($ok) {
            $this->step = 'cart';
            $this->dispatch('order-cart-updated');
        }
    }

    public function askDiscard(string $id): void
    {
        $this->discardId = $id;
    }

    public function cancelDiscard(): void
    {
        $this->discardId = null;
    }

    public function discard(): void
    {
        $id = $this->discardId;
        $this->discardId = null;
        if (! $id) {
            return;
        }
        $this->attempt(function () use ($id) {
            $this->svc()->discard(auth()->user(), $this->svc()->find(auth()->user(), $id));
        }, __('neworder_admin.draft_discarded'));
        $this->dispatch('order-cart-updated');
    }

    // ── Render ───────────────────────────────────────────────────────────────

    public function render()
    {
        $user = auth()->user();
        $svc = $this->svc();
        if (! $svc->canCreate($user) || ($this->mode === 'overlay' && ! $this->open)) {
            return view('livewire.admin.sales.order-cart', ['active' => false]);
        }

        $data = ['active' => true, 'branches' => $svc->branches($user), 'draft' => null, 'cart' => null, 'quote' => null, 'customer' => null,
            'results' => [], 'draftCount' => 0, 'slotKey' => 'delivery', 'addresses' => collect(), 'suggestions' => [], 'drafts' => collect(), 'slotDays' => [], 'selectedDate' => null];

        if ($this->step === 'drafts') {
            $data['drafts'] = $svc->drafts($user);
            $data['draft'] = $svc->current($user);

            return view('livewire.admin.sales.order-cart', $data);
        }
        $draft = $svc->current($user);
        $data['draft'] = $draft;
        if (! $draft) {
            $data['results'] = mb_strlen(trim($this->customerSearch)) >= 2 ? $svc->searchCustomers($user, $this->customerSearch) : [];
            $data['drafts'] = $svc->drafts($user)->where('staff_id', $user->id);

            return view('livewire.admin.sales.order-cart', $data);
        }

        $data['results'] = $draft->user_id === null && mb_strlen(trim($this->customerSearch)) >= 2 ? $svc->searchCustomers($user, $this->customerSearch) : [];
        $data['customer'] = $draft->user_id ? $draft->user()->first(['id', 'name', 'phone', 'locale']) : null;
        $data['cart'] = app(CartService::class)->present($draft);
        $data['quote'] = $this->step === 'done' ? null : $svc->quote($user, $draft);
        $data['draftCount'] = $svc->drafts($user)->count();
        if ($this->step === 'cart' && $data['cart']['items']->isNotEmpty()) {
            $data['suggestions'] = $svc->suggestions($user, $draft);
        }
        if ($this->step === 'checkout') {
            $data['addresses'] = $data['customer'] ? $data['customer']->addresses()->with('zone')->orderByDesc('is_default')->latest()->get() : collect();
            $this->notes = $this->notes === '' ? ($draft->staff_options['notes'] ?? '') : $this->notes;
            $this->internalNote = $this->internalNote === '' ? ($draft->staff_options['internal_note'] ?? '') : $this->internalNote;
            $quote = $data['quote'];
            $slots = app(SlotService::class);
            if ($quote && count($quote->plans) === 1 && ! $quote->isSplit && $slots->hasSlots($quote->plans[0]->branch, $quote->fulfillmentType)) {
                $from = BusinessTime::now();
                $data['slotDays'] = $slots->availableRange($quote->plans[0]->branch, $quote->fulfillmentType, $from->toDateString(), $from->addDays(6)->toDateString(), true);
                $key = $quote->fulfillmentType === 'pickup' ? 'pickup' : 'delivery';
                $data['selectedDate'] = ($draft->staff_options[$key.'_date'] ?? null) ?: ($data['slotDays'][0]['date'] ?? null);
                $data['slotKey'] = $key;
            }
        }

        return view('livewire.admin.sales.order-cart', $data);
    }
}
