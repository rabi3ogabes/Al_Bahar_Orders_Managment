<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Api\V1\ApiRequest;
use App\Rules\PhoneNumber;

class VerifyOtpRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', new PhoneNumber],
            'code' => ['required', 'string', 'regex:/^\d{4,8}$/'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'in:android,ios,web'],
        ];
    }
}
