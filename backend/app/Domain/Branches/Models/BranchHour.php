<?php

namespace App\Domain\Branches\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BranchHour extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['is_closed' => 'boolean', 'day_of_week' => 'integer'];
}
