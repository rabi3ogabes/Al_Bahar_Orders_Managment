<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CatalogImport extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['errors' => 'array', 'completed_at' => 'immutable_datetime'];
}
