<?php

namespace App\Support;

/**
 * Minimal Arabic presentation-form shaper for PDF engines that cannot join Arabic letters (dompdf).
 * Converts letters to their contextual presentation forms and reverses for visual RTL order.
 * Used only for server-rendered PDFs; browsers and Flutter shape Arabic natively.
 */
final class ArabicShaper
{
    // isolated, final, initial, medial
    private const FORMS = [
        'ء' => ['ﺀ', 'ﺀ', 'ﺀ', 'ﺀ'], 'آ' => ['ﺁ', 'ﺂ', 'ﺁ', 'ﺂ'], 'أ' => ['ﺃ', 'ﺄ', 'ﺃ', 'ﺄ'], 'ؤ' => ['ﺅ', 'ﺆ', 'ﺅ', 'ﺆ'], 'إ' => ['ﺇ', 'ﺈ', 'ﺇ', 'ﺈ'],
        'ئ' => ['ﺉ', 'ﺊ', 'ﺋ', 'ﺌ'], 'ا' => ['ﺍ', 'ﺎ', 'ﺍ', 'ﺎ'], 'ب' => ['ﺏ', 'ﺐ', 'ﺑ', 'ﺒ'], 'ة' => ['ﺓ', 'ﺔ', 'ﺓ', 'ﺔ'], 'ت' => ['ﺕ', 'ﺖ', 'ﺗ', 'ﺘ'],
        'ث' => ['ﺙ', 'ﺚ', 'ﺛ', 'ﺜ'], 'ج' => ['ﺝ', 'ﺞ', 'ﺟ', 'ﺠ'], 'ح' => ['ﺡ', 'ﺢ', 'ﺣ', 'ﺤ'], 'خ' => ['ﺥ', 'ﺦ', 'ﺧ', 'ﺨ'], 'د' => ['ﺩ', 'ﺪ', 'ﺩ', 'ﺪ'],
        'ذ' => ['ﺫ', 'ﺬ', 'ﺫ', 'ﺬ'], 'ر' => ['ﺭ', 'ﺮ', 'ﺭ', 'ﺮ'], 'ز' => ['ﺯ', 'ﺰ', 'ﺯ', 'ﺰ'], 'س' => ['ﺱ', 'ﺲ', 'ﺳ', 'ﺴ'], 'ش' => ['ﺵ', 'ﺶ', 'ﺷ', 'ﺸ'],
        'ص' => ['ﺹ', 'ﺺ', 'ﺻ', 'ﺼ'], 'ض' => ['ﺽ', 'ﺾ', 'ﺿ', 'ﻀ'], 'ط' => ['ﻁ', 'ﻂ', 'ﻃ', 'ﻄ'], 'ظ' => ['ﻅ', 'ﻆ', 'ﻇ', 'ﻈ'], 'ع' => ['ﻉ', 'ﻊ', 'ﻋ', 'ﻌ'],
        'غ' => ['ﻍ', 'ﻎ', 'ﻏ', 'ﻐ'], 'ف' => ['ﻑ', 'ﻒ', 'ﻓ', 'ﻔ'], 'ق' => ['ﻕ', 'ﻖ', 'ﻗ', 'ﻘ'], 'ك' => ['ﻙ', 'ﻚ', 'ﻛ', 'ﻜ'], 'ل' => ['ﻝ', 'ﻞ', 'ﻟ', 'ﻠ'],
        'م' => ['ﻡ', 'ﻢ', 'ﻣ', 'ﻤ'], 'ن' => ['ﻥ', 'ﻦ', 'ﻧ', 'ﻨ'], 'ه' => ['ﻩ', 'ﻪ', 'ﻫ', 'ﻬ'], 'و' => ['ﻭ', 'ﻮ', 'ﻭ', 'ﻮ'], 'ى' => ['ﻯ', 'ﻰ', 'ﻯ', 'ﻰ'],
        'ي' => ['ﻱ', 'ﻲ', 'ﻳ', 'ﻴ'],
    ];

    /** Letters that never connect to the following letter. */
    private const NON_JOINING = ['ء', 'آ', 'أ', 'ؤ', 'إ', 'ا', 'ة', 'د', 'ذ', 'ر', 'ز', 'و', 'ى'];

    public static function shape(string $text): string
    {
        if (! preg_match('/\p{Arabic}/u', $text)) {
            return $text;
        }
        $chars = mb_str_split($text);
        $out = [];
        $n = count($chars);
        for ($i = 0; $i < $n; $i++) {
            $c = $chars[$i];
            if (! isset(self::FORMS[$c])) {
                $out[] = $c;

                continue;
            }
            $prev = $i > 0 ? $chars[$i - 1] : null;
            $next = $i < $n - 1 ? $chars[$i + 1] : null;
            $joinsPrev = $prev && isset(self::FORMS[$prev]) && ! in_array($prev, self::NON_JOINING, true);
            $joinsNext = $next && isset(self::FORMS[$next]) && ! in_array($c, self::NON_JOINING, true);
            // Lam-alef ligature.
            if ($c === 'ل' && in_array($next, ['ا', 'أ', 'إ', 'آ'], true)) {
                $lig = ['ا' => ['ﻻ', 'ﻼ'], 'أ' => ['ﻷ', 'ﻸ'], 'إ' => ['ﻹ', 'ﻺ'], 'آ' => ['ﻵ', 'ﻶ']][$next];
                $out[] = $joinsPrev ? $lig[1] : $lig[0];
                $i++;

                continue;
            }
            $out[] = self::FORMS[$c][match (true) {
                $joinsPrev && $joinsNext => 3, $joinsPrev => 1, $joinsNext => 2, default => 0,
            }];
        }

        // Reverse Arabic runs for visual order while keeping Latin/number runs readable.
        $segments = preg_split('/(\s+)/u', implode('', $out), -1, PREG_SPLIT_DELIM_CAPTURE);

        return implode('', array_reverse(array_map(fn ($s) => preg_match('/\p{Arabic}|[\x{FB50}-\x{FEFF}]/u', $s) ? implode('', array_reverse(mb_str_split($s))) : $s, $segments)));
    }
}
