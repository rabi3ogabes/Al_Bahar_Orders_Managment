<?php

namespace App\Http\Resources\V1;

use App\Domain\Identity\Models\User;
use App\Support\BusinessTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'phone_verified' => $this->phone_verified_at !== null,
            'locale' => $this->locale,
            'avatar_url' => $this->avatar_path ? \Storage::disk(config('platform.media.disk'))->url($this->avatar_path) : null,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'gender' => $this->gender,
            'referral_code' => $this->referral_code,
            'marketing_consent' => $this->marketing_consent_at !== null,
            'has_password' => $this->password !== null,
            'created_at' => BusinessTime::iso($this->created_at),
        ];
    }
}
