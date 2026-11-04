<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceipt extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'goods_receipts';

    protected $guarded = ['id'];

    protected $casts = ['received_at' => 'immutable_datetime'];

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }
}
