<?php

namespace App\Livewire\Admin\Finance\Concerns;

use App\Domain\Identity\Models\User;
use App\Domain\Payments\Models\CashPayout;
use App\Domain\Payments\Models\CashShift;
use App\Domain\Payments\Services\CashPayoutService;
use App\Domain\Payments\Services\CashShiftService;
use App\Domain\Payments\Services\CashShiftSettings;
use App\Livewire\Admin\Finance\CashIndex;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;

/**
 * Petty cash on the cash page (docs/guides/ASSEMBLY_SALES_AND_PETTY_CASH.md): the "Pay out cash" window of an open shift, the list of what was paid out in
 * that shift and the reversal of a payout. The rules are in {@see CashPayoutService}; this only asks, checks the person's rights at the branch again, and
 * shows. Used by {@see CashIndex}, which owns the window state (`dialog`, `showDialog`, `target`).
 */
trait HandlesPettyCash
{
    use WithFileUploads;

    /** What is typed in the window. `key` is one per opening of the window: a second tap on "Pay out" answers with the payout that was already recorded. */
    public array $payout = ['amount' => '', 'reason' => '', 'note' => '', 'approver' => '', 'key' => ''];

    /** The receipt photo, once chosen (uploaded to a temporary place until the payout is recorded). */
    public $receipt = null;

    /** The payout whose "Reverse" row is open (it asks for a reason first). */
    #[Locked]
    public string $voidId = '';

    public string $voidReason = '';

    public function openPayoutDialog(string $shiftId): void
    {
        $shift = CashShift::findOrFail($shiftId);
        $this->authorizeBranch('payments.edit', $shift->branch_id);
        if (! $shift->isOpen()) {
            $this->toast(__('errors.cash_payout_shift_closed'), 'error');

            return;
        }
        $this->dialog = 'payout';
        $this->target = $shift->id;
        $this->freshPayoutForm();
        $this->showDialog = true;
    }

    private function freshPayoutForm(): void
    {
        $this->payout = ['amount' => '', 'reason' => '', 'note' => '', 'approver' => '', 'key' => (string) Str::uuid()];
        $this->receipt = null;
        $this->voidId = '';
        $this->voidReason = '';
        $this->resetValidation();
    }

    /** Pays the money out and keeps the window open on the list (the cashier often has two receipts), so it returns false even when it worked. */
    private function payOut(CashPayoutService $payouts, User $user): bool
    {
        $shift = CashShift::findOrFail($this->target);
        $this->authorizeBranch('payments.edit', $shift->branch_id);
        $this->validate([
            'payout.amount' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'payout.reason' => ['required', Rule::in(CashPayoutService::REASONS)],
            'payout.note' => 'nullable|string|max:300',
            'payout.approver' => 'nullable|uuid',
            'receipt' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:'.CashPayoutService::RECEIPT_MAX_KB,
        ], [], ['payout.amount' => __('petty_cash.amount'), 'payout.reason' => __('petty_cash.reason'), 'payout.note' => __('petty_cash.note'), 'receipt' => __('petty_cash.receipt')]);
        $p = $this->attempt(fn () => $payouts->record($user, $shift, (string) $this->payout['amount'], (string) $this->payout['reason'], $this->payout['note'] ?: null, $this->payout['approver'] ?: null, $this->receipt, $this->payout['key'] ?: null));
        if ($p instanceof CashPayout) {
            $this->toast(__('petty_cash.recorded_ok', ['amount' => Money::of((string) $p->amount)->format()]));
            $this->freshPayoutForm();
        }

        return false;
    }

    public function startVoid(string $payoutId): void
    {
        $payout = CashPayout::findOrFail($payoutId);
        $this->authorizeBranch('payments.edit', $payout->branch_id);
        $this->voidId = $payout->id;
        $this->voidReason = '';
        $this->resetValidation('voidReason');
    }

    public function cancelVoid(): void
    {
        $this->voidId = '';
        $this->voidReason = '';
    }

    public function voidPayout(CashPayoutService $payouts): void
    {
        if ($this->voidId === '') {
            return; // the reversal was already done (a second tap) or cancelled
        }
        $payout = CashPayout::findOrFail($this->voidId);
        $this->authorizeBranch('payments.edit', $payout->branch_id);
        $this->validate(['voidReason' => 'required|string|min:3|max:300'], [], ['voidReason' => __('petty_cash.void_reason')]);
        if ($this->attempt(fn () => $payouts->void(auth()->user(), $payout, $this->voidReason), __('petty_cash.voided_ok'))) {
            $this->cancelVoid();
        }
    }

    /**
     * What the "Pay out cash" window needs: the shift, the cash the drawer should hold, the payouts so far (the newest first, voided ones marked), the people
     * who may approve and whether the typed amount needs one.
     *
     * @return array<string, mixed>|null
     */
    private function payoutDialogData(CashShiftService $shifts, CashPayoutService $payouts, CashShiftSettings $settings): ?array
    {
        if ($this->dialog !== 'payout' || ! $this->target) {
            return null;
        }
        $shift = CashShift::query()->with('branch:id,code,name_en,name_ar')->find($this->target);
        if ($shift === null || ! $shift->isOpen()) {
            return null;
        }
        $typed = trim((string) ($this->payout['amount'] ?? ''));
        $amount = preg_match('/^\d{1,7}(\.\d{1,2})?$/', $typed) === 1 ? Money::of($typed) : null;
        $list = CashPayout::query()->where('cash_shift_id', $shift->id)->with(['payer:id,name', 'approver:id,name', 'voider:id,name'])->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get();
        $drawer = $shifts->figures($shift)['expected'];

        return ['shift' => $shift, 'drawer' => $drawer, 'list' => $list, 'limit' => $settings->payoutApprovalAbove(),
            'needsApprover' => $amount !== null && $payouts->needsApproval($amount), 'tooBig' => $amount !== null && $amount->greaterThan($drawer),
            'approvers' => $this->approverChoices($payouts, $shift), 'canVoidApproved' => $this->can('payments.override', $shift->branch_id)];
    }

    /** @return Collection<int, User> */
    private function approverChoices(CashPayoutService $payouts, CashShift $shift): Collection
    {
        return $payouts->approvers($shift->branch_id, (string) auth()->id());
    }
}
