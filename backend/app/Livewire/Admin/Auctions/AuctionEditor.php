<?php

namespace App\Livewire\Admin\Auctions;

use App\Domain\Auctions\Models\Auction;
use App\Domain\Auctions\Models\AuctionLot;
use App\Domain\Auctions\Services\AuctionService;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductVariant;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Livewire\Admin\Concerns\WithVariantPicker;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Auction editor: event settings (type, window, registration, deposit, payment deadline, anti-sniping, unpaid policy),
 * EN/AR title/description/terms, branch eligibility, lots (catalog-linked or auction-only) and publish/cancel.
 * Settings are editable only while draft/scheduled (enforced by AuctionService).
 */
class AuctionEditor extends Component
{
    use InteractsWithAdmin, WithVariantPicker;

    #[Locked]
    public ?string $auctionId = null;

    public array $form = [];

    public array $tr = ['en' => ['title' => '', 'description' => '', 'terms' => ''], 'ar' => ['title' => '', 'description' => '', 'terms' => '']];

    public array $branchIds = [];

    public bool $showLot = false;

    public array $lot = [];

    /** Set while editing an existing lot; null when adding. */
    #[Locked]
    public ?string $editingLotId = null;

    public bool $showCancel = false;

    public string $cancelReason = '';

    public function mount(?Auction $auction = null): void
    {
        abort_unless($this->can($auction?->exists ? 'auctions.view' : 'auctions.create'), 403);
        if ($auction?->exists) {
            $this->auctionId = $auction->id;
            $this->fillFrom($auction->load('translations', 'branches'));
        } else {
            $this->form = ['type' => 'scheduled', 'starts_at' => '', 'ends_at' => '', 'requires_registration' => true, 'requires_verified_phone' => true,
                'deposit_amount' => '0', 'winner_payment_hours' => 48, 'cod_allowed' => false, 'pickup_allowed' => true, 'delivery_allowed' => true,
                'extension_window_seconds' => 120, 'extension_seconds' => 120, 'max_extensions' => '', 'unpaid_winner_policy' => 'offer_next_bidder', 'show_bidder_names' => false];
        }
    }

    private function fillFrom(Auction $a): void
    {
        $local = fn ($v) => $v ? BusinessTime::local($v)->format('Y-m-d\TH:i') : '';
        $this->form = ['type' => $a->type, 'starts_at' => $local($a->starts_at), 'ends_at' => $local($a->ends_at), 'requires_registration' => (bool) $a->requires_registration,
            'requires_verified_phone' => (bool) $a->requires_verified_phone, 'deposit_amount' => (string) $a->deposit_amount, 'winner_payment_hours' => $a->winner_payment_hours,
            'cod_allowed' => (bool) $a->cod_allowed, 'pickup_allowed' => (bool) $a->pickup_allowed, 'delivery_allowed' => (bool) $a->delivery_allowed,
            'extension_window_seconds' => $a->extension_window_seconds, 'extension_seconds' => $a->extension_seconds, 'max_extensions' => $a->max_extensions ?? '',
            'unpaid_winner_policy' => $a->unpaid_winner_policy, 'show_bidder_names' => (bool) $a->show_bidder_names];
        foreach (['en', 'ar'] as $l) {
            $t = $a->translations->firstWhere('locale', $l);
            $this->tr[$l] = ['title' => $t?->title ?? '', 'description' => $t?->description ?? '', 'terms' => $t?->terms ?? ''];
        }
        $this->branchIds = $a->branches->pluck('id')->all();
    }

    private function auction(): ?Auction
    {
        return $this->auctionId ? Auction::findOrFail($this->auctionId) : null;
    }

    /** Datetime-local input (business time zone) → UTC instant. */
    private static function instant(?string $local): ?string
    {
        return $local ? CarbonImmutable::parse($local, BusinessTime::zone())->utc()->toIso8601String() : null;
    }

    public function save(AuctionService $svc)
    {
        abort_unless($this->can($this->auctionId ? 'auctions.edit' : 'auctions.create'), 403);
        $data = $this->validate([
            'form.type' => 'required|in:scheduled,live',
            'form.starts_at' => 'nullable|date', 'form.ends_at' => 'nullable|date|after:form.starts_at',
            'form.requires_registration' => 'boolean', 'form.requires_verified_phone' => 'boolean',
            'form.deposit_amount' => 'nullable|numeric|min:0|max:1000000', 'form.winner_payment_hours' => 'required|integer|min:1|max:720',
            'form.cod_allowed' => 'boolean', 'form.pickup_allowed' => 'boolean', 'form.delivery_allowed' => 'boolean',
            'form.extension_window_seconds' => 'required|integer|min:0|max:3600', 'form.extension_seconds' => 'required|integer|min:0|max:3600',
            'form.max_extensions' => 'nullable|integer|min:0|max:1000', 'form.unpaid_winner_policy' => 'required|in:offer_next_bidder,relist,none',
            'form.show_bidder_names' => 'boolean',
            'tr.en.title' => 'required|string|max:255', 'tr.ar.title' => 'required|string|max:255',
            'tr.*.description' => 'nullable|string|max:20000', 'tr.*.terms' => 'nullable|string|max:20000',
            'branchIds' => 'array', 'branchIds.*' => 'uuid|exists:branches,id',
        ], [], ['tr.en.title' => __('auction_admin.title_en'), 'tr.ar.title' => __('auction_admin.title_ar'), 'form.ends_at' => __('auction_admin.ends_at'), 'form.winner_payment_hours' => __('auction_admin.payment_hours')]);
        $f = $data['form'];
        $payload = array_merge($f, [
            'starts_at' => self::instant($f['starts_at']), 'ends_at' => self::instant($f['ends_at']),
            'deposit_amount' => $f['deposit_amount'] === '' || $f['deposit_amount'] === null ? '0' : (string) $f['deposit_amount'],
            'max_extensions' => $f['max_extensions'] === '' ? null : (int) $f['max_extensions'],
            'translations' => $data['tr'], 'branch_ids' => $data['branchIds'],
        ]);
        $a = $this->attempt(fn () => $svc->save(auth()->user(), $payload, $this->auction()), __('auction_admin.saved'));
        if ($a instanceof Auction && ! $this->auctionId) {
            session()->flash('toast', ['type' => 'success', 'message' => __('auction_admin.created')]);

            return $this->redirectRoute('admin.auctions.edit', $a, navigate: true);
        }
    }

    public function newLot(): void
    {
        abort_unless($this->auctionId && $this->can('auctions.edit'), 403);
        $a = $this->auction();
        $this->editingLotId = null;
        $local = fn ($v) => $v ? BusinessTime::local($v)->format('Y-m-d\TH:i') : '';
        $this->lot = ['title_en' => '', 'title_ar' => '', 'description_en' => '', 'description_ar' => '', 'starting_price' => '', 'reserve_price' => '', 'min_increment' => '50',
            'buy_now_price' => '', 'quantity' => 1, 'starts_at' => $local($a->starts_at), 'ends_at' => $local($a->ends_at), 'product_variant_id' => '', 'branch_id' => ''];
        $this->variantSearch = '';
        $this->resetValidation();
        $this->showLot = true;
    }

    protected function onVariantPicked(ProductVariant $variant): void
    {
        $variant->load('product.translations');
        $this->lot['product_variant_id'] = $variant->id;
        $this->lot['title_en'] = $this->lot['title_en'] ?: (string) $variant->product?->trans('name', 'en');
        $this->lot['title_ar'] = $this->lot['title_ar'] ?: (string) $variant->product?->trans('name', 'ar');
    }

    public function clearVariant(): void
    {
        $this->lot['product_variant_id'] = '';
        $this->lot['branch_id'] = '';
    }

    public function editLot(string $id): void
    {
        abort_unless($this->auctionId && $this->can('auctions.edit'), 403);
        $l = AuctionLot::where('auction_id', $this->auctionId)->with('products')->findOrFail($id);
        abort_unless(in_array($l->status, ['pending', 'open', 'paused'], true) && ! in_array($this->auction()->status, ['closed', 'cancelled'], true), 403);
        $local = fn ($v) => $v ? BusinessTime::local($v)->format('Y-m-d\TH:i') : '';
        $link = $l->products->first();
        $this->editingLotId = $l->id;
        $this->lot = ['title_en' => $l->title_en, 'title_ar' => $l->title_ar, 'description_en' => (string) $l->description_en, 'description_ar' => (string) $l->description_ar,
            'starting_price' => (string) $l->starting_price, 'reserve_price' => (string) ($l->reserve_price ?? ''), 'min_increment' => (string) $l->min_increment,
            'buy_now_price' => (string) ($l->buy_now_price ?? ''), 'quantity' => $l->quantity, 'starts_at' => $local($l->starts_at), 'ends_at' => $local($l->ends_at),
            'product_variant_id' => (string) ($link?->product_variant_id ?? ''), 'branch_id' => (string) ($link?->branch_id ?? ''), 'lot_number' => $l->lot_number];
        $this->variantSearch = '';
        $this->resetValidation();
        $this->showLot = true;
    }

    public function deleteLot(string $id, AuctionService $svc): void
    {
        abort_unless($this->auctionId && $this->can('auctions.delete'), 403);
        $l = AuctionLot::where('auction_id', $this->auctionId)->findOrFail($id);
        $this->attempt(fn () => $svc->deleteLot(auth()->user(), $l), __('auction_admin.lot_deleted'));
    }

    /** Add a lot, or save the one being edited (what is editable follows the lot's state; see AuctionService::updateLot). */
    public function saveLot(AuctionService $svc): void
    {
        abort_unless($this->can('auctions.edit'), 403);
        $a = $this->auction();
        $existing = $this->editingLotId ? AuctionLot::where('auction_id', $a->id)->findOrFail($this->editingLotId) : null;
        $full = ! $existing || $existing->status === 'pending';
        $reserveEditable = $full || (int) $existing->bid_count === 0;

        $rules = ['lot.title_en' => 'required|string|max:255', 'lot.title_ar' => 'required|string|max:255',
            'lot.description_en' => 'nullable|string|max:5000', 'lot.description_ar' => 'nullable|string|max:5000'];
        if ($reserveEditable) {
            $rules['lot.reserve_price'] = 'nullable|numeric|min:0';
        }
        if ($full) {
            $rules += ['lot.starting_price' => 'required|numeric|min:0', 'lot.min_increment' => 'required|numeric|min:1', 'lot.buy_now_price' => 'nullable|numeric|gt:lot.starting_price',
                'lot.quantity' => 'required|integer|min:1|max:1000',
                'lot.starts_at' => ($a->type === 'scheduled' ? 'required' : 'nullable').'|date', 'lot.ends_at' => ($a->type === 'scheduled' ? 'required' : 'nullable').'|date|after:lot.starts_at',
                'lot.product_variant_id' => 'nullable|uuid|exists:product_variants,id',
                'lot.branch_id' => 'required_with:lot.product_variant_id|nullable|uuid|exists:branches,id'];
        }
        $data = $this->validate($rules, [], ['lot.title_en' => __('auction_admin.title_en'), 'lot.title_ar' => __('auction_admin.title_ar'), 'lot.starting_price' => __('auction_admin.starting_price'),
            'lot.min_increment' => __('auction_admin.increment'), 'lot.buy_now_price' => __('auction_admin.buy_now'), 'lot.branch_id' => __('auction_admin.stock_branch'),
            'lot.starts_at' => __('auction_admin.starts_at'), 'lot.ends_at' => __('auction_admin.ends_at')])['lot'];

        $payload = $data;
        foreach (['reserve_price', 'buy_now_price', 'product_variant_id', 'branch_id'] as $k) {
            if (array_key_exists($k, $payload)) {
                $payload[$k] = $payload[$k] === '' ? null : $payload[$k];
            }
        }
        foreach (['starts_at', 'ends_at'] as $k) {
            if (array_key_exists($k, $payload)) {
                $payload[$k] = self::instant($payload[$k]);
            }
        }

        $ok = $existing
            ? $this->attempt(fn () => $svc->updateLot(auth()->user(), $existing, $payload), __('auction_admin.lot_updated'))
            : $this->attempt(fn () => $svc->addLot(auth()->user(), $a, $payload), __('auction_admin.lot_added'));
        if ($ok) {
            $this->showLot = false;
            $this->editingLotId = null;
        }
    }

    public function publish(AuctionService $svc): void
    {
        abort_unless($this->can('auctions.approve') || $this->can('auctions.edit'), 403);
        $this->attempt(fn () => $svc->publish(auth()->user(), $this->auction()), __('auction_admin.published'));
    }

    public function cancelAuction(AuctionService $svc): void
    {
        abort_unless($this->can('auctions.edit'), 403);
        $this->validate(['cancelReason' => 'required|string|min:5|max:500'], [], ['cancelReason' => __('admin.reason')]);
        if ($this->attempt(fn () => $svc->cancel(auth()->user(), $this->auction(), trim($this->cancelReason)), __('auction_admin.cancelled'))) {
            $this->showCancel = false;
        }
    }

    public function render()
    {
        $a = $this->auctionId ? Auction::with(['lots.products.variant.product.translations', 'lots.winners'])->find($this->auctionId) : null;
        $editable = ! $a || in_array($a->status, ['draft', 'scheduled'], true);
        $editingLot = $this->editingLotId ? AuctionLot::where('auction_id', $this->auctionId)->find($this->editingLotId) : null;
        $lotsOpen = $a && $this->can('auctions.edit') && ! in_array($a->status, ['closed', 'cancelled'], true);

        return view('livewire.admin.auctions.editor', [
            'a' => $a,
            'editable' => $editable && $this->can($a ? 'auctions.edit' : 'auctions.create'),
            'branches' => Branch::active()->orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_ar']),
            'editingLot' => $editingLot,
            'canEditLots' => $lotsOpen,
            'canDeleteLots' => $lotsOpen && $this->can('auctions.delete'),
            'matches' => $this->showLot && (! $editingLot || $editingLot->status === 'pending') ? $this->variantMatches() : collect(),
            'lotVariant' => ! empty($this->lot['product_variant_id']) ? ProductVariant::with(['product.translations', 'attributeValues'])->find($this->lot['product_variant_id']) : null,
            'canPublish' => $a && $a->status === 'draft' && ($this->can('auctions.approve') || $this->can('auctions.edit')),
            'canCancel' => $a && ! in_array($a->status, ['closed', 'cancelled'], true) && $this->can('auctions.edit'),
        ])->title($a ? ($a->trans('title') ?: $a->reference) : __('auction_admin.new_auction'));
    }
}
