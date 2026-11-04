<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Api\V1\ApiRequest;
use App\Rules\PhoneNumber;

class RequestOtpRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', new PhoneNumber],
            'purpose' => ['sometimes', 'in:login,password_reset'],
        ];
    }
}
