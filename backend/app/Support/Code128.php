<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Code 128 barcodes as inline SVG, without a library.
 *
 * Code 128 is the one linear barcode that carries any printable ASCII text, which is what a shop's own labels need (SKUs such as
 * "SOFA-OAK-180", EAN/UPC numbers as well). Every barcode scanner and the customer app's camera read it.
 *
 * The encoder uses sets B (text) and C (digit pairs, half the width for long numbers) and switches between them the way the
 * standard recommends: a run of 6 or more digits (4 at the very start or end) is written in set C.
 */
final class Code128
{
    /** Bar / space widths of symbols 0-105 (a bar first, then alternating), 11 modules each. */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232',
    ];

    /** Seven elements (bar to bar, 13 modules): the stop symbol. */
    private const STOP = '2331112';

    private const START_B = 104;

    private const START_C = 105;

    private const SWITCH_TO_B = 100;

    private const SWITCH_TO_C = 99;

    /** Longest text that still prints at a readable width on a label. */
    public const MAX_LENGTH = 48;

    /** Plain printable ASCII (space to tilde), 1 to {@see MAX_LENGTH} characters. */
    public static function supports(string $text): bool
    {
        return $text !== '' && strlen($text) <= self::MAX_LENGTH && preg_match('/^[\x20-\x7E]+$/', $text) === 1;
    }

    /**
     * The symbol values of the barcode: start, data, check, stop.
     *
     * @return list<int>
     */
    public static function symbols(string $text): array
    {
        if (! self::supports($text)) {
            throw new InvalidArgumentException('Code 128 text must be 1 to '.self::MAX_LENGTH.' printable ASCII characters.');
        }
        $n = strlen($text);
        $digits = fn (int $i): int => strspn($text, '0123456789', $i);

        $run = $digits(0);
        $setC = $run >= 4 || ($n === 2 && $run === 2);
        $data = [$setC ? self::START_C : self::START_B];
        $i = 0;
        while ($i < $n) {
            if ($setC) {
                if ($digits($i) >= 2) {
                    $data[] = (int) substr($text, $i, 2);
                    $i += 2;
                } else {
                    $data[] = self::SWITCH_TO_B;
                    $setC = false;
                }

                continue;
            }
            $run = $digits($i);
            if ($run >= 6 || ($run >= 4 && $i + $run === $n)) {
                if ($run % 2 === 1) { // an odd run starts with one digit in set B, so the rest pairs up exactly
                    $data[] = ord($text[$i]) - 32;
                    $i++;
                }
                $data[] = self::SWITCH_TO_C;
                $setC = true;

                continue;
            }
            $data[] = ord($text[$i]) - 32;
            $i++;
        }

        $sum = $data[0];
        foreach (array_slice($data, 1) as $position => $value) {
            $sum += $value * ($position + 1);
        }
        $data[] = $sum % 103;
        $data[] = 106;

        return $data;
    }

    /** The barcode as a string of modules, "1" = bar, "0" = space (no quiet zone). */
    public static function modules(string $text): string
    {
        $out = '';
        foreach (self::symbols($text) as $symbol) {
            $pattern = $symbol === 106 ? self::STOP : self::PATTERNS[$symbol];
            foreach (str_split($pattern) as $k => $width) {
                $out .= str_repeat($k % 2 === 0 ? '1' : '0', (int) $width);
            }
        }

        return $out;
    }

    /**
     * Inline SVG of the barcode, stretching to the width of its box ($height is in the SVG's own units; the box sets the real size).
     * Includes the 10-module quiet zone the standard asks for on both sides.
     */
    public static function svg(string $text, int $height = 40, string $color = '#000'): string
    {
        $modules = self::modules($text);
        $quiet = 10;
        $path = '';
        preg_match_all('/1+/', $modules, $runs, PREG_OFFSET_CAPTURE);
        foreach ($runs[0] as [$run, $offset]) {
            $path .= sprintf('M%d 0h%dv%dh-%dz', $quiet + $offset, strlen($run), $height, strlen($run));
        }
        $width = strlen($modules) + 2 * $quiet;
        $label = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" preserveAspectRatio="none" role="img" aria-label="%s" shape-rendering="crispEdges"><path d="%s" fill="%s"/></svg>',
            $width, $height, $label, $path, htmlspecialchars($color, ENT_QUOTES),
        );
    }
}
