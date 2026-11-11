<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Support\BranchOf;
use App\Support\Redactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Records security-relevant actions with actor, entity, branch, request context and safe before/after values.
 * Values pass through Redactor so secrets, OTPs and card data are never persisted.
 */
class AuditLogger
{
    public function log(
        string $action,
        Model|string|null $subject = null,
        array $old = [],
        array $new = [],
        array $metadata = [],
        ?string $branchId = null,
        ?User $actor = null,
    ): AuditLog {
        $actor ??= Auth::user();
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        [$type, $id] = match (true) {
            $subject instanceof Model => [class_basename($subject), (string) $subject->getKey()],
            is_string($subject) => [$subject, null],
            default => [null, null],
        };
        $branchId ??= $subject instanceof Model ? BranchOf::model($subject) : null;

        return AuditLog::create([
            'actor_id' => $actor?->getKey(),
            'actor_type' => $actor ? $actor->type->value : 'system',
            'action' => $action,
            'auditable_type' => $type,
            'auditable_id' => $id,
            'branch_id' => $branchId,
            'old_values' => $old ? Redactor::redact($old) : null,
            'new_values' => $new ? Redactor::redact($new) : null,
            'metadata' => $metadata ? Redactor::redact($metadata) : null,
            'ip' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 512) : null,
            'request_id' => $request?->attributes->get('request_id'),
        ]);
    }

    /** Log a model change using its dirty attributes (call before save) or changes (after save). */
    public function logChanges(string $action, Model $model, array $metadata = [], array $only = []): ?AuditLog
    {
        $changes = $model->getChanges() ?: $model->getDirty();
        unset($changes['updated_at']);
        if ($only) {
            $changes = array_intersect_key($changes, array_flip($only));
        }
        if (! $changes && ! $model->wasRecentlyCreated) {
            return null;
        }
        $old = [];
        foreach (array_keys($changes) as $key) {
            $old[$key] = $model->getOriginal($key);
        }

        return $this->log($action, $model, $model->wasRecentlyCreated ? [] : $old, $changes, $metadata);
    }
}
