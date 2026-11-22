<?php

namespace App\Livewire\Admin\Sales;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Services\SlotService;
use App\Domain\Fulfillment\Enums\DeliveryStatus as DS;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Services\AssemblyFacts;
use App\Domain\Fulfillment\Services\DeliveryFollowUpSettings;
use App\Domain\Fulfillment\Services\DeliveryService;
use App\Domain\Fulfillment\Services\SlotOffers;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * What the branch sees and does about the two delivery follow-ups of one branch part, inside the order window or on the order page
 * (docs/guides/DELIVERY_FOLLOW_UPS.md):
 *
 *  - NEW TIMES after a failed delivery: whether the customer was offered times, is still choosing (until when), chose one, or the offer ran out; and the
 *    button "Offer new times", which proposes up to five free slots to the customer through the same service as the system's own offer.
 *  - ASSEMBLY the driver reported on: done (when), or not done (why, his note) with the button "Mark as handled" once the branch refunded the fee or booked
 *    it again. The refund itself stays the existing approval flow (Finance > Refunds).
 * It draws nothing for a part without either, and every step needs deliveries.assign at the part's branch (the same right as rescheduling).
 */
class DeliveryFollowUp extends Component
{
    use InteractsWithAdmin;

    #[Locked]
    public string $fulfillmentId;

    /** Inside an order side window the dialog opens as a modal over it; on the order page it is a side window. */
    #[Locked]
    public bool $drawer = false;

    public bool $showOffer = false;

    /** The times picked for the offer, as "slot id|date". @var list<string> */
    public array $picked = [];

    public function mount(string $fulfillmentId, bool $drawer = false): void
    {
        $this->fulfillmentId = $fulfillmentId;
        $this->drawer = $drawer;
        abort_unless($this->can('orders.view', $this->part()->branch_id), 403);
    }

    private function part(): OrderFulfillment
    {
        return OrderFulfillment::query()->findOrFail($this->fulfillmentId);
    }

    private function delivery(OrderFulfillment $f): ?Delivery
    {
        return $f->activeDelivery();
    }

    /** The branch opens the "Offer new times" window with the times the system would offer already ticked. */
    public function openOffer(SlotOffers $offers, DeliveryFollowUpSettings $config): void
    {
        $f = $this->part();
        $this->authorizeBranch('deliveries.assign', $f->branch_id);
        $d = $this->delivery($f);
        if ($d === null || $d->status !== DS::Failed) {
            $this->toast(__('delivery_followup.errors.offer_needs_failed_delivery'), 'error');

            return;
        }
        $this->picked = array_map(fn (array $t) => $t['slot_id'].'|'.$t['date'], $offers->pickOptions(Branch::findOrFail($f->branch_id), $config->offerCount()));
        $this->showOffer = true;
    }

    public function toggle(string $key): void
    {
        if (in_array($key, $this->picked, true)) {
            $this->picked = array_values(array_diff($this->picked, [$key]));
        } elseif (count($this->picked) < SlotOffers::MAX_MANUAL) {
            $this->picked[] = $key;
        } else {
            $this->toast(__('delivery_followup.errors.offer_choose_times', ['max' => SlotOffers::MAX_MANUAL]), 'error');
        }
    }

    public function sendOffer(SlotOffers $offers): void
    {
        $f = $this->part();
        $this->authorizeBranch('deliveries.assign', $f->branch_id);
        $d = $this->delivery($f);
        $options = array_map(function (string $key) {
            [$slot, $date] = array_pad(explode('|', $key, 2), 2, '');

            return ['slot_id' => $slot, 'date' => $date];
        }, $this->picked);
        if ($d === null || $this->attempt(fn () => $offers->propose($d, auth()->user(), $options), __('delivery_followup.ui.offer_sent')) === null) {
            return;
        }
        $this->showOffer = false;
        $this->picked = [];
        $this->dispatch('record-changed');
    }

    /** The branch dealt with an assembly that was not done (refunded the fee through the refund approval, or booked it again). */
    public function handleAssembly(DeliveryService $svc): void
    {
        $f = $this->part();
        $this->authorizeBranch('deliveries.assign', $f->branch_id);
        if ($this->attempt(fn () => $svc->handleAssembly($f, auth()->user()), __('delivery_followup.ui.assembly_handled')) !== null) {
            $this->dispatch('record-changed');
        }
    }

    public function render(SlotOffers $offers, SlotService $slots)
    {
        $f = $this->part();
        $d = $this->delivery($f);
        $note = $d !== null ? ($offers->staffNotes([$d])[$d->id] ?? null) : null;
        $assembly = $f->type === 'delivery' ? AssemblyFacts::state($f) : null;
        $canAssign = $this->can('deliveries.assign', $f->branch_id);
        $canOffer = $canAssign && $d !== null && $d->status === DS::Failed;

        $days = [];
        if ($this->showOffer && $canOffer) {
            $today = BusinessTime::now();
            foreach ($slots->availableRange(Branch::findOrFail($f->branch_id), 'delivery', $today->toDateString(), $today->addDays(9)->toDateString(), onlyAvailable: true) as $day) {
                $rows = array_values(array_filter($day['slots'], fn (array $s) => $s['available'] > 0));
                if ($rows !== []) {
                    $days[] = ['date' => $day['date'], 'label' => CarbonImmutable::parse($day['date'], BusinessTime::zone())->locale(app()->getLocale())->translatedFormat('l j M'), 'slots' => $rows];
                }
            }
        }

        return view('livewire.admin.sales.delivery-follow-up', [
            'f' => $f, 'delivery' => $d, 'note' => $note, 'assembly' => $assembly, 'canOffer' => $canOffer, 'canAssign' => $canAssign, 'days' => $days,
            'hours' => app(DeliveryFollowUpSettings::class)->offerHours(), 'enabled' => app(DeliveryFollowUpSettings::class)->offersEnabled(),
        ]);
    }
}
