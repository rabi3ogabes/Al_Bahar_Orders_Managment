<?php

use App\Domain\Orders\Printing\PrintText;
use App\Support\ArabicShaper;

/** The text of the printed documents: Arabic is cut into lines in reading order and shaped one line at a time, numbers stay readable. */
it('cuts a long text into lines at word breaks, in reading order', function () {
    $lines = PrintText::lines('Zone 66 Street 850 Building 12 near the big blue mosque gate', 20);

    expect(array_column($lines, 'text'))->toBe(['Zone 66 Street 850', 'Building 12 near the', 'big blue mosque gate'])
        ->and(collect($lines)->contains('rtl', true))->toBeFalse();
});

it('cuts a word that is longer than a line, keeps blank lines out and returns nothing for an empty text', function () {
    expect(array_column(PrintText::lines('Supercalifragilisticexpialidocious', 10), 'text'))->toBe(['Supercalif', 'ragilistic', 'expialidoc', 'ious'])
        ->and(PrintText::lines("  \n  ", 10))->toBe([])->and(PrintText::lines(null, 10))->toBe([]);
});

it('shapes every Arabic line on its own so the lines read top to bottom, not last line first', function () {
    $text = 'شارع الاستقلال بجانب مسجد الشيخ محمد بن عبدالله آل ثاني قرب البوابة الزرقاء';
    $lines = PrintText::lines($text, 24);

    expect(count($lines))->toBeGreaterThan(1)->and(collect($lines)->every('rtl', true))->toBeTrue();
    // The first line holds the FIRST words (shaped), the last line the last words.
    expect($lines[0]['text'])->toContain(ArabicShaper::shape('شارع'))->and(end($lines)['text'])->toContain(ArabicShaper::shape('الزرقاء'));
    foreach ($lines as $line) {
        expect(mb_strlen($line['text']))->toBeLessThanOrEqual(30);
    }
});

it('keeps the digits of a number readable when an Arabic comma follows it', function () {
    $shaped = PrintText::shape('المنطقة 66، الشارع 850، المبنى 12');

    expect($shaped)->toContain('850')->not->toContain('058')->toContain('12')->not->toContain('21')->toContain('66')
        ->and($shaped)->toContain('،850'); // the comma moved to the other side of the number, as it reads in a right-to-left line
});

it('leaves text without Arabic untouched and does not mix English words into Arabic ones', function () {
    expect(PrintText::shape('Zone 66, Doha'))->toBe('Zone 66, Doha')->and(PrintText::shape(''))->toBe('')->and(PrintText::shape(null))->toBe('')
        ->and(PrintText::shape('فرع Doha 12'))->toContain('Doha')->toContain('12');
});

it('prints a label in both languages with the customer\'s language first', function () {
    $en = PrintText::label('signature', 'en');
    $ar = PrintText::label('signature', 'ar');

    expect($en)->toStartWith('<span class="l1">Signature</span>')->toContain(ArabicShaper::shape('التوقيع'))
        ->and($ar)->toStartWith('<span class="l1">'.ArabicShaper::shape('التوقيع').'</span>')->toContain('Signature')
        ->and(PrintText::label('part_of', 'en', ['n' => 2, 'm' => 3]))->toContain('Part 2 of 3');
});
