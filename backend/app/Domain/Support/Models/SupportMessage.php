<?php

namespace App\Domain\Support\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SupportMessage extends Model
{
    use HasUuids;

    protected $table = 'support_messages';

    protected $guarded = ['id'];

    protected $casts = ['attachments' => 'array', 'is_internal' => 'boolean'];

    public function author(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Identity\Models\User::class, 'author_id');
    }
}
