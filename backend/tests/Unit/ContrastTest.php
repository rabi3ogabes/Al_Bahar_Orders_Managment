<?php

use App\Support\Contrast;

it('measures luminance and contrast the WCAG way', function () {
    expect(Contrast::luminance('#000000'))->toBe(0.0)
        ->and(Contrast::luminance('#FFFFFF'))->toEqualWithDelta(1.0, 1e-9)
        ->and(Contrast::ratio('#000000', '#FFFFFF'))->toEqualWithDelta(21.0, 1e-9)
        ->and(Contrast::ratio('#FFFFFF', '#000000'))->toEqualWithDelta(21.0, 1e-9)
        ->and(Contrast::ratio('#336699', '#336699'))->toEqualWithDelta(1.0, 1e-9);
    // The classic AA boundary: #767676 on white is 4.54:1, #777777 is 4.48:1.
    expect(Contrast::ratio('#767676', '#FFFFFF'))->toEqualWithDelta(4.54, 0.01)->and(Contrast::meets('#767676', '#FFFFFF'))->toBeTrue()
        ->and(Contrast::ratio('#777777', '#FFFFFF'))->toEqualWithDelta(4.48, 0.01)->and(Contrast::meets('#777777', '#FFFFFF'))->toBeFalse();
});

it('is case blind and knows what a colour is', function () {
    expect(Contrast::ratio('#abcdef', '#ABCDEF'))->toEqualWithDelta(1.0, 1e-9)
        ->and(Contrast::isHex('#A1b2C3'))->toBeTrue();
    foreach (['A1B2C3', '#abc', '#12345G', '#1234567', '', null, 12, ['#000000']] as $bad) {
        expect(Contrast::isHex($bad))->toBeFalse();
    }
    expect(fn () => Contrast::luminance('red'))->toThrow(InvalidArgumentException::class);
});

it('mixes two colours', function () {
    expect(Contrast::mix('#000000', '#FFFFFF', 0.5))->toBe('#808080')
        ->and(Contrast::mix('#8A1538', '#FFFFFF', 0.0))->toBe('#8A1538')
        ->and(Contrast::mix('#8A1538', '#FFFFFF', 1.0))->toBe('#FFFFFF')
        ->and(Contrast::mix('#8A1538', '#FFFFFF', 7.0))->toBe('#FFFFFF') // clamped
        ->and(Contrast::mix('#8a1538', '#ffffff', 0.4))->toBe('#B97388');
});
