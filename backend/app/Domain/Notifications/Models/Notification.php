<?php

namespace App\Domain\Notifications\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** In-app notification centre entry. */
class Notification extends Model
{
    use HasUuids;

    protected $table = 'notifications';

    protected $guarded = ['id'];

    protected $casts = ['data' => 'array', 'read_at' => 'immutable_datetime'];
}
