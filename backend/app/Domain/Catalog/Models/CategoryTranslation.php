<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CategoryTranslation extends Model
{
    use HasUuids;

    protected $guarded = ['id'];
}
