<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Domain\Orders\Models\OrderRevision;
use App\Domain\Orders\Services\OrderAdjustmentService;
use App\Http\Requests\Api\V1\ApiRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/admin/fulfillments/{id}/adjustments (docs/api/ORDER_ADJUSTMENTS.md). Only the SHAPE is checked here; whether the change makes sense for the
 * order as it is now (no_change, quantity_exceeds, duplicate_item, substitute_unavailable, item_not_in_fulfillment) is the service's call, under the order's lock.
 */
class AdjustOrderItemsRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'changes' => ['required', 'array', 'min:1', 'max:'.OrderAdjustmentService::MAX_CHANGES],
            'changes.*' => ['required', 'array:order_item_id,action,new_quantity,variant_id,quantity'],
            'changes.*.order_item_id' => ['required', 'uuid'],
            'changes.*.action' => ['required', Rule::in(['reduce', 'remove', 'replace'])],
            // A negative or too-large quantity is the service's quantity_exceeds, not a generic validation error.
            'changes.*.new_quantity' => ['required_if:changes.*.action,reduce', 'nullable', 'integer', 'max:1000000'],
            'changes.*.variant_id' => ['required_if:changes.*.action,replace', 'nullable', 'uuid'],
            'changes.*.quantity' => ['required_if:changes.*.action,replace', 'nullable', 'integer', 'max:1000000'],
            'reason_code' => ['required', Rule::in(OrderRevision::REASONS)],
            'note' => ['nullable', 'string', 'max:500'],
            'preview' => ['nullable', 'boolean'],
        ];
    }

    /** The changes as the service takes them: only the fields their action uses, as integers. @return list<array<string, mixed>> */
    public function changes(): array
    {
        return collect($this->validated('changes'))->map(fn (array $c) => match ($c['action']) {
            'reduce' => ['order_item_id' => $c['order_item_id'], 'action' => 'reduce', 'new_quantity' => (int) $c['new_quantity']],
            'replace' => ['order_item_id' => $c['order_item_id'], 'action' => 'replace', 'variant_id' => $c['variant_id'], 'quantity' => (int) $c['quantity']],
            default => ['order_item_id' => $c['order_item_id'], 'action' => 'remove'],
        })->values()->all();
    }
}
