<?php

namespace App\Domain\Branches\Models;

use App\Domain\Shared\HasTranslations;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BranchHoliday extends Model
{
    use HasTranslations, HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
}
