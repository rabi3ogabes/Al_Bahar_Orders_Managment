<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Operations\Services\AttentionFeed;
use App\Domain\Operations\Support\AttentionItem;
use App\Domain\Operations\Support\AttentionList;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "Needs attention" for the branch staff app (prefix /api/v1/admin/dispatch): the top of the same inbox the dashboard shows, for the signed-in person,
 * with the same scoping and permissions (it is {@see AttentionFeed}, not a copy of it). Every line says where the app should take the person.
 *
 * @tags Dispatch
 */
class StaffAttentionController extends ApiController
{
    /** The most lines one answer carries; `counts` always covers the whole inbox. */
    public const LIMIT = 20;

    /** What a line asks the app to do: open the order's card, pick a driver, give a new pass; or nothing in the app (the dashboard handles it). */
    private const TARGETS = [
        'unaccepted' => 'open_order', 'stuck' => 'open_order', 'delivery_failed' => 'open_order', 'cancellation' => 'open_order',
        'undispatched' => 'assign_driver', 'driver_silent' => 'assign_driver',
        'pickup_waiting' => 'open_order', // becomes reissue_pass when the person may and the pass can be re-issued
    ];

    /** The lines that point at a branch part: their id is "{category}:{part id}". */
    private const PART_KINDS = ['unaccepted', 'stuck', 'undispatched', 'driver_silent', 'delivery_failed', 'pickup_waiting'];

    public function index(Request $request, AttentionFeed $feed): JsonResponse
    {
        $branchId = $request->validate(['branch_id' => 'nullable|uuid'])['branch_id'] ?? null;
        $list = $feed->for($this->user(), $branchId);
        if ($branchId !== null && ! in_array($branchId, array_column($list->branches, 'id'), true)) {
            // A branch the person has nothing to do with: nothing, never "everything" (the feed ignores a branch it does not know).
            $list = new AttentionList(collect(), false, $list->branches);
        }

        return response()->json(['data' => [
            'items' => $list->top(self::LIMIT)->map(fn (AttentionItem $i) => $this->row($i))->values(),
            'counts' => $list->counts() + ['by_kind' => $list->items->countBy('category')->all()],
            'truncated' => $list->truncated,
            'branch_id' => $branchId,
        ]]);
    }

    /** @return array<string, mixed> */
    private function row(AttentionItem $i): array
    {
        $part = in_array($i->category, self::PART_KINDS, true) ? Str::after($i->id, ':') : null;
        $target = self::TARGETS[$i->category] ?? 'dashboard';
        if ($i->category === 'pickup_waiting' && ($i->action['type'] ?? null) === 'reissue') {
            $target = 'reissue_pass';
        }

        return [
            'id' => $i->id, 'kind' => $i->category, 'target' => $target, 'in_app' => $target !== 'dashboard',
            'subject' => $i->subject, 'subject_is_number' => $i->subjectIsNumber, 'label' => $i->label, 'detail' => $i->detail, 'note' => $i->note,
            'state' => $i->state, 'age_minutes' => $i->age?->ageMinutes ?? 0, 'age_label' => $i->age?->label,
            'branch_id' => $i->branchId, 'branch_code' => $i->branchCode,
            'order_id' => $i->action['order'] ?? null, 'fulfillment_id' => $part,
            'mine' => $i->assignedTo !== null && $i->assignedTo === $this->user()->id,
            'call' => $i->call,
            'hint' => $target === 'dashboard' ? __('staff_app_api.attention.dashboard_only') : null,
        ];
    }
}
