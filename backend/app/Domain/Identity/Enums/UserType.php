<?php

namespace App\Domain\Identity\Enums;

enum UserType: string
{
    case Customer = 'customer';
    case Staff = 'staff';
}
