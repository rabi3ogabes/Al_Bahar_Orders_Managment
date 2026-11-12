<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Settings\Services\Settings;
use App\Support\DomainException;
use Illuminate\Support\Facades\Http;

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

    /** @return array{en: array{short: string, description: string}, ar: array{short: string, description: string}} */
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
            ."Write for this product, using ONLY the facts below. Do not invent sizes, materials, colours, warranties, certifications, prices or claims that are not stated.\n"
            ."Tone: calm, elegant, specific and honest. No exclamation marks, no emojis, no filler.\n\n"
            .collect($facts)->map(fn ($v, $k) => "$k: $v")->implode("\n")."\n\n"
            ."Return ONLY JSON: {\"en\":{\"short\":\"max 160 characters\",\"description\":\"two short paragraphs as <p>…</p>\"},\"ar\":{\"short\":\"…\",\"description\":\"…\"}}\n"
            .'The Arabic must be natural Modern Standard Arabic written for Qatar, not a literal translation. If one title is missing, base both languages on the other.';

        $res = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(40)->acceptJson()
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => (string) ($this->settings->get('ai.model') ?: 'claude-sonnet-5-5'), 'max_tokens' => 1200,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);
        if (! $res->successful()) {
            throw DomainException::make('ai_failed', 502);
        }
        $text = (string) data_get($res->json(), 'content.0.text', '');
        $json = json_decode(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($text)) ?? '', true);
        foreach (['en', 'ar'] as $l) {
            if (! is_array($json) || ! is_string(data_get($json, "$l.short")) || ! is_string(data_get($json, "$l.description"))) {
                throw DomainException::make('ai_failed', 502);
            }
        }

        return ['en' => ['short' => mb_substr(strip_tags($json['en']['short']), 0, 300), 'description' => $this->clean($json['en']['description'])],
            'ar' => ['short' => mb_substr(strip_tags($json['ar']['short']), 0, 300), 'description' => $this->clean($json['ar']['description'])]];
    }

    /** Only paragraph tags survive: the text goes into a rich-text field that is rendered on the storefront. */
    private function clean(string $html): string
    {
        return trim(strip_tags($html, '<p><br><strong><em><ul><ol><li>'));
    }
}
