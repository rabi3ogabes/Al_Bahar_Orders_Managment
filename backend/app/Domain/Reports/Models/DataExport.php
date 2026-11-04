<?php

namespace App\Domain\Reports\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DataExport extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['filters' => 'array', 'completed_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
}
