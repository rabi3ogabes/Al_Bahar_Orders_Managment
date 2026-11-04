<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\OtpPurpose;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OtpVerification extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['code_hash'];

    protected $casts = [
        'purpose' => OtpPurpose::class,
        'expires_at' => 'immutable_datetime',
        'consumed_at' => 'immutable_datetime',
        'invalidated_at' => 'immutable_datetime',
    ];

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->invalidated_at === null
            && $this->expires_at->isFuture() && $this->attempts < $this->max_attempts;
    }
}
