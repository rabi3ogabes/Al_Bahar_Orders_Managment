<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductTranslation;
use App\Domain\Settings\Services\Settings;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Rewrites the wording of existing products (names, short and full descriptions, SEO text, in English and Arabic) with Claude,
 * WITHOUT changing what the product is: brand, sizes, pack counts and units must survive. Three safe steps:
 *   generate → proposals file (nothing in the database changes),
 *   apply    → backup file first, then update; the old names are added to the search keywords so they still find the product,
 *   restore  → put a backup file back.
 * Slugs (URLs) are never changed.
 */
class CatalogRewriter
{
    public const DIR = 'catalog-rewrite';

    public function __construct(private readonly Settings $settings) {}

    /**
     * Writes proposals for products not yet in the proposals file. Returns [proposed, rejected].
     *
     * @return array{0: int, 1: int}
     */
    public function generate(string $file, ?int $limit = null, ?string $category = null, int $chunk = 8, int $parallel = 4, ?callable $progress = null): array
    {
        $key = (string) $this->settings->get('ai.anthropic_api_key', '');
        if ($key === '') {
            throw new \RuntimeException('The Claude API key is not set (Settings → Integrations).');
        }
        $done = $this->loadProposals($file)->keys()->all();
        $products = Product::with(['translations', 'category.translations', 'brand'])
            ->when($category, fn ($q) => $q->whereHas('category.translations', fn ($t) => $t->where('name', 'ilike', $category)))
            ->orderBy('sku')->get()->reject(fn (Product $p) => in_array($p->sku, $done, true))->values();
        if ($limit) {
            $products = $products->take($limit)->values();
        }
        $ok = 0;
        $bad = 0;
        foreach ($products->chunk($chunk * $parallel) as $wave) {
            $groups = $wave->chunk($chunk)->values();
            $responses = Http::pool(fn (Pool $pool) => $groups->map(fn (Collection $g, int $i) => $pool->as((string) $i)->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])
                ->timeout(180)->acceptJson()->post('https://api.anthropic.com/v1/messages', [
                    'model' => (string) ($this->settings->get('ai.model') ?: 'claude-sonnet-5-5'), 'max_tokens' => 12000,
                    'messages' => [['role' => 'user', 'content' => $this->prompt($g)]],
                ]))->all());
            foreach ($groups as $i => $g) {
                $res = $responses[(string) $i] ?? null;
                $items = $res && ! $res instanceof \Throwable && $res->successful() ? $this->parse($res->json()) : [];
                foreach ($g as $p) {
                    $new = $items[$p->sku] ?? null;
                    if ($new && $this->valid($p, $new)) {
                        Storage::disk('local')->append($file, json_encode(['sku' => $p->sku, 'id' => $p->id, 'new' => $new], JSON_UNESCAPED_UNICODE));
                        $ok++;
                    } else {
                        $bad++;
                    }
                }
            }
            $progress && $progress($ok, $bad, $products->count());
        }

        return [$ok, $bad];
    }

    /** @return Collection<string, array<string, mixed>> proposals keyed by sku */
    public function loadProposals(string $file): Collection
    {
        if (! Storage::disk('local')->exists($file)) {
            return collect();
        }

        return collect(preg_split('/\r\n|\n|\r/', trim(Storage::disk('local')->get($file))))->filter()->map(fn ($l) => json_decode($l, true))->filter()->keyBy('sku');
    }

    /**
     * Applies the proposals. Writes the backup first. Returns the number of products changed.
     */
    public function apply(string $proposalsFile, string $backupFile, ?int $limit = null): int
    {
        $proposals = $this->loadProposals($proposalsFile)->take($limit ?: PHP_INT_MAX);
        $products = Product::with('translations')->whereIn('sku', $proposals->keys())->get()->keyBy('sku');
        $backup = [];
        foreach ($products as $p) {
            foreach ($p->translations as $t) {
                $backup[] = $t->only(['id', 'product_id', 'locale', 'name', 'short_description', 'description', 'seo_title', 'seo_description', 'search_keywords']);
            }
        }
        Storage::disk('local')->put($backupFile, json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $n = 0;
        foreach ($proposals as $sku => $row) {
            $p = $products[$sku] ?? null;
            if (! $p) {
                continue;
            }
            DB::transaction(function () use ($p, $row) {
                foreach (['en', 'ar'] as $l) {
                    $t = $p->translations->firstWhere('locale', $l);
                    $new = $row['new'][$l] ?? null;
                    if (! $t || ! $new) {
                        continue;
                    }
                    $old = $t->name;
                    $keywords = collect(explode(',', (string) $t->search_keywords))->map(fn ($k) => trim($k))->filter()->push($old)->unique()->implode(', ');
                    $t->update([
                        'name' => $new['name'], 'short_description' => $new['short'], 'description' => $new['description'],
                        'seo_title' => $new['seo_title'], 'seo_description' => $new['seo_description'],
                        'search_keywords' => mb_substr($keywords, 0, 1000),
                    ]);
                }
            });
            $n++;
        }

        return $n;
    }

    public function restore(string $backupFile): int
    {
        $rows = json_decode(Storage::disk('local')->get($backupFile), true) ?: [];
        foreach ($rows as $r) {
            ProductTranslation::whereKey($r['id'])->first()?->update(collect($r)->only(['name', 'short_description', 'description', 'seo_title', 'seo_description', 'search_keywords'])->all());
        }

        return count($rows);
    }

    private function prompt(Collection $group): string
    {
        $items = $group->map(fn (Product $p) => [
            'sku' => $p->sku, 'name_en' => $p->trans('name', 'en'), 'name_ar' => $p->trans('name', 'ar'),
            'category' => $p->category?->trans('name', 'en'), 'brand' => $p->brand?->name_en,
            'current_description_en' => mb_substr(strip_tags((string) $p->trans('description', 'en')), 0, 400),
        ])->values()->all();

        return "You are the senior copywriter of Al Bahar Home & Living, a premium retailer in Qatar. Rewrite the wording of these EXISTING products so the catalogue reads professional, consistent and elegant.\n\n"
            ."HARD RULES\n- The product stays exactly the same product. Keep the brand name, every number and every unit (sizes, pack counts, weights, volumes, model numbers) exactly as given.\n"
            ."- Use only facts present in the input. Never invent materials, certifications, antibacterial/eco/halal or other claims, prices, origins or warranties.\n"
            ."- Calm, clear, specific tone. No exclamation marks, no emojis, no filler, no ALL CAPS.\n\n"
            ."STYLE\n- name: Title Case, 'Brand Product Type, Size / Pack' (drop nothing that identifies the product), at most 100 characters.\n"
            ."- short: one sentence, at most 150 characters, says what it is and its main use.\n"
            ."- description: two short paragraphs as <p>…</p>: what it is and where it helps; then the pack/size details and a practical note drawn only from the input.\n"
            ."- seo_title: at most 60 characters. seo_description: at most 155 characters.\n"
            ."- Arabic: natural Modern Standard Arabic for Qatar (not a literal translation), Western digits, same facts as the English.\n\n"
            ."INPUT (JSON)\n".json_encode($items, JSON_UNESCAPED_UNICODE)."\n\n"
            .'Return ONLY a JSON array, one object per input, in the same order, each: '
            .'{"sku":"…","en":{"name":"…","short":"…","description":"<p>…</p><p>…</p>","seo_title":"…","seo_description":"…"},"ar":{…same keys…}}'
            ."\nEvery string on one line (no line breaks inside strings).";
    }

    /** @return array<string, array<string, array<string, string>>> proposals keyed by sku */
    private function parse(?array $json): array
    {
        $text = collect((array) data_get($json, 'content', []))->filter(fn ($b) => isset($b['text']) && ($b['type'] ?? 'text') === 'text')->pluck('text')->implode("\n");
        $a = strpos($text, '[');
        $b = strrpos($text, ']');
        if ($a === false || $b === false || $b <= $a) {
            return [];
        }
        $list = json_decode(substr($text, $a, $b - $a + 1), true, 16, JSON_INVALID_UTF8_SUBSTITUTE);
        $out = [];
        foreach ((array) $list as $row) {
            if (is_array($row) && isset($row['sku'], $row['en'], $row['ar'])) {
                $out[(string) $row['sku']] = ['en' => $this->clean($row['en']), 'ar' => $this->clean($row['ar'])];
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $b @return array<string, string> */
    private function clean(array $b): array
    {
        $s = fn ($v, int $max) => mb_substr(trim(strip_tags((string) $v)), 0, $max);

        return ['name' => $s($b['name'] ?? '', 120), 'short' => $s($b['short'] ?? '', 200),
            'description' => trim(strip_tags((string) ($b['description'] ?? ''), '<p><br><ul><li><strong>')),
            'seo_title' => $s($b['seo_title'] ?? '', 70), 'seo_description' => $s($b['seo_description'] ?? '', 170)];
    }

    /** A proposal is used only if the product is still recognisably the same: all numbers and the brand survive, texts are present. */
    public function valid(Product $p, array $new): bool
    {
        foreach (['en', 'ar'] as $l) {
            $n = $new[$l] ?? null;
            if (! $n || mb_strlen($n['name']) < 6 || $n['short'] === '' || $n['description'] === '' || $n['seo_title'] === '' || $n['seo_description'] === '') {
                return false;
            }
            $old = (string) $p->trans('name', $l);
            preg_match_all('/\d+(?:[.,]\d+)?/', $old, $oldNums);
            $newName = mb_strtolower($n['name']);
            foreach ($oldNums[0] as $num) {
                if (! str_contains($newName, mb_strtolower($num))) {
                    return false;
                }
            }
        }
        $brand = $p->brand?->name_en;
        if ($brand && stripos((string) $p->trans('name', 'en'), $brand) !== false && stripos($new['en']['name'], $brand) === false) {
            return false;
        }

        return true;
    }
}
