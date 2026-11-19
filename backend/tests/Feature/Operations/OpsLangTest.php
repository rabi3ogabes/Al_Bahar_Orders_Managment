<?php

use Illuminate\Support\Arr;

/** The English and Arabic words of Live Operations stay in step: same keys, same placeholders, nothing empty, nothing left in English. */
it('has the same keys in English and Arabic', function () {
    $en = Arr::dot(require base_path('lang/en/ops_live.php'));
    $ar = Arr::dot(require base_path('lang/ar/ops_live.php'));
    expect(array_diff_key($en, $ar))->toBe([])->and(array_diff_key($ar, $en))->toBe([]);
});

it('uses the same placeholders in both languages and leaves no text empty', function () {
    $en = Arr::dot(require base_path('lang/en/ops_live.php'));
    $ar = Arr::dot(require base_path('lang/ar/ops_live.php'));
    $placeholders = fn (string $text) => collect(preg_match_all('/:([a-z]+)/', $text, $m) ? $m[1] : [])->unique()->sort()->values()->all();
    foreach ($en as $key => $text) {
        expect(trim($text))->not->toBe('')->and(trim($ar[$key]))->not->toBe('', "ar {$key} is empty")
            ->and($placeholders($ar[$key]))->toBe($placeholders($text), "placeholders of {$key} differ");
    }
});

it('writes the Arabic in Arabic', function () {
    $ar = Arr::dot(require base_path('lang/ar/ops_live.php'));
    foreach ($ar as $key => $text) {
        expect(preg_match('/\p{Arabic}/u', $text))->toBe(1, "ar {$key} has no Arabic: {$text}");
    }
});
