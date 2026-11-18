<?php

namespace App\Domain\Marketing\Support;

/**
 * The words of an automatic reminder: which placeholders exist, how they are filled in and what a customer sees in the preview.
 * One place, so the dashboard preview, the real message and the validation can never disagree.
 */
final class ReminderText
{
    /** Placeholder => what it becomes. Shown as chips next to the text fields (the lang key is `cart_reminders.ph.<name>`). */
    public const PLACEHOLDERS = ['name', 'items_text', 'items', 'total', 'link', 'coupon', 'expires', 'offer', 'first_item', 'app_link'];

    /** Placeholders that only make sense when the reminder carries a recovery offer. */
    public const OFFER_ONLY = ['coupon', 'expires', 'offer'];

    /** @param  array<string, mixed>  $vars */
    public static function fill(string $text, array $vars): string
    {
        return trim((string) preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', fn (array $m) => (string) ($vars[strtolower($m[1])] ?? ''), $text));
    }

    /** @return list<string> every {{placeholder}} the text uses */
    public static function used(string $text): array
    {
        preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $text, $m);

        return array_values(array_unique(array_map('strtolower', $m[1])));
    }

    /** "1 item", "3 items" / "منتج واحد", "منتجان", "3 منتجات", "12 منتجًا": a count that reads naturally in a sentence. */
    public static function itemsText(int $count, string $locale): string
    {
        return trans_choice('cart_reminders.items_text', $count, ['count' => number_format($count)], $locale);
    }

    /**
     * What the preview (and the test message) shows in place of a real customer's cart.
     *
     * @return array<string, string>
     */
    public static function sample(string $locale, bool $withOffer = false): array
    {
        $ar = $locale === 'ar';

        return [
            'name' => $ar ? 'فاطمة' : 'Fatima',
            'items' => '2',
            'items_text' => self::itemsText(2, $locale),
            'total' => $ar ? '1,250.00 ر.ق' : 'QAR 1,250.00',
            'first_item' => $ar ? 'أريكة مخمل' : 'Velvet sofa',
            'link' => rtrim((string) (config('platform.storefront_url') ?: config('app.url')), '/').'/cart',
            'app_link' => rtrim((string) config('app.url'), '/'),
            'coupon' => $withOffer ? 'BAHAR-7K2M9Q' : '',
            'expires' => $withOffer ? ($ar ? 'غدًا 20:00' : 'tomorrow 20:00') : '',
            'offer' => $withOffer ? ($ar ? 'توصيل مجاني' : 'Free delivery') : '',
        ];
    }

    /**
     * A message that carries a coupon but never mentions it gets one plain sentence with the code, so the customer is always told.
     *
     * @param  string  $raw  every unfilled text of the template (title, message, WhatsApp wording): where {{coupon}} would have been written
     * @param  array<string, mixed>  $vars
     */
    public static function withOffer(string $text, string $raw, array $vars, string $locale): string
    {
        if (($vars['coupon'] ?? '') === '' || in_array('coupon', self::used($raw), true)) {
            return $text;
        }

        return trim($text.' '.self::fill(self::offerLine($locale), $vars));
    }

    /** The sentence added when a reminder carries an offer but the text does not mention the code itself. */
    public static function offerLine(string $locale): string
    {
        return (string) trans('cart_reminders.offer_line', [], $locale);
    }
}
