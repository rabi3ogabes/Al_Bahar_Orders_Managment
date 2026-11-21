<?php

namespace App\Domain\Orders\Printing;

use App\Support\ArabicShaper;

/**
 * Text for the printed documents. dompdf cannot join Arabic letters and lays every line out left to right, so Arabic goes through
 * {@see ArabicShaper} one LINE at a time: a long Arabic address is first cut into lines here (reading order, top to bottom) and each line is
 * shaped on its own. Shaping a whole paragraph and letting the PDF engine wrap it would put the end of the sentence on the first line.
 */
final class PrintText
{
    /** An Arabic LETTER (the Arabic comma and the Arabic digits are not letters: they travel with the number beside them). */
    private const ARABIC_LETTER = '/[\x{0621}-\x{064A}\x{0671}-\x{06D3}\x{FB50}-\x{FEFF}]/u';

    public static function hasArabic(?string $text): bool
    {
        return $text !== null && preg_match('/\p{Arabic}/u', $text) === 1;
    }

    /** One text ready for the PDF: Arabic words joined and put in visual order, numbers and Latin words kept readable, anything else unchanged. */
    public static function shape(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        if (! self::hasArabic($text)) {
            return $text;
        }
        $visual = array_map(function (string $part): string {
            if ($part === '' || trim($part) === '') {
                return $part;
            }
            if (preg_match(self::ARABIC_LETTER, $part) === 1) {
                return ArabicShaper::shape($part);
            }
            // A number or a Latin word with an Arabic comma beside it ("66،"): the number stays as it is and the comma moves to the other side of it.
            if (preg_match('/^(.*?)([\x{060C}\x{061B}\x{061F}]+)$/u', $part, $m) === 1) {
                return $m[2].$m[1];
            }
            if (preg_match('/^([\x{060C}\x{061B}\x{061F}]+)(.*)$/u', $part, $m) === 1) {
                return $m[2].$m[1];
            }

            return $part;
        }, preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: []);

        return implode('', array_reverse($visual));
    }

    /**
     * A text cut into lines of about $width characters at word breaks (a longer word is cut), each line shaped when it holds Arabic.
     *
     * @return list<array{text: string, rtl: bool}> `rtl` lines are meant to sit at the right edge
     */
    public static function lines(?string $text, int $width): array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return [];
        }
        $width = max(8, $width);
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                while (mb_strlen($word) > $width) {
                    if ($line !== '') {
                        $out[] = $line;
                        $line = '';
                    }
                    $out[] = mb_substr($word, 0, $width);
                    $word = mb_substr($word, $width);
                }
                $try = $line === '' ? $word : $line.' '.$word;
                if (mb_strlen($try) > $width) {
                    $out[] = $line;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return array_map(fn (string $l) => ['text' => self::shape($l), 'rtl' => self::hasArabic($l)], $out);
    }

    /**
     * A label of the documents in both languages, the one that leads first and the other one muted beside it (the same look as the invoice).
     * The words are in lang/{en,ar}/print_docs.php under `doc`.
     *
     * @param  array<string, scalar>  $params
     */
    public static function label(string $key, string $lead, array $params = []): string
    {
        $en = e(__('print_docs.doc.'.$key, $params, 'en'));
        $ar = e(self::shape(__('print_docs.doc.'.$key, $params, 'ar')));
        [$first, $second] = $lead === 'ar' ? [$ar, $en] : [$en, $ar];

        return '<span class="l1">'.$first.'</span> <span class="l2">'.$second.'</span>';
    }
}
