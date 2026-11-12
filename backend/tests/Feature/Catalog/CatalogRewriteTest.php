<?php

use App\Domain\Catalog\Services\CatalogRewriter;
use App\Domain\Settings\Services\Settings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function rewriteAnswer(string $sku, string $enName, string $arName): array
{
    $b = fn ($n) => ['name' => $n, 'short' => 'A clear short line.', 'description' => '<p>One.</p><p>Two.</p>', 'seo_title' => 'SEO title', 'seo_description' => 'SEO description'];

    return ['content' => [['type' => 'thinking', 'thinking' => '…'], ['type' => 'text', 'text' => "```json\n".json_encode([['sku' => $sku, 'en' => $b($enName), 'ar' => $b($arName)]], JSON_UNESCAPED_UNICODE)."\n```"]]];
}

it('rewrites wording only when the product stays recognisable, backs up first, keeps the old name searchable, and can be restored', function () {
    Storage::fake('local');
    $w = commerceWorld();
    $p = $w->p1;
    $p->translations()->where('locale', 'en')->update(['name' => 'Cling Film Roll, 45 cm × 300 m']);
    $p->translations()->where('locale', 'ar')->update(['name' => 'رول فيلم تغليف، 45 سم × 300 متر']);
    $slug = $p->translations()->where('locale', 'en')->value('slug');
    app(Settings::class)->set('ai.anthropic_api_key', 'sk-ant-test-key-1234567890', secret: true);
    $r = app(CatalogRewriter::class);

    // A rewrite that loses the pack size is rejected, the product keeps its wording.
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(rewriteAnswer($p->sku, 'Premium Cling Film Roll', 'رول فيلم تغليف فاخر'))
        ->push(rewriteAnswer($p->sku, 'Professional Cling Film Roll, 45 cm × 300 m', 'رول فيلم تغليف احترافي، 45 سم × 300 متر'))]);
    [$ok, $bad] = $r->generate('catalog-rewrite/p.jsonl', category: null);
    expect($ok)->toBe(0)->and($bad)->toBeGreaterThanOrEqual(1);

    // A faithful rewrite is accepted (answer carries a thinking block first and sits in a code fence).
    [$ok] = $r->generate('catalog-rewrite/p.jsonl');
    expect($ok)->toBe(1)->and($p->translations()->where('locale', 'en')->value('name'))->toBe('Cling Film Roll, 45 cm × 300 m'); // nothing applied yet

    $n = $r->apply('catalog-rewrite/p.jsonl', 'catalog-rewrite/backup.json');
    $en = $p->translations()->where('locale', 'en')->first();
    expect($n)->toBe(1)->and($en->name)->toBe('Professional Cling Film Roll, 45 cm × 300 m')->and($en->slug)->toBe($slug)
        ->and($en->search_keywords)->toContain('Cling Film Roll, 45 cm × 300 m')->and($en->short_description)->toBe('A clear short line.');
    expect(Storage::disk('local')->exists('catalog-rewrite/backup.json'))->toBeTrue();

    // Searching by the old name still finds it (it was added to the keywords and the search text).
    expect($en->search_text)->toContain('cling film roll');

    expect($r->restore('catalog-rewrite/backup.json'))->toBeGreaterThanOrEqual(2);
    expect($p->translations()->where('locale', 'en')->value('name'))->toBe('Cling Film Roll, 45 cm × 300 m');
});
