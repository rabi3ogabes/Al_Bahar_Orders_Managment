<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Settings\Services\Settings;
use App\Support\DomainException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Writes the product description (English and Arabic) from the title with Claude. The key is an admin setting (sealed);
 * without it the feature says it is not set up. The model is told to use only what the title and category say.
 */
class ProductAi
{
    public function __construct(private readonly Settings $settings) {}

    public function configured(): bool
    {
        return (string) $this->settings->get('ai.anthropic_api_key', '') !== '';
    }

    /**
     * Writes both languages from whatever the admin typed: the missing title is translated (a typed title is kept as it is)
     * and the short and full descriptions are written in English and Arabic.
     *
     * @return array{name: array{en: string, ar: string}, en: array{short: string, description: string}, ar: array{short: string, description: string}}
     */
    public function describe(?string $titleEn, ?string $titleAr, ?string $category = null, ?string $material = null): array
    {
        $titleEn = trim((string) $titleEn);
        $titleAr = trim((string) $titleAr);
        if ($titleEn === '' && $titleAr === '') {
            throw DomainException::make('ai_title_required');
        }
        $key = (string) $this->settings->get('ai.anthropic_api_key', '');
        if ($key === '') {
            throw DomainException::make('ai_not_configured');
        }
        $facts = array_filter(['Title (English)' => $titleEn, 'Title (Arabic)' => $titleAr, 'Category' => $category, 'Material' => $material]);
        $prompt = "You write product copy for Al Bahar Home & Living, a premium home furnishing store in Qatar.\n"
            ."Use ONLY the facts below. Do not invent sizes, materials, colours, warranties, certifications, prices or claims that are not stated.\n"
            ."Tone: calm, elegant, specific and honest. No exclamation marks, no emojis, no filler.\n\n"
            .collect($facts)->map(fn ($v, $k) => "$k: $v")->implode("\n")."\n\n"
            ."Return ONLY one JSON object, no other text:\n"
            .'{"name":{"en":"…","ar":"…"},"en":{"short":"max 160 characters","description":"two short paragraphs as <p>…</p>"},"ar":{"short":"…","description":"…"}}'."\n"
            ."name: if a title is missing, translate the other one into a natural, short product name (Title Case in English); if a title is given, return it unchanged.\n"
            .'The Arabic must be natural Modern Standard Arabic written for Qatar, not a literal translation. Write every string on one line (no line breaks inside strings).';

        $json = null;
        foreach ([1, 2] as $attempt) {
            $res = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(60)->acceptJson()
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => (string) ($this->settings->get('ai.model') ?: 'claude-sonnet-5-5'), 'max_tokens' => 2500,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);
            if (! $res->successful()) {
                Log::warning('ai.describe http '.$res->status().' '.substr($res->body(), 0, 300));

                continue;
            }
            // The answer can start with a thinking block: read every text block, not only the first one.
            $text = collect((array) data_get($res->json(), 'content', []))->filter(fn ($b) => isset($b['text']) && ($b['type'] ?? 'text') === 'text')->pluck('text')->implode("\n");
            $json = $this->parse($text);
            if ($json !== null) {
                break;
            }
            Log::warning('ai.describe unreadable answer (stop='.data_get($res->json(), 'stop_reason').', blocks='.collect((array) data_get($res->json(), 'content', []))->pluck('type')->implode(',').'): '.substr($text, 0, 300));
        }
        if ($json === null) {
            throw DomainException::make('ai_failed', 502);
        }
        $name = fn (string $l, string $typed) => $typed !== '' ? $typed : mb_substr(trim(strip_tags((string) data_get($json, "name.$l", ''))), 0, 255);

        return [
            'name' => ['en' => $name('en', $titleEn), 'ar' => $name('ar', $titleAr)],
            'en' => ['short' => mb_substr(strip_tags($json['en']['short']), 0, 300), 'description' => $this->clean($json['en']['description'])],
            'ar' => ['short' => mb_substr(strip_tags($json['ar']['short']), 0, 300), 'description' => $this->clean($json['ar']['description'])],
        ];
    }

    /** The first JSON object in the answer (the model may wrap it in a code block or add a word), or null when it is not usable. */
    private function parse(string $text): ?array
    {
        $a = strpos($text, '{');
        $b = strrpos($text, '}');
        if ($a === false || $b === false || $b <= $a) {
            return null;
        }
        $json = json_decode(substr($text, $a, $b - $a + 1), true, 16, JSON_INVALID_UTF8_SUBSTITUTE);
        foreach (['en', 'ar'] as $l) {
            if (! is_array($json) || ! is_string(data_get($json, "$l.short")) || ! is_string(data_get($json, "$l.description"))) {
                return null;
            }
        }

        return $json;
    }

    /** Only paragraph tags survive: the text goes into a rich-text field that is rendered on the storefront. */
    private function clean(string $html): string
    {
        return trim(strip_tags($html, '<p><br><strong><em><ul><ol><li>'));
    }
}
