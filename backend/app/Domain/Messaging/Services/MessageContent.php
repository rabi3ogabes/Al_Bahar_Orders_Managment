<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Messaging\Models\MessageBatch;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The texts of a message as the staff member wrote or edited them: validated, stripped of markup and control characters, and checked for
 * placeholders that do not exist. What comes out is exactly what is stored in the batch and sent.
 *
 * @phpstan-type Content array{title_en: string, title_ar: string, body_en: string, body_ar: string, whatsapp_en: string, whatsapp_ar: string, deep_link: ?string, coupon: array{enabled: bool, promotion_id: ?string, prefix: string, valid_hours: int}}
 */
class MessageContent
{
    public const TITLE_MAX = 65;

    public const BODY_MAX = 240;

    public const WHATSAPP_MAX = 1000;

    public function __construct(private readonly MessageVariables $variables) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $channels
     * @return Content
     *
     * @throws ValidationException keyed by the field names of the composer (title_en, body_ar, whatsapp_en, deep_link, coupon.promotion_id ...)
     */
    public function clean(array $input, array $channels, string $kind): array
    {
        $in = [];
        foreach (['title_en', 'title_ar', 'body_en', 'body_ar', 'whatsapp_en', 'whatsapp_ar'] as $field) {
            $in[$field] = $this->plain($input[$field] ?? '', $field);
        }
        $in['deep_link'] = trim((string) ($input['deep_link'] ?? '')) ?: null;
        $coupon = (array) ($input['coupon'] ?? []);
        $in['coupon'] = [
            'enabled' => (bool) ($coupon['enabled'] ?? false),
            'promotion_id' => ($coupon['promotion_id'] ?? '') !== '' ? (string) $coupon['promotion_id'] : null,
            'prefix' => strtoupper(trim((string) ($coupon['prefix'] ?? 'BAHAR'))) ?: 'BAHAR',
            'valid_hours' => (int) ($coupon['valid_hours'] ?? 72),
        ];

        $push = in_array('notification', $channels, true);
        $wa = in_array('whatsapp', $channels, true);
        $rules = [
            'title_en' => [$push ? 'required' : 'nullable', 'string', 'max:'.self::TITLE_MAX], 'title_ar' => [$push ? 'required' : 'nullable', 'string', 'max:'.self::TITLE_MAX],
            'body_en' => [$push || $wa ? 'required' : 'nullable', 'string', 'max:'.self::BODY_MAX], 'body_ar' => [$push || $wa ? 'required' : 'nullable', 'string', 'max:'.self::BODY_MAX],
            'whatsapp_en' => ['nullable', 'string', 'max:'.self::WHATSAPP_MAX], 'whatsapp_ar' => ['nullable', 'string', 'max:'.self::WHATSAPP_MAX],
            'deep_link' => ['nullable', 'string', 'max:140', 'regex:#^(albahar://[a-z0-9/_\-?=&.%]{1,120}|/[A-Za-z0-9/_\-?=&.%]{0,120})$#'],
            'coupon.enabled' => ['boolean'],
            'coupon.promotion_id' => [$in['coupon']['enabled'] ? 'required' : 'nullable', 'uuid', 'exists:promotions,id'],
            'coupon.prefix' => ['required', 'regex:/^[A-Z0-9]{2,8}$/'],
            'coupon.valid_hours' => ['integer', 'min:1', 'max:720'],
        ];
        $validator = Validator::make($in, $rules, [], $this->names());
        $validator->after(function ($v) use ($in, $kind) {
            foreach (['title_en', 'title_ar', 'body_en', 'body_ar', 'whatsapp_en', 'whatsapp_ar'] as $field) {
                $unknown = $this->variables->unknown($in[$field]);
                if ($unknown !== []) {
                    $v->errors()->add($field, __('messaging_admin.err.unknown_placeholder', ['list' => '{{'.implode('}}, {{', $unknown).'}}']));
                }
            }
            $usesCoupon = collect(['title_en', 'title_ar', 'body_en', 'body_ar', 'whatsapp_en', 'whatsapp_ar'])->contains(fn ($f) => $this->variables->uses($in[$f], 'coupon'));
            if ($usesCoupon && ! $in['coupon']['enabled']) {
                $v->errors()->add('coupon.enabled', __('messaging_admin.err.coupon_unused'));
            }
            if ($kind === 'offer' && $in['coupon']['enabled'] && ! $usesCoupon) {
                $v->errors()->add('coupon.enabled', __('messaging_admin.err.coupon_not_shown'));
            }
        });

        return $validator->validate() + [];
    }

    /** Plain text only: no markup, no control characters, at most one blank line in a row. */
    public function plain(mixed $value, string $field = ''): string
    {
        $s = strip_tags((string) $value);
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = (string) preg_replace('/[^\P{C}\n]+/u', '', $s);
        $s = (string) preg_replace("/[ \t]+\n/", "\n", $s);
        $s = (string) preg_replace("/\n{3,}/", "\n\n", $s);

        return trim($s);
    }

    /** @return array<string, string> */
    private function names(): array
    {
        $out = [];
        foreach (['title_en', 'title_ar', 'body_en', 'body_ar', 'whatsapp_en', 'whatsapp_ar', 'deep_link'] as $f) {
            $out[$f] = __('messaging_admin.field.'.$f);
        }

        return $out + ['coupon.promotion_id' => __('messaging_admin.field.promotion'), 'coupon.prefix' => __('messaging_admin.field.coupon_prefix'), 'coupon.valid_hours' => __('messaging_admin.field.coupon_hours')];
    }

    /** Which kinds exist (kept next to the validation so a new one is added in one place). @return list<string> */
    public static function kinds(): array
    {
        return MessageBatch::KINDS;
    }
}
