<?php

namespace App\Domain\Support\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    use HasUuids;

    protected $table = 'support_tickets';

    protected $guarded = ['id'];

    protected $casts = ['first_response_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];

    public function messages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SupportMessage::class)->orderBy('created_at');
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Identity\Models\User::class);
    }

    public function assignee(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Domain\Identity\Models\User::class, 'assigned_to');
    }
}
