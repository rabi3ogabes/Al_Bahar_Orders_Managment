<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\JourneyTemplate;
use App\Domain\Orders\Models\Cart;
use App\Support\BusinessTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * The placeholders a message may use ({{name}}, {{coupon}} ...), their values for one customer, and the filling itself.
 * They are the same ones the automatic journeys use, so a template works in both. Anything not on the list is rejected when the
 * message is written (a typo like {{nmae}} must never reach a customer as an empty hole).
 */
class MessageVariables
{
    public const PLACEHOLDERS = ['name', 'coupon', 'expires', 'items', 'first_item', 'app_link'];

    /**
     * @return array{name: string, coupon: string, expires: string, items: string, first_item: string, app_link: string}
     */
    public function for(User $user, string $locale, ?string $couponCode = null, ?CarbonInterface $couponEnds = null): array
    {
        $ar = $locale === 'ar';
        $cart = $this->activeCart($user->id);
        $first = $cart?->items->first()?->variant?->product;

        return [
            'name' => Str::before(trim((string) $user->name), ' '),
            'coupon' => (string) $couponCode,
            'expires' => $couponEnds ? BusinessTime::local($couponEnds)->locale($ar ? 'ar' : 'en')->isoFormat('D MMM, HH:mm') : '',
            'items' => $cart ? (string) $cart->items->sum('quantity') : '',
            'first_item' => $first ? (string) $first->trans('name', $ar ? 'ar' : 'en') : '',
            'app_link' => rtrim((string) config('app.url'), '/'),
        ];
    }

    /** What a message looks like to the staff member before sending: made-up but realistic values (no customer data, no coupon issued). */
    public function sample(string $locale, string $name = ''): array
    {
        $ar = $locale === 'ar';

        return ['name' => $name !== '' ? $name : ($ar ? 'نور' : 'Noor'), 'coupon' => 'BAHAR-7K2QXM', 'expires' => $ar ? '٣ ديسمبر، ١٨:٠٠' : '3 Dec, 18:00',
            'items' => '3', 'first_item' => $ar ? 'طقم عشاء سيراميك' : 'Ceramic dinner set', 'app_link' => rtrim((string) config('app.url'), '/')];
    }

    /** @param array<string, string> $vars */
    public function fill(string $text, array $vars): string
    {
        return trim((string) preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', fn (array $m) => (string) ($vars[strtolower($m[1])] ?? ''), $text));
    }

    /** Placeholders in the text that are not on the list. @return list<string> */
    public function unknown(string $text): array
    {
        preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $text, $m);

        return array_values(array_unique(array_diff(array_map('strtolower', $m[1]), self::PLACEHOLDERS)));
    }

    /**
     * Can every placeholder the template's texts use be filled in a message to a customer? The automatic abandoned-cart reminders use cart details
     * ({{items_text}}, {{total}}, {{link}}) that this list does not know, so they stay in their own journey and are not offered in the composer.
     */
    public function fitsTemplate(JourneyTemplate $t): bool
    {
        return $this->unknown(implode(' ', array_filter([$t->title_en, $t->title_ar, $t->body_en, $t->body_ar, $t->whatsapp_en, $t->whatsapp_ar]))) === [];
    }

    /** Whether the text uses a placeholder. */
    public function uses(string $text, string $placeholder): bool
    {
        return (bool) preg_match('/\{\{\s*'.preg_quote($placeholder, '/').'\s*\}\}/i', $text);
    }

    /** The customer's open cart with something in it (what an "order left behind" reminder is about), or null. */
    public function activeCart(string $userId): ?Cart
    {
        return Cart::query()->where('user_id', $userId)->where('status', 'active')->whereHas('items')
            ->with('items.variant.product.translations')->orderByDesc('last_activity_at')->first();
    }
}
