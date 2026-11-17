<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Api\V1\ApiRequest;

class PasswordLoginRequest extends ApiRequest
{
    /**
     * One "e-mail or mobile number" box: a value without an "@" cannot be an e-mail, so it is the mobile number
     * (a branch employee may have a number and no e-mail address).
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        if (is_string($email) && $email !== '' && ! str_contains($email, '@') && ! $this->filled('phone')) {
            $this->merge(['phone' => $email, 'email' => null]);
        }
        parent::prepareForValidation();
    }

    public function rules(): array
    {
        return [
            'phone' => ['required_without:email', 'nullable', 'string'],
            'email' => ['required_without:phone', 'nullable', 'email'],
            'password' => ['required', 'string', 'max:200'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'in:android,ios,web'],
            'two_factor_code' => ['nullable', 'string', 'max:32'],
        ];
    }
}
