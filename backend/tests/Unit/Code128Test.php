<?php

use App\Support\Code128;

/**
 * Code 128 vectors. The module strings below were decoded back to their text with an independent decoder (zxing-cpp) when this test was
 * written, together with every printable character, every digit pair and every switch between sets, so they pin the encoder's output.
 */
it('encodes known texts to the exact bars', function (string $text, string $modules) {
    expect(Code128::modules($text))->toBe($modules);
})->with([
    'Hi (set B)' => ['Hi', '110100100001100010100010000110100100111101001100011101011'],
    '12 (set C)' => ['12', '1101001110010110011100100110011101100011101011'],
    'A' => ['A', '1101001000010100011000100010110001100011101011'],
    '13-digit EAN' => ['6291000000011', '110100111001111000101011110110110110110011001101100110011011001100110011011001011110111010011100110111011000101100011101011'],
]);

it('starts in set C for a number and finishes an odd one in set B', function () {
    expect(Code128::symbols('12345678')[0])->toBe(105)
        ->and(Code128::symbols('6291000000011'))->toBe([105, 62, 91, 0, 0, 0, 1, 100, 17, 58, 106]);
});

it('uses set C inside a code only for long digit runs (6, or 4 at the end)', function () {
    expect(Code128::symbols('AB1234'))->toContain(99)
        ->and(Code128::symbols('AB123'))->not->toContain(99)
        ->and(Code128::symbols('AB12345'))->toContain(99)
        ->and(Code128::symbols('AB12345X'))->not->toContain(99)
        ->and(Code128::symbols('AB123456X'))->toContain(99);
});

it('checksum is the weighted sum modulo 103', function () {
    // "Hi": 'H' = 72 - 32 = 40, 'i' = 105 - 32 = 73; (104 + 40*1 + 73*2) % 103 = 290 % 103 = 84
    expect(Code128::symbols('Hi'))->toBe([104, 40, 73, 84, 106])
        ->and(Code128::symbols('12'))->toBe([105, 12, 14, 106]);
});

it('every symbol pattern is 11 modules (6 elements) with an even bar total; the stop symbol is 13', function () {
    $patterns = (new ReflectionClassConstant(Code128::class, 'PATTERNS'))->getValue();
    expect($patterns)->toHaveCount(106)->and(array_unique($patterns))->toHaveCount(106);
    foreach ($patterns as $value => $pattern) {
        $widths = array_map('intval', str_split($pattern));
        expect(count($widths))->toBe(6, "symbol {$value}")
            ->and(array_sum($widths))->toBe(11, "symbol {$value} is 11 modules wide")
            ->and(($widths[0] + $widths[2] + $widths[4]) % 2)->toBe(0, "symbol {$value} has an even bar total");
    }
    $stop = (new ReflectionClassConstant(Code128::class, 'STOP'))->getValue();
    expect(array_sum(array_map('intval', str_split($stop))))->toBe(13);
    // start + one character + check + stop
    expect(strlen(Code128::modules('A')))->toBe(11 * 3 + 13);
});

it('only takes printable ASCII, 1 to 48 characters', function (string $text, bool $ok) {
    expect(Code128::supports($text))->toBe($ok);
})->with([
    ['SKU-1', true], ['6291000000011', true], ['a b', true], ['~', true], ['', false], ["tab\t", false], ['كنبة', false], ['é', false],
    [str_repeat('9', 48), true], [str_repeat('9', 49), false],
]);

it('refuses text it cannot draw', function () {
    expect(fn () => Code128::modules('كنبة'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Code128::svg(''))->toThrow(InvalidArgumentException::class);
});

it('draws an SVG with a quiet zone, escaped text and one path', function () {
    $svg = Code128::svg('A"B<1>', 40);

    expect($svg)->toStartWith('<svg')->and($svg)->toContain('viewBox="0 0 ')->and($svg)->toContain('aria-label="A&quot;B&lt;1&gt;"')
        ->and($svg)->not->toContain('<script')->and(substr_count($svg, '<path'))->toBe(1)->and($svg)->not->toContain('B<1>');
    preg_match('/viewBox="0 0 (\d+) 40"/', $svg, $m);
    expect((int) $m[1])->toBe(strlen(Code128::modules('A"B<1>')) + 20);
    // The first bar starts after the 10-module quiet zone.
    expect($svg)->toContain('d="M10 0');
});
