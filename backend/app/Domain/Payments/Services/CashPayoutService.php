<?php

namespace App\Domain\Payments\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Payments\Models\CashPayout;
use App\Domain\Payments\Models\CashShift;
use App\Support\DomainException;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Petty cash: money a cashier pays out of the open drawer of a branch (docs/guides/ASSEMBLY_SALES_AND_PETTY_CASH.md).
 *
 *  - WHO: whoever may run the drawer (`payments.edit` at the branch, the same level as opening and closing the shift).
 *  - ONLY while the shift is open. A payout takes the shift row first (the lock receiving cash, banking and closing already take), so it is either inside the
 *    shift and its Z-report, or refused: it can never slip in after the close.
 *  - IT LOWERS the expected cash of the shift ({@see CashShiftService::figuresFor()}). A payout cannot be larger than the cash the drawer should hold.
 *  - ABOVE THE LIMIT (Settings > Cash, default QAR 100, "above" is strict) a second person must be named as the approver: someone who holds
 *    `payouts.approve` (the narrow right of the branch employee checklist) or `payments.override` at the branch and is not the cashier paying it out.
 *  - A DOUBLE TAP is harmless: the same `request_key` answers with the payout that already exists.
 *  - A MISTAKE is VOIDED, never deleted: with a reason, by whoever may run the drawer (a payout that needed an approver can only be voided by someone who may
 *    approve), only while the shift is open. A voided payout stops counting in the expected cash and stays on the Z-report, marked.
 * Every payout and every void is in the audit log. Staff names and amounts only: nothing here names a customer.
 */
final class CashPayoutService
{
    /** The short list a cashier chooses the reason from; `other` needs words of its own. */
    public const REASONS = ['supplies', 'transport', 'maintenance', 'cleaning', 'refreshments', 'other'];

    /** A receipt photo: these types, at most this big. */
    public const RECEIPT_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public const RECEIPT_MAX_KB = 5120;

    public function __construct(
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
        private readonly CashShiftSettings $settings,
        private readonly CashShiftService $shifts,
    ) {}

    /** Does a payout of this amount need an approver? Strictly above the limit. */
    public function needsApproval(Money $amount): bool
    {
        return $amount->greaterThan($this->settings->payoutApprovalAbove());
    }

    /**
     * The people who may approve a payout at the branch: they hold `payouts.approve` or `payments.override` there, are active, and are not the cashier.
     *
     * @return Collection<int, User>
     */
    public function approvers(string $branchId, string $exceptUserId): Collection
    {
        return User::query()->where('type', 'staff')->where('status', 'active')->where('id', '!=', $exceptUserId)
            ->where(fn ($q) => $q->where('is_super_admin', true)
                ->orWhereIn('id', DB::table('user_roles')->select('user_id')->where(fn ($r) => $r->where('branch_id', $branchId)->orWhereNull('branch_id'))))
            ->orderBy('name')->orderBy('id')->limit(200)->get(['id', 'name', 'type', 'status', 'is_super_admin'])
            ->filter(fn (User $u) => $this->mayApprove($u, $branchId))->values();
    }

    /** Who may stand behind a payout above the limit at a branch: the narrow `payouts.approve`, or the wider `payments.override` that has always been enough. */
    public function mayApprove(User $user, string $branchId): bool
    {
        return $this->access->allows($user, 'payouts.approve', $branchId) || $this->access->allows($user, 'payments.override', $branchId);
    }

    /**
     * Pays money out of the open drawer.
     *
     * @param  string  $amount  QAR, at most two decimals
     * @param  string|null  $requestKey  one per opening of the window: a second submit with it returns the payout that was already recorded
     */
    public function record(User $actor, CashShift $shift, string $amount, string $reason, ?string $note = null, ?string $approverId = null, ?UploadedFile $receipt = null, ?string $requestKey = null): CashPayout
    {
        if (! $this->access->allows($actor, 'payments.edit', $shift->branch_id)) {
            throw DomainException::make('branch_forbidden', 403);
        }
        $money = $this->amount($amount);
        if (! in_array($reason, self::REASONS, true)) {
            throw DomainException::make('cash_payout_reason_invalid', 422);
        }
        $note = $note !== null ? trim($note) : null;
        $note = $note === '' ? null : $note;
        if (($note !== null && mb_strlen($note) > 300) || ($reason === 'other' && ($note === null || mb_strlen($note) < 3))) {
            throw DomainException::make('cash_payout_note_required', 422);
        }
        $this->checkReceipt($receipt);
        $requestKey = $requestKey !== null && $requestKey !== '' ? mb_substr($requestKey, 0, 64) : null;

        $stored = null;
        try {
            return DB::transaction(function () use ($actor, $shift, $money, $reason, $note, $approverId, $receipt, $requestKey, &$stored) {
                $locked = CashShift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
                if ($requestKey !== null && ($done = CashPayout::query()->where('request_key', $requestKey)->first()) !== null) {
                    // The same window sent twice: the first one won, this one answers with it.
                    if ($done->cash_shift_id === $locked->id && $done->paid_by === $actor->id) {
                        return $done;
                    }

                    throw DomainException::make('cash_payout_duplicate', 409);
                }
                if (! $locked->isOpen()) {
                    throw DomainException::make('cash_payout_shift_closed', 409);
                }
                $drawer = $this->shifts->figures($locked)['expected'];
                if ($money->greaterThan($drawer)) {
                    throw DomainException::make('cash_payout_exceeds_drawer', 422, ['amount' => $money->format(), 'drawer' => $drawer->format()]);
                }
                $approver = $this->approver($actor, $locked->branch_id, $money, $approverId);
                $payout = CashPayout::create([
                    'cash_shift_id' => $locked->id, 'branch_id' => $locked->branch_id, 'request_key' => $requestKey, 'reason_code' => $reason, 'note' => $note,
                    'amount' => $money->toDecimal(), 'paid_by' => $actor->id, 'approved_by' => $approver?->id,
                ]);
                if ($receipt !== null) {
                    $stored = (string) $receipt->storeAs('cash-payouts/'.$locked->id, $payout->id.'.'.($receipt->guessExtension() ?: 'jpg'), self::disk());
                    $payout->update(['receipt_path' => $stored]);
                }
                $this->audit->log('cash_shift.payout_recorded', $payout, [], ['amount' => $money->toDecimal(), 'reason' => $reason, 'note' => $note, 'approved_by' => $approver?->id,
                    'receipt' => $stored !== null, 'drawer_after' => $drawer->minus($money)->toDecimal()], branchId: $locked->branch_id, actor: $actor);

                return $payout;
            });
        } catch (Throwable $e) {
            if ($stored !== null) {
                Storage::disk(self::disk())->delete($stored); // the payout was not recorded: its photo must not stay behind
            }

            throw $e;
        }
    }

    /** Reverses a payout while its shift is open. The same call twice by the same person with the same reason is one void. */
    public function void(User $actor, CashPayout $payout, string $reason): CashPayout
    {
        if (! $this->access->allows($actor, 'payments.edit', $payout->branch_id)) {
            throw DomainException::make('branch_forbidden', 403);
        }
        // A payout somebody else approved cannot be erased by the cashier alone.
        if ($payout->approved_by !== null && ! $this->mayApprove($actor, $payout->branch_id)) {
            throw DomainException::make('cash_payout_void_needs_approver', 403);
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 300) {
            throw DomainException::make('cash_payout_void_reason_required', 422);
        }

        return DB::transaction(function () use ($actor, $payout, $reason) {
            $shift = CashShift::query()->whereKey($payout->cash_shift_id)->lockForUpdate()->firstOrFail();
            $row = CashPayout::query()->whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if ($row->isVoided()) {
                if ($row->voided_by === $actor->id && $row->void_reason === $reason) {
                    return $row;
                }

                throw DomainException::make('cash_payout_already_voided', 409);
            }
            if (! $shift->isOpen()) {
                throw DomainException::make('cash_payout_shift_closed', 409);
            }
            $row->update(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason]);
            $this->audit->log('cash_shift.payout_voided', $row, ['voided' => false], ['voided' => true, 'amount' => $row->amount, 'reason' => $reason], branchId: $row->branch_id, actor: $actor);

            return $row;
        });
    }

    /** The disk for receipt photos: private, never the public one. */
    public static function disk(): string
    {
        $disk = (string) config('platform.media.disk');

        return $disk === 'public' ? 'local' : $disk;
    }

    private function amount(string $amount): Money
    {
        $amount = trim($amount);
        if (preg_match('/^\d{1,7}(\.\d{1,2})?$/', $amount) !== 1 || ! ($money = Money::of($amount))->isPositive()) {
            throw DomainException::make('cash_payout_amount_invalid', 422);
        }

        return $money;
    }

    /** The named second person, when the amount needs one (or one was offered): holds `payouts.approve` or `payments.override` at the branch, active, and is not the cashier. */
    private function approver(User $actor, string $branchId, Money $amount, ?string $approverId): ?User
    {
        if ($approverId === null || $approverId === '') {
            if ($this->needsApproval($amount)) {
                throw DomainException::make('cash_payout_approver_required', 422, ['amount' => $this->settings->payoutApprovalAbove()->format()]);
            }

            return null;
        }
        $approver = Str::isUuid($approverId) ? User::query()->find($approverId) : null;
        if ($approver === null || $approver->id === $actor->id || ! $this->mayApprove($approver, $branchId)) {
            throw DomainException::make('cash_payout_approver_invalid', 422);
        }

        return $approver;
    }

    private function checkReceipt(?UploadedFile $receipt): void
    {
        if ($receipt === null) {
            return;
        }
        if (! $receipt->isValid() || ! in_array((string) $receipt->getMimeType(), self::RECEIPT_TYPES, true) || $receipt->getSize() > self::RECEIPT_MAX_KB * 1024) {
            throw DomainException::make('cash_payout_receipt_invalid', 422);
        }
    }
}
