<?php

namespace App\Support;

/**
 * Normalises Arabic and English text for search: folds Alef/Yaa/Taa-marbuta variants,
 * strips diacritics (tashkeel) and tatweel, converts Arabic-Indic digits and lower-cases Latin.
 */
final class SearchNormalizer
{
    public static function normalize(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        $text = mb_strtolower($text, 'UTF-8');
        // Diacritics U+064B–U+065F, superscript alef U+0670, tatweel U+0640.
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text) ?? $text;
        $text = strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ى' => 'ي', 'ئ' => 'ي', 'ؤ' => 'و', 'ة' => 'ه',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
