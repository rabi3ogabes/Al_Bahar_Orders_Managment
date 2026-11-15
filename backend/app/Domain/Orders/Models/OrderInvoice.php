<?php

namespace App\Domain\Orders\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One revision of an order's invoice. The first one is issued when the invoice is first needed; a staff adjustment of the items issues the next
 * (same number, revision + 1). The latest revision is the invoice; older ones are kept for audit.
 *
 * @property string $id
 * @property string $order_id
 * @property string $number
 * @property int $revision
 * @property array<string, mixed> $snapshot
 * @property \Carbon\CarbonImmutable $issued_at
 */
class OrderInvoice extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'array', 'issued_at' => 'immutable_datetime', 'revision' => 'integer'];

    public function isRevised(): bool
    {
        return $this->revision > 1;
    }

    /** The name the PDF is downloaded under: the invoice number, plus the revision once there is more than one. */
    public function fileName(): string
    {
        return $this->number.($this->isRevised() ? '-rev'.$this->revision : '').'.pdf';
    }
}
