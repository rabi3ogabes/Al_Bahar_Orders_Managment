<?php

namespace App\Domain\Settings\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['value' => 'array', 'is_secret' => 'boolean'];
}
