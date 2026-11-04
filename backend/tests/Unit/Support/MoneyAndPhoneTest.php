<?php

use App\Support\Money;
use App\Support\Phone;
use App\Support\SearchNormalizer;

it('does exact decimal money arithmetic', function () {
    expect(Money::of('0.10')->plus(Money::of('0.20'))->toDecimal())->toBe('0.30')
        ->and(Money::of('199.99')->times(3)->toDecimal())->toBe('599.97')
        ->and(Money::of('100.00')->percent('12.5')->toDecimal())->toBe('12.50')
        ->and(Money::of('10.005')->toDecimal())->toBe('10.01')
        ->and(Money::of('-5.50')->toDecimal())->toBe('-5.50');
});

it('allocates money without losing a dirham', function () {
    $parts = Money::of('100.00')->allocate([1, 1, 1]);
    expect(array_sum(array_map(fn ($m) => $m->minor, $parts)))->toBe(10000)
        ->and($parts[0]->toDecimal())->toBe('33.34');
});

it('normalises Qatar mobile numbers including Arabic digits', function () {
    expect(Phone::normalize('5512 3456'))->toBe('+97455123456')
        ->and(Phone::normalize('+974 6611-2233'))->toBe('+97466112233')
        ->and(Phone::normalize('00974 33112233'))->toBe('+97433112233')
        ->and(Phone::normalize('٥٥١٢٣٤٥٦'))->toBe('+97455123456')
        ->and(Phone::normalize('44123456'))->toBeNull() // landline
        ->and(Phone::normalize('+966501234567'))->toBeNull(); // not enabled
});

it('folds Arabic letter variants for search', function () {
    expect(SearchNormalizer::normalize('أريكة مُريحة'))->toBe(SearchNormalizer::normalize('اريكه مريحه'))
        ->and(SearchNormalizer::normalize('Sofa-Bed 3 Seater'))->toBe('sofa bed 3 seater');
});
