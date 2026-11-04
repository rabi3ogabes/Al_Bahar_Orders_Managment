<?php

namespace App\Domain\Identity\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Deactivated = 'deactivated';
    case Deleted = 'deleted';
}
