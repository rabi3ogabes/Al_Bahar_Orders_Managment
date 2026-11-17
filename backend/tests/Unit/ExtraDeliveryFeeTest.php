<?php

use App\Domain\Checkout\Services\ExtraDeliveryFee;
use App\Support\Money;

// The money rule of the extra delivery fee per product (docs/api/CHECKOUT_FEES_V2.md section 3), without a database.

it('charges per unit for every unit and per line once, whatever the quantity', function (string $mode, int $quantity, string $expected) {
    expect(ExtraDeliveryFee::amount(Money::of('50.00'), $mode, $quantity)->toDecimal())->toBe($expected);
})->with([
    'per unit, 1' => [ExtraDeliveryFee::PER_UNIT, 1, '50.00'],
    'per unit, 2' => [ExtraDeliveryFee::PER_UNIT, 2, '100.00'],
    'per unit, 5' => [ExtraDeliveryFee::PER_UNIT, 5, '250.00'],
    'per unit, 50 (the largest line)' => [ExtraDeliveryFee::PER_UNIT, 50, '2500.00'],
    'per line, 1' => [ExtraDeliveryFee::PER_LINE, 1, '50.00'],
    'per line, 2' => [ExtraDeliveryFee::PER_LINE, 2, '50.00'],
    'per line, 5' => [ExtraDeliveryFee::PER_LINE, 5, '50.00'],
    'per line, 50' => [ExtraDeliveryFee::PER_LINE, 50, '50.00'],
]);

it('charges nothing for no units or no fee', function (string $mode) {
    expect(ExtraDeliveryFee::amount(Money::of('50.00'), $mode, 0)->isZero())->toBeTrue()
        ->and(ExtraDeliveryFee::amount(Money::of('50.00'), $mode, -3)->isZero())->toBeTrue()
        ->and(ExtraDeliveryFee::amount(Money::zero(), $mode, 7)->isZero())->toBeTrue();
})->with([ExtraDeliveryFee::PER_UNIT, ExtraDeliveryFee::PER_LINE]);

it('works in whole dirham, never with floats', function () {
    // 33.33 x 3 = 99.99 exactly; 0.01 x 7 = 0.07 (a float would drift); the biggest fee x the largest line is exact too.
    expect(ExtraDeliveryFee::amount(Money::of('33.33'), ExtraDeliveryFee::PER_UNIT, 3)->toDecimal())->toBe('99.99')
        ->and(ExtraDeliveryFee::amount(Money::of('0.01'), ExtraDeliveryFee::PER_UNIT, 7)->toDecimal())->toBe('0.07')
        ->and(ExtraDeliveryFee::amount(Money::of((string) ExtraDeliveryFee::MAX), ExtraDeliveryFee::PER_UNIT, 50)->toDecimal())->toBe('5000000.00');
});

it('knows exactly two modes', function () {
    expect(ExtraDeliveryFee::MODES)->toBe(['per_unit', 'per_line'])->and(ExtraDeliveryFee::MAX)->toBe(100000);
});
