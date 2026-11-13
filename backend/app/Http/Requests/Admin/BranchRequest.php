<?php

namespace App\Http\Requests\Admin;

use App\Support\Geo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Shared by the admin API and the Livewire branch form. */
class BranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @param  string  $prefix  the attribute prefix the rules are used under (a Livewire form validates "form.latitude"), so the latitude/longitude pair rules find each other */
    public static function rulesFor(?string $ignoreId = null, bool $partial = false, string $prefix = ''): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'code' => [$req, 'string', 'max:24', 'regex:/^[A-Z0-9\-]+$/', Rule::unique('branches', 'code')->ignore($ignoreId)],
            'name_en' => [$req, 'string', 'max:120'],
            'name_ar' => [$req, 'string', 'max:120'],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'description_ar' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:191'],
            'address_en' => ['nullable', 'string', 'max:255'],
            'address_ar' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:120'],
            'zone_number' => ['nullable', 'string', 'max:16'],
            // A map pin is a pair inside Qatar's bounding box.
            'latitude' => ['nullable', 'numeric', 'between:'.Geo::QATAR_BOUNDS[0].','.Geo::QATAR_BOUNDS[1], 'required_with:'.$prefix.'longitude'],
            'longitude' => ['nullable', 'numeric', 'between:'.Geo::QATAR_BOUNDS[2].','.Geo::QATAR_BOUNDS[3], 'required_with:'.$prefix.'latitude'],
            'opening_status' => ['sometimes', 'in:open,temporarily_closed'],
            'pickup_enabled' => ['sometimes', 'boolean'],
            'delivery_enabled' => ['sometimes', 'boolean'],
            'dispatch_mode' => ['sometimes', 'in:manual,auto'],
            'cod_enabled' => ['sometimes', 'boolean'],
            'pay_at_pickup_enabled' => ['sometimes', 'boolean'],
            'pay_at_pickup_tenders' => ['nullable', 'array'],
            'pay_at_pickup_tenders.*' => ['in:cash,card_terminal'],
            'cod_min_order' => ['nullable', 'numeric', 'min:0'],
            'cod_max_order' => ['nullable', 'numeric', 'min:0', 'gte:cod_min_order'],
            'pickup_preparation_minutes' => ['sometimes', 'integer', 'min:0', 'max:10080'],
            'is_fulfillment_hub' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:active,inactive'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function rules(): array
    {
        $branch = $this->route('branch');

        return self::rulesFor(is_object($branch) ? $branch->id : $branch, $this->isMethod('patch'));
    }
}
