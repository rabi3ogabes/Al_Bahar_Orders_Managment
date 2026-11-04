<?php

namespace App\Domain\Branches\Models;

use Illuminate\Database\Eloquent\Model;

class BranchSetting extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['value' => 'array'];
}
