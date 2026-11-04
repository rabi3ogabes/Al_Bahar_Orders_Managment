<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Shared\HasTranslations;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductWarranty extends Model
{
    use HasTranslations, HasUuids;

    protected $guarded = ['id'];
}
