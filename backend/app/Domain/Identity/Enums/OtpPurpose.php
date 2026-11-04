<?php

namespace App\Domain\Identity\Enums;

enum OtpPurpose: string
{
    case Login = 'login';
    case Register = 'register';
    case PasswordReset = 'password_reset';
    case PhoneChange = 'phone_change';
}
