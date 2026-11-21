<?php

namespace App\Domain\Orders\Printing;

/**
 * The walking order of a picking list: the lines are sorted by bin (shelf) location so the picker goes through the store once. The bins are
 * compared the way a person reads them ("A-2" before "A-10", capitals and small letters alike), lines whose bin is not known come last, and
 * lines in the same bin (or without one) fall back to name, SKU and id, so the same part always prints in the same order.
 */
final class BinOrder
{
    /**
     * @param  list<array<string, mixed>>  $lines  each with `bin` (?string), `name` (string), `sku` (string) and `id` (string)
     * @return list<array<string, mixed>>
     */
    public static function sort(array $lines): array
    {
        usort($lines, self::compare(...));

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public static function compare(array $a, array $b): int
    {
        $binA = self::bin($a['bin'] ?? null);
        $binB = self::bin($b['bin'] ?? null);
        if (($binA === null) !== ($binB === null)) {
            return $binA === null ? 1 : -1;
        }
        if ($binA !== null && $binB !== null && ($byBin = strnatcasecmp($binA, $binB)) !== 0) {
            return $byBin;
        }

        return strnatcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''))
            ?: strnatcasecmp((string) ($a['sku'] ?? ''), (string) ($b['sku'] ?? ''))
            ?: strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
    }

    /** The bin as it is compared: no blanks around it, and null when there is none. */
    public static function bin(mixed $bin): ?string
    {
        $text = is_string($bin) ? trim($bin) : '';

        return $text === '' ? null : $text;
    }
}
