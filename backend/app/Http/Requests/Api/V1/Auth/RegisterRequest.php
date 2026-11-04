<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Api\V1\ApiRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'registration_token' => ['required', 'string'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:191', 'unique:users,email'],
            'password' => ['nullable', 'confirmed', Password::min(8)->letters()->numbers()],
            'locale' => ['nullable', 'in:en,ar'],
            'referral_code' => ['nullable', 'string', 'max:16'],
            'marketing_consent' => ['nullable', 'boolean'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'in:android,ios,web'],
        ];
    }
}
