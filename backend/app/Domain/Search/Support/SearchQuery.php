<?php

namespace App\Domain\Search\Support;

use App\Support\SearchNormalizer;

/**
 * What a person typed into the global search, cleaned once and read in every way a result group needs it.
 *
 *  - `text`   the words, Arabic folded and lower-cased (SearchNormalizer): for product names and the "Go to" commands
 *  - `raw`    the words as typed (control characters gone, spaces collapsed, at most MAX_LENGTH characters): for customer names and e-mails
 *  - `order`  the part of an order number to look for ("#ab-100123", "AB100123-1" and "100123" all give 100123), or null when it is too short
 *  - `phone`  the digits of a Qatar mobile number or the start of one, when the person typed a phone-like thing: "+974 5512 3456",
 *             "974 55123456", "55123456", "٥٥١٢٣٤٥٦" (Arabic-Indic digits) all give 55123456; "5512" gives 5512. Null when it is not a phone.
 *
 * The query is never logged, never put in a URL here and only ever handed to the database as a bound parameter; the characters of a
 * LIKE pattern are escaped by {@see self::contains()} and {@see self::startsWith()}.
 */
final class SearchQuery
{
    /** The longest query that is read; the rest is dropped (a phone number with its spaces is 20 characters). */
    public const MAX_LENGTH = 64;

    /** Fewest characters before any lookup in a table (a trigram index needs three); two are enough for a menu page. */
    public const MIN_TABLE = 3;

    public const MIN_COMMAND = 2;

    /** Fewest digits that count as part of a phone number. */
    private const MIN_PHONE_DIGITS = 4;

    private const DIGITS = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];

    private function __construct(
        public readonly string $raw,
        public readonly string $text,
        public readonly ?string $order,
        public readonly ?string $phone,
        public readonly bool $phoneHasCountryCode,
    ) {}

    public static function from(mixed $input): self
    {
        $raw = is_string($input) ? $input : '';
        $raw = mb_scrub($raw, 'UTF-8');
        $raw = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $raw) ?? '';
        $raw = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
        $raw = rtrim(mb_substr($raw, 0, self::MAX_LENGTH));

        $ascii = strtr($raw, self::DIGITS);
        [$phone, $withCountry] = self::phoneOf($ascii);

        // A number typed with its country code is a phone, never the middle of an order number.
        return new self($raw, SearchNormalizer::normalize($raw), $withCountry ? null : self::orderOf($ascii), $phone, $withCountry);
    }

    /**
     * The part of an order number to look for: the number without its "AB" and without the "-1" of a branch part, or null when there is not
     * enough of it. The "AB" every number starts with is not counted, so "AB1" does not match the whole shop; three characters after it are needed.
     */
    private static function orderOf(string $ascii): ?string
    {
        $typed = strtoupper(str_replace(' ', '', ltrim($ascii, '# ')));
        if (preg_match('/^(?:AB)?-?([A-Z0-9]+?)(?:-\d{1,2})?$/', $typed, $m) !== 1) {
            return null;
        }

        return strlen($m[1]) >= self::MIN_TABLE ? $m[1] : null;
    }

    /** @return array{0: ?string, 1: bool} the national digits (4 to 8) and whether the +974 was typed */
    private static function phoneOf(string $ascii): array
    {
        if (preg_match('/^[+\d\s().\-]+$/', $ascii) !== 1) {
            return [null, false];
        }
        $digits = (string) preg_replace('/\D+/', '', $ascii);
        if (str_starts_with(ltrim($ascii), '00')) {
            $digits = substr($digits, 2);
        }
        $country = false;
        // Qatar mobile numbers start with 3, 5, 6 or 7, so a leading 974 is always the country code.
        if (str_starts_with($digits, '974')) {
            $digits = substr($digits, 3);
            $country = true;
        }
        // A whole number is 8 digits starting with 3, 5, 6 or 7; anything shorter is the start (or a piece) of one.
        if (strlen($digits) < self::MIN_PHONE_DIGITS || strlen($digits) > 8 || (strlen($digits) === 8 && preg_match('/^[3567]/', $digits) !== 1)) {
            return [null, false];
        }

        return [$digits, $country];
    }

    public function length(): int
    {
        return mb_strlen($this->raw);
    }

    /** Is there anything to look for in the tables? A trigram index needs three characters, and at least one of them a letter or a digit. */
    public function searchesTables(): bool
    {
        return $this->length() >= self::MIN_TABLE && preg_match('/[\p{L}\p{N}]/u', $this->raw) === 1;
    }

    public function searchesCommands(): bool
    {
        return $this->length() >= self::MIN_COMMAND && $this->text !== '';
    }

    /** `%word%`: the word anywhere, with the characters that mean something in a LIKE pattern made plain. */
    public static function contains(string $value): string
    {
        return '%'.addcslashes($value, '%_\\').'%';
    }

    /** `word%`: starts with the word. */
    public static function startsWith(string $value): string
    {
        return addcslashes($value, '%_\\').'%';
    }
}
