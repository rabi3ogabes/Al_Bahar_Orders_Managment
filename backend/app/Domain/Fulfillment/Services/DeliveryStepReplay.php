<?php

namespace App\Domain\Fulfillment\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Identity\Models\User;
use App\Support\BusinessTime;
use App\Support\DomainException;
use App\Support\StepTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The driver's steps that the app may send later, from its outbox (accept, pickup, out-for-delivery, deliver, fail, assembly), made safe to send again
 * (docs/guides/OFFLINE_DRIVER_AND_STAFF_APPS.md):
 *
 *  - Idempotency-Key: the app makes one key when the driver presses and sends it on every retry. The first answer that worked is kept in
 *    `idempotency_keys` (scope `delivery_step`) in the SAME transaction as the step, so either both are saved or neither is: a retry after an answer
 *    that was lost on the way gets that first answer back (header `Idempotent-Replayed: true`) and nothing happens a second time. A step that was
 *    refused keeps nothing, so the driver can correct it and send it again with the same key. Two copies arriving together wait for each other on the
 *    key's row. The same key with different details is refused (422 `idempotency_conflict`) for an app that sends `acted_at` (the outbox never changes
 *    an item); an older app (no `acted_at`, up to build 90) keeps one key while the driver edits the form, so for it the step falls through to the
 *    rules by state it always had.
 *  - acted_at: when the driver pressed ({@see StepTime}); every time the step writes is that moment, and the audit log keeps the time it arrived
 *    (`delivery.step_sent_late`). Without it nothing changes.
 *
 * A request with neither header nor `acted_at` runs exactly as before.
 */
final class DeliveryStepReplay
{
    public const SCOPE = 'delivery_step';

    /** The steps the driver app may queue. */
    public const ACTIONS = ['accept', 'pickup', 'out-for-delivery', 'deliver', 'fail', 'assembly'];

    /** Days a step's key is kept: well past the day an outbox item may wait ({@see StepTime::MAX_AGE_HOURS}). */
    public const KEEP_DAYS = 7;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  callable(): Delivery  $step */
    public function run(Request $request, User $user, Delivery $delivery, string $action, ?string $key, callable $step): JsonResponse
    {
        $hash = $key === null ? null : $this->fingerprint($request, $delivery, $action);

        return DB::transaction(function () use ($request, $user, $action, $key, $hash, $step) {
            if ($key !== null) {
                $now = now();
                DB::table('idempotency_keys')->insertOrIgnore(['id' => (string) Str::uuid7(), 'user_id' => $user->id, 'scope' => self::SCOPE, 'key' => $key,
                    'request_hash' => $hash, 'created_at' => $now, 'updated_at' => $now]);
                $row = DB::table('idempotency_keys')->where('scope', self::SCOPE)->where('user_id', $user->id)->where('key', $key)->lockForUpdate()->first();
                if ($row !== null && $row->completed_at !== null) {
                    if ($row->request_hash === $hash) {
                        return response()->json(json_decode((string) $row->response_body, true), (int) $row->response_status, ['Idempotent-Replayed' => 'true']);
                    }
                    if ($request->filled('acted_at')) {
                        throw new DomainException('idempotency_conflict', __('offline_steps.errors.idempotency_conflict'), 422);
                    }
                    $key = null; // an older app retrying with edited details: today's rules by state decide, and the first answer stays the one kept
                }
            }
            $actedAt = StepTime::parse($request->input('acted_at'));
            $d = StepTime::during($actedAt, $step);
            if ($actedAt !== null) {
                $this->audit->log('delivery.step_sent_late', $d, [], ['step' => $action], ['acted_at' => BusinessTime::iso($actedAt), 'received_at' => BusinessTime::iso(CarbonImmutable::now())],
                    branchId: $d->branch_id, actor: $user);
            }
            $body = ['data' => $d];
            if ($key !== null) {
                DB::table('idempotency_keys')->where('scope', self::SCOPE)->where('user_id', $user->id)->where('key', $key)
                    ->update(['response_status' => 200, 'response_body' => json_encode($body), 'completed_at' => now(), 'updated_at' => now()]);
            }

            return response()->json($body);
        });
    }

    /** Removes the keys of steps older than {@see self::KEEP_DAYS} days, a bounded batch at a time. Returns how many went. */
    public function prune(): int
    {
        $before = now()->subDays(self::KEEP_DAYS);
        $total = 0;
        do {
            $ids = DB::table('idempotency_keys')->where('scope', self::SCOPE)->where('created_at', '<', $before)->orderBy('created_at')->limit(1000)->pluck('id');
            $total += $ids->isEmpty() ? 0 : DB::table('idempotency_keys')->whereIn('id', $ids)->delete();
        } while ($ids->count() === 1000);

        return $total;
    }

    /** What makes two sends "the same step": the job, the step, every field (in a stable order) and the content of every photo. */
    private function fingerprint(Request $request, Delivery $delivery, string $action): string
    {
        $files = [];
        foreach ($request->allFiles() as $name => $file) {
            $files[$name] = $file instanceof UploadedFile && $file->getRealPath() !== false ? hash_file('sha256', $file->getRealPath()) : null;
        }
        $input = $request->input();
        $input = is_array($input) ? $this->sorted($input) : [];
        ksort($files);

        return hash('sha256', (string) json_encode([$delivery->id, $action, $input, $files]));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function sorted(array $value): array
    {
        ksort($value);

        return array_map(fn ($v) => is_array($v) ? $this->sorted($v) : (is_scalar($v) || $v === null ? (string) $v : null), $value);
    }
}
