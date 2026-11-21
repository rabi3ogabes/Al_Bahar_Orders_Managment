<?php

use App\Domain\Search\Support\SearchGroup;
use App\Domain\Search\Support\SearchQuery;

/** How the global search reads what was typed: the order number, the phone, the words, and the limits. */
it('reads an order number from every way it is typed', function (string $typed, ?string $order) {
    expect(SearchQuery::from($typed)->order)->toBe($order);
})->with([
    'whole' => ['AB100123', '100123'],
    'lower case' => ['ab100123', '100123'],
    'hash' => ['#AB100123', '100123'],
    'dash' => ['ab-100123', '100123'],
    'spaces' => [' ab 100123 ', '100123'],
    'digits only' => ['100123', '100123'],
    'a piece' => ['0012', '0012'],
    'a branch part' => ['AB100123-1', '100123'],
    'a branch part of ten' => ['AB100123-12', '100123'],
    'Arabic digits' => ['AB١٠٠١٢٣', '100123'],
    'too short after AB' => ['AB10', null],
    'only AB' => ['AB', null],
    'two characters' => ['10', null],
    'symbols' => ["AB'||'0", null],
    'a word still counts as a possible staff-made number' => ['Ahmed', 'AHMED'],
]);

it('reads a Qatar phone from every way it is typed, and only four to eight digits of it', function (string $typed, ?string $phone, bool $country) {
    $q = SearchQuery::from($typed);
    expect($q->phone)->toBe($phone)->and($q->phoneHasCountryCode)->toBe($country);
})->with([
    'international' => ['+974 5512 3456', '55123456', true],
    'with 00' => ['0097455123456', '55123456', true],
    'country code only in front' => ['974 55123456', '55123456', true],
    'eight digits' => ['55123456', '55123456', false],
    'dashes' => ['5512-3456', '55123456', false],
    'brackets' => ['(+974) 55 12 34 56', '55123456', true],
    'Arabic digits' => ['٥٥١٢٣٤٥٦', '55123456', false],
    'Persian digits' => ['۵۵۱۲۳۴۵۶', '55123456', false],
    'four digits is a piece' => ['5512', '5512', false],
    'seven digits' => ['5512345', '5512345', false],
    'after the country code' => ['+974 5512', '5512', true],
    'three digits is too few' => ['551', null, false],
    'only the country code' => ['+974', null, false],
    'a whole number must start 3, 5, 6 or 7' => ['12345678', null, false],
    'nine digits is no Qatar number' => ['551234567', null, false],
    'letters make it a name' => ['hello 5512', null, false],
]);

it('cuts a long query, drops control characters and invalid text, and keeps the words', function () {
    expect(SearchQuery::from(str_repeat('x', 500))->raw)->toHaveLength(SearchQuery::MAX_LENGTH)
        ->and(mb_strlen(SearchQuery::from(str_repeat('فاطمة ', 200))->raw))->toBeLessThanOrEqual(SearchQuery::MAX_LENGTH)
        ->and(SearchQuery::from("a\x00b\x1fc\n d")->raw)->toBe('a b c d')
        ->and(SearchQuery::from("\xff\xfeabc")->raw)->toContain('abc')
        ->and(SearchQuery::from(['x'])->raw)->toBe('')
        ->and(SearchQuery::from(null)->raw)->toBe('')
        ->and(SearchQuery::from(' Velvet   SOFA ')->text)->toBe('velvet sofa')
        ->and(SearchQuery::from('كنبة')->text)->toBe('كنبه');          // the Arabic spelling is folded like the catalogue search folds it
});

it('looks in the tables from three characters with a letter or a digit among them, and at pages from two', function (string $typed, bool $tables, bool $pages) {
    $q = SearchQuery::from($typed);
    expect($q->searchesTables())->toBe($tables)->and($q->searchesCommands())->toBe($pages);
})->with([
    'nothing' => ['', false, false],
    'one letter' => ['a', false, false],
    'two letters' => ['ab', false, true],
    'three letters' => ['abc', true, true],
    'emoji only' => ['😀😀😀', false, false],
    'symbols only' => ['___', false, false],
    'Arabic' => ['نقد', true, true],
    'two digits' => ['12', false, true],
]);

it('makes LIKE characters plain', function () {
    expect(SearchQuery::contains('100%_\\'))->toBe('%100\\%\\_\\\\%')->and(SearchQuery::startsWith('a_b'))->toBe('a\\_b%');
});

it('keeps a number left to right inside a line of text', function () {
    expect(SearchGroup::ltr('+97455123456'))->toBe("\u{2066}+97455123456\u{2069}")->and(SearchGroup::ltr(null))->toBeNull()->and(SearchGroup::ltr(''))->toBe('');
});
