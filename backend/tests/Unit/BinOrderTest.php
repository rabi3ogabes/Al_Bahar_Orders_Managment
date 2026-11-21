<?php

use App\Domain\Orders\Printing\BinOrder;

/** The walking order of a picking list: bin by bin, the way a person reads a shelf code. */
function binLine(string $id, ?string $bin, string $name = 'Item', string $sku = 'S'): array
{
    return ['id' => $id, 'bin' => $bin, 'name' => $name, 'sku' => $sku];
}

it('sorts the lines by bin the way a person reads a shelf code (A-2 before A-10)', function () {
    $sorted = BinOrder::sort([binLine('1', 'A-10-1'), binLine('2', 'A-2-1'), binLine('3', 'B-1-1'), binLine('4', 'A-2-10'), binLine('5', 'A-2-2')]);

    expect(array_column($sorted, 'bin'))->toBe(['A-2-1', 'A-2-2', 'A-2-10', 'A-10-1', 'B-1-1']);
});

it('puts the lines without a bin last, and ignores blanks and capitals', function () {
    $sorted = BinOrder::sort([binLine('1', null, 'Zebra rug'), binLine('2', 'b-1'), binLine('3', '  ', 'Apple lamp'), binLine('4', ' A-1 ')]);

    expect(array_column($sorted, 'id'))->toBe(['4', '2', '3', '1']); // A-1, b-1, then the two without a bin by name
});

it('keeps lines of one bin in a fixed order: name, then SKU, then id', function () {
    $in = [binLine('9', 'A-1', 'Sofa', 'S-2'), binLine('3', 'A-1', 'Sofa', 'S-1'), binLine('1', 'A-1', 'Lamp', 'S-9'), binLine('2', 'A-1', 'Sofa', 'S-1')];
    $sorted = BinOrder::sort($in);

    expect(array_column($sorted, 'id'))->toBe(['1', '2', '3', '9'])
        ->and(array_column(BinOrder::sort(array_reverse($in)), 'id'))->toBe(['1', '2', '3', '9']); // the same whatever order they arrive in
});

it('sorts an empty list and a single line', function () {
    expect(BinOrder::sort([]))->toBe([])->and(BinOrder::sort([binLine('1', null)]))->toHaveCount(1);
});
