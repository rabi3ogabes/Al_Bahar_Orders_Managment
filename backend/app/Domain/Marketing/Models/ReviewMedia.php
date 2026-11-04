<?php

namespace App\Domain\Marketing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ReviewMedia extends Model
{
    use HasUuids;

    protected $table = 'review_media';

    protected $guarded = ['id'];
}
