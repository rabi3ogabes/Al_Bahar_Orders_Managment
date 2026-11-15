<?php

use App\Domain\Orders\Services\AdjustmentMath as M;
use App\Support\Money;

/** The money rules of an order adjustment, with no database: exact to the dirham, never more for the customer. */
$m = fn (string $v) => Money::of($v);

it('keeps the share of a discount that belongs to the units that stay, a fraction of a dirham going the customer\'s way', function () use ($m) {
    expect(M::keptShare($m('100.00'), 4, 1)->toDecimal())->toBe('25.00')
        ->and(M::keptShare($m('10.00'), 3, 2)->toDecimal())->toBe('6.67')
        ->and(M::keptShare($m('0.01'), 3, 1)->toDecimal())->toBe('0.01')
        ->and(M::keptShare($m('10.00'), 3, 0)->toDecimal())->toBe('0.00')
        ->and(M::keptShare($m('10.00'), 3, 3)->toDecimal())->toBe('10.00');
    // never less than the exact share, never a whole dirham more, never more than the whole discount
    foreach ([[1000, 7, 3], [1, 9, 4], [99999, 13, 12], [5, 2, 1], [0, 3, 1]] as [$amount, $from, $to]) {
        $kept = M::keptShare(Money::ofMinor($amount), $from, $to)->minor;
        expect($kept * $from)->toBeGreaterThanOrEqual($amount * $to)->and($kept * $from)->toBeLessThan($amount * $to + $from)->and($kept)->toBeLessThanOrEqual($amount);
    }
});

it('works a reduced line out again: same unit price, the discount share that stays, tax on what is left', function () use ($m) {
    $l = M::reduced($m('1000.00'), 3, 2, $m('300.00'), '5.000');
    expect($l['subtotal']->toDecimal())->toBe('2000.00')->and($l['discount']->toDecimal())->toBe('200.00')
        ->and($l['tax']->toDecimal())->toBe('90.00')->and($l['total']->toDecimal())->toBe('1890.00');
    // the discount can never be more than the line's own subtotal
    expect(M::line($m('10.00'), 1, $m('50.00'), '0')['discount']->toDecimal())->toBe('10.00');
    // a line with no units is all zeros
    $gone = M::line($m('10.00'), 0, $m('5.00'), '5');
    expect($gone['subtotal']->isZero() && $gone['discount']->isZero() && $gone['tax']->isZero() && $gone['total']->isZero())->toBeTrue();
});

it('never charges more per unit for a substitute than for the item it replaces', function () use ($m) {
    // cheaper: its own price; equal: the same; dearer: capped at the original
    expect(M::substituteUnitPrice($m('1000.00'), $m('600.00'))->toDecimal())->toBe('600.00')
        ->and(M::substituteUnitPrice($m('1000.00'), $m('1000.00'))->toDecimal())->toBe('1000.00')
        ->and(M::substituteUnitPrice($m('1000.00'), $m('1500.00'))->toDecimal())->toBe('1000.00');

    // 2 sofas at 1000 with a 10% discount (200): swapped for 2 of a 600 item. Net per unit: 900 → 540.
    $s = M::substitute($m('1000.00'), 2, $m('200.00'), $m('600.00'), 2, '0');
    expect($s['unit']->toDecimal())->toBe('600.00')->and($s['discount']->toDecimal())->toBe('120.00')->and($s['total']->toDecimal())->toBe('1080.00');
    // dearer substitute: capped, so the line costs exactly what it did
    $d = M::substitute($m('1000.00'), 2, $m('200.00'), $m('1500.00'), 2, '0');
    expect($d['total']->toDecimal())->toBe('1800.00');
});

it('keeps the net price of a substitute at or below the original for any prices, discounts and quantities', function () {
    mt_srand(7);
    for ($i = 0; $i < 400; $i++) {
        $unit = Money::ofMinor(mt_rand(1, 500000));
        $q = mt_rand(1, 20);
        $n = mt_rand(1, $q);
        $disc = Money::ofMinor(mt_rand(0, $unit->minor * $q));
        $cur = Money::ofMinor(mt_rand(1, 900000));
        $rate = ['0', '5', '12.5'][mt_rand(0, 2)];
        $orig = M::line($unit, $q, $disc, $rate);
        $sub = M::substitute($unit, $q, $disc, $cur, $n, $rate);
        // the unit price is never above the original's, the net of a unit (before tax) is never above the original's, and the line never costs more
        $netOrig = $orig['subtotal']->minus($orig['discount'])->minor;
        $netSub = $sub['subtotal']->minus($sub['discount'])->minor;
        expect($sub['unit']->minor)->toBeLessThanOrEqual($unit->minor)
            ->and($netSub * $q)->toBeLessThanOrEqual($netOrig * $n)
            ->and($sub['total']->minor)->toBeLessThanOrEqual($orig['total']->minor);
        // and a reduced line never costs more than the line it came from
        $to = mt_rand(0, $q - 1);
        $red = $to === 0 ? M::line($unit, 0, Money::zero(), $rate) : M::reduced($unit, $q, $to, $disc, $rate);
        expect($red['total']->minor)->toBeLessThanOrEqual($orig['total']->minor);
    }
});

it('works the order total out the way checkout does, never below zero, cash-on-delivery fee after the floor', function () use ($m) {
    $parts = ['subtotal' => $m('2250.00'), 'item_discount' => $m('0'), 'delivery_fee' => $m('15.00'), 'delivery_discount' => $m('0'), 'service_fee' => $m('0'),
        'cod_fee' => $m('10.00'), 'tax' => $m('0'), 'loyalty' => $m('0')];
    expect(M::grandTotal($parts)->toDecimal())->toBe('2275.00');
    // loyalty points worth more than everything else: the total is the cash-on-delivery fee only, never negative
    expect(M::grandTotal(['subtotal' => $m('100.00'), 'loyalty' => $m('500.00')] + $parts)->toDecimal())->toBe('10.00');
    // free delivery removes the fee through the discount
    expect(M::grandTotal(['delivery_discount' => $m('15.00')] + $parts)->toDecimal())->toBe('2260.00');
});

it('hands back loyalty points a smaller order cannot absorb, keeping every whole point that still fits', function () use ($m) {
    // fits: nothing changes
    expect(M::loyaltyThatFits(6000, $m('300.00'), $m('400.00')))->toMatchArray(['points' => 6000, 'returned' => 0]);
    expect(M::loyaltyThatFits(6000, $m('300.00'), $m('300.00')))->toMatchArray(['points' => 6000, 'returned' => 0]);
    // the order is now worth 265: 5300 points (265.00) stay, 700 go back
    $fit = M::loyaltyThatFits(6000, $m('300.00'), $m('265.00'));
    expect($fit['points'])->toBe(5300)->and($fit['discount']->toDecimal())->toBe('265.00')->and($fit['returned'])->toBe(700);
    // nothing left to pay: every point goes back
    expect(M::loyaltyThatFits(6000, $m('300.00'), $m('0.00')))->toMatchArray(['points' => 0, 'returned' => 6000]);
    expect(M::loyaltyThatFits(0, $m('0.00'), $m('0.00')))->toMatchArray(['points' => 0, 'returned' => 0]);
});
