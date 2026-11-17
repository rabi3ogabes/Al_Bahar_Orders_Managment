<?php

namespace App\Domain\Checkout\Data;

use App\Support\Money;

/**
 * The extra delivery fee one product adds to one fulfillment's delivery fee (docs/api/CHECKOUT_FEES_V2.md section 3):
 * the product, how it is charged, how many units of it the fulfillment carries, the fee per unit and the amount charged.
 */
final class DeliveryExtra
{
    public function __construct(
        public readonly string $productId,
        public readonly string $branchId,
        public readonly string $nameEn,
        public readonly string $nameAr,
        public readonly string $mode,
        public readonly int $quantity,
        public readonly Money $feeEach,
        public readonly Money $amount,
    ) {}

    /** @return array{product_id: string, name: string, mode: string, quantity: int, fee_each: array<string, string>, amount: array<string, string>, branch_id: string} */
    public function toArray(?string $locale = null): array
    {
        return [
            'product_id' => $this->productId, 'name' => ($locale ?? app()->getLocale()) === 'ar' ? $this->nameAr : $this->nameEn, 'mode' => $this->mode,
            'quantity' => $this->quantity, 'fee_each' => $this->feeEach->jsonSerialize(), 'amount' => $this->amount->jsonSerialize(), 'branch_id' => $this->branchId,
        ];
    }

    /** What an order stores (plain decimal strings, both languages), so the order page and the invoice never read the live catalog. */
    public function toStored(): array
    {
        return [
            'branch_id' => $this->branchId, 'product_id' => $this->productId, 'name_en' => $this->nameEn, 'name_ar' => $this->nameAr, 'mode' => $this->mode,
            'quantity' => $this->quantity, 'fee_each' => $this->feeEach->toDecimal(), 'amount' => $this->amount->toDecimal(),
        ];
    }
}
