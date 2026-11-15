<?php

namespace App\Domain\Orders\Services;

use App\Domain\Orders\Models\OrderRevisionLine;

/** The words for an order adjustment in one language: the reason and one sentence per changed item (notification, order page, invoice). */
final class AdjustmentText
{
    public static function reason(string $code, string $locale): string
    {
        return (string) __('adjustments.reason.'.$code, [], $locale);
    }

    /** "Oslo sofa (Grey): 2 → 1", "Oslo lamp removed", "Oslo lamp replaced with Oslo lamp (large)". */
    public static function line(OrderRevisionLine $l, string $locale): string
    {
        return self::fromArray(['action' => $l->action, 'name_en' => $l->name_en, 'name_ar' => $l->name_ar, 'variant_name_en' => $l->variant_name_en,
            'variant_name_ar' => $l->variant_name_ar, 'from_quantity' => $l->from_quantity, 'to_quantity' => $l->to_quantity, 'replacement' => $l->replacement], $locale);
    }

    /**
     * The same sentence from a frozen copy of the line (an invoice snapshot): names in both languages, quantities, the substitute.
     *
     * @param  array<string, mixed>  $l
     */
    public static function fromArray(array $l, string $locale): string
    {
        $name = self::named(self::pick($l, 'name', $locale), self::pick($l, 'variant_name', $locale));

        return match ($l['action']) {
            'reduce' => __('adjustments.line.reduce', ['name' => $name, 'from' => $l['from_quantity'], 'to' => $l['to_quantity']], $locale),
            'remove' => __('adjustments.line.remove', ['name' => $name], $locale),
            default => self::replaced($l, $name, $locale),
        };
    }

    /** @param  iterable<OrderRevisionLine>  $lines */
    public static function lines(iterable $lines, string $locale): string
    {
        $out = [];
        foreach ($lines as $l) {
            $out[] = self::line($l, $locale);
        }

        return implode('; ', $out);
    }

    /** @param  array<string, mixed>  $l */
    private static function replaced(array $l, string $name, string $locale): string
    {
        $sub = (array) ($l['replacement'] ?? []);
        $new = self::named(self::pick($sub, 'name', $locale), self::pick($sub, 'variant_name', $locale));
        $qty = (int) ($sub['quantity'] ?? $l['from_quantity']);

        return $qty === (int) $l['from_quantity']
            ? __('adjustments.line.replace', ['name' => $name, 'new' => $new], $locale)
            : __('adjustments.line.replace_qty', ['name' => $name, 'new' => $new, 'qty' => $qty], $locale);
    }

    /** @param  array<string, mixed>  $row */
    private static function pick(array $row, string $field, string $locale): string
    {
        return (string) (($row[$field.'_'.$locale] ?? null) ?: ($row[$field.'_en'] ?? ''));
    }

    private static function named(string $name, ?string $variant): string
    {
        return $variant !== null && $variant !== '' ? $name.' ('.$variant.')' : $name;
    }
}
