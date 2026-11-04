<?php

namespace App\Domain\Marketing\Models;

use App\Domain\Shared\HasTranslations;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CustomerGroup extends Model
{
    use HasTranslations, HasUuids;

    protected $guarded = ['id'];
}
