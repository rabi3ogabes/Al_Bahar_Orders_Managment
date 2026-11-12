<?php

use App\Domain\Catalog\Models\ProductPhotoRequest;
use App\Domain\Catalog\Services\MediaService;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Catalog\ProductEditor;
use App\Livewire\Admin\Catalog\ProductMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function catalogStaff(): User
{
    return actingAsStaff(staffWithRole('super_admin'));
}

function productWithPhoto(): array
{
    $w = commerceWorld();
    $img = app(MediaService::class)->storeProductImage($w->p1, UploadedFile::fake()->image('p.png', 800, 600));

    return [$w, $w->p1, $img];
}

it('writes the description from the title with Claude, text only', function () {
    catalogStaff();
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['text' => json_encode([
        'en' => ['short' => 'A calm linen throw.', 'description' => '<p>Soft linen.</p><script>x()</script>'],
        'ar' => ['short' => 'غطاء كتان هادئ.', 'description' => '<p>كتان ناعم.</p>'],
    ])]]])]);
    app(Settings::class)->set('ai.anthropic_api_key', 'sk-ant-test-key-1234567890', secret: true);

    $r = $this->postJson('/api/v1/admin/catalog/ai/describe', ['title_en' => 'Linen Throw Sand'])->assertOk();
    expect($r->json('data.en.short'))->toBe('A calm linen throw.')->and($r->json('data.en.description'))->not->toContain('script')->and($r->json('data.ar.short'))->toContain('كتان');
    Http::assertSent(fn ($req) => $req->hasHeader('x-api-key', 'sk-ant-test-key-1234567890') && str_contains($req['messages'][0]['content'], 'Linen Throw Sand'));
});

it('says so when the AI writer is not set up, or the title is missing, or Claude fails', function () {
    catalogStaff();
    $this->postJson('/api/v1/admin/catalog/ai/describe', ['title_en' => 'Vase'])->assertStatus(422)->assertJsonPath('code', 'ai_not_configured');
    app(Settings::class)->set('ai.anthropic_api_key', 'sk-ant-test-key-1234567890', secret: true);
    $this->postJson('/api/v1/admin/catalog/ai/describe', [])->assertStatus(422)->assertJsonPath('code', 'ai_title_required');
    Http::fake(['api.anthropic.com/*' => Http::response('nope', 500)]);
    $this->postJson('/api/v1/admin/catalog/ai/describe', ['title_en' => 'Vase'])->assertStatus(502)->assertJsonPath('code', 'ai_failed');
});

it('runs a photo request end to end: ask, claim, deliver, approve — nothing is public before approval', function () {
    Storage::fake('public');
    config(['platform.media.disk' => 'public']);
    [$w, $product, $img] = productWithPhoto();
    catalogStaff();
    $before = $product->images()->count();

    $req = $this->postJson("/api/v1/admin/catalog/products/{$product->id}/photo-requests", ['image_id' => $img->id, 'kind' => 'clean_background', 'notes' => 'plain white'])->assertCreated()->json('data');
    expect($req['status'])->toBe('requested')->and($req['source']['url'])->toBeString();
    $this->postJson("/api/v1/admin/catalog/products/{$product->id}/photo-requests", ['image_id' => $img->id, 'kind' => 'clean_background'])->assertStatus(422)->assertJsonPath('code', 'photo_request_open');
    expect($this->getJson('/api/v1/admin/catalog/photo-requests?status=requested')->json('data'))->toHaveCount(1);

    $this->postJson("/api/v1/admin/catalog/photo-requests/{$req['id']}/claim")->assertOk()->assertJsonPath('data.status', 'processing');
    $this->postJson("/api/v1/admin/catalog/photo-requests/{$req['id']}/claim")->assertStatus(422);
    $done = $this->post("/api/v1/admin/catalog/photo-requests/{$req['id']}/results", ['images' => [UploadedFile::fake()->image('a.png', 900, 900), UploadedFile::fake()->image('b.jpg', 900, 900)]], ['Accept' => 'application/json'])->assertCreated()->json('data');
    expect($done['status'])->toBe('ready')->and($done['results'])->toHaveCount(2)->and($product->images()->count())->toBe($before); // still not public

    $this->postJson("/api/v1/admin/catalog/photo-results/{$done['results'][0]['id']}/use", ['as_cover' => true])->assertOk();
    expect($product->images()->count())->toBe($before + 1)->and($product->images()->orderBy('sort_order')->first()->width)->toBe(900);
    $this->postJson("/api/v1/admin/catalog/photo-results/{$done['results'][0]['id']}/use")->assertStatus(422)->assertJsonPath('code', 'photo_result_decided');
    $this->postJson("/api/v1/admin/catalog/photo-results/{$done['results'][1]['id']}/discard")->assertOk();
    expect($product->images()->count())->toBe($before + 1);
});

it('needs a source photo except for new designs, and refuses a photo of another product', function () {
    [$w, $product, $img] = productWithPhoto();
    catalogStaff();
    $this->postJson("/api/v1/admin/catalog/products/{$product->id}/photo-requests", ['kind' => 'studio_white'])->assertStatus(422)->assertJsonPath('code', 'photo_source_required');
    $this->postJson("/api/v1/admin/catalog/products/{$product->id}/photo-requests", ['kind' => 'new_design', 'count' => 3])->assertCreated();
    $other = $w->p2 ?? null;
    if ($other) {
        $this->postJson("/api/v1/admin/catalog/products/{$other->id}/photo-requests", ['image_id' => $img->id, 'kind' => 'lifestyle'])->assertStatus(404);
    }
});

it('keeps photo studio away from staff without catalog access and from non-images', function () {
    Storage::fake('public');
    config(['platform.media.disk' => 'public']);
    [$w, $product] = productWithPhoto();
    actingAsStaff(staffWithRole('delivery_employee'));
    $this->getJson('/api/v1/admin/catalog/photo-requests')->assertForbidden();
    catalogStaff();
    $r = ProductPhotoRequest::create(['product_id' => $product->id, 'kind' => 'new_design']);
    $this->post("/api/v1/admin/catalog/photo-requests/{$r->id}/results", ['images' => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]], ['Accept' => 'application/json'])->assertStatus(422);
});

it('fills both languages from the title in the product editor and shows the studio panel on the photos tab', function () {
    Storage::fake('public');
    config(['platform.media.disk' => 'public']);
    [$w, $product, $img] = productWithPhoto();
    $admin = catalogStaff();
    app(Settings::class)->set('ai.anthropic_api_key', 'sk-ant-test-key-1234567890', secret: true);
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['text' => json_encode(['en' => ['short' => 'Short EN', 'description' => '<p>Long EN</p>'], 'ar' => ['short' => 'قصير', 'description' => '<p>طويل</p>']])]]])]);

    Livewire::actingAs($admin)->test(ProductEditor::class, ['product' => $product])
        ->call('describeWithAi')->assertSet('tr.en.short_description', 'Short EN')->assertSet('tr.ar.description', '<p>طويل</p>');

    Livewire::actingAs($admin)->test(ProductMedia::class, ['product' => $product])
        ->assertSee(__('catalog_admin.studio_title'))
        ->call('askPhoto', $img->id, 'clean_background')->assertSee(__('catalog_admin.studio_kind_clean_background'))
        ->call('askPhoto', $img->id, 'clean_background'); // duplicate is refused with a toast, not an error page
    expect(ProductPhotoRequest::where('product_id', $product->id)->count())->toBe(1);
});

it('translates an Arabic-only title into the English name and writes both descriptions, even if the model wraps its answer', function () {
    Storage::fake('public');
    config(['platform.media.disk' => 'public']);
    [$w, $product] = productWithPhoto();
    $admin = catalogStaff();
    app(Settings::class)->set('ai.anthropic_api_key', 'sk-ant-test-key-1234567890', secret: true);
    $answer = "Here you go:\n```json\n".json_encode(['name' => ['en' => 'Wooden Coffee Table', 'ar' => 'طاولة قهوة خشبية'], 'en' => ['short' => 'A warm wooden table.', 'description' => '<p>Solid wood.</p>'], 'ar' => ['short' => 'طاولة خشبية دافئة.', 'description' => '<p>خشب متين.</p>']], JSON_UNESCAPED_UNICODE)."\n```";
    Http::fake(['api.anthropic.com/*' => Http::sequence()->push(['content' => [['text' => 'not json at all']]])->push(['content' => [['text' => $answer]]])->whenEmpty(Http::response(['content' => [['text' => $answer]]]))]);

    Livewire::actingAs($admin)->test(ProductEditor::class)
        ->set('tr.ar.name', 'طاولة قهوة خشبية')->set('tr.en.name', '')
        ->call('describeWithAi')
        ->assertSet('tr.en.name', 'Wooden Coffee Table')->assertSet('tr.ar.name', 'طاولة قهوة خشبية')
        ->assertSet('tr.en.short_description', 'A warm wooden table.')->assertSet('tr.ar.description', '<p>خشب متين.</p>');
    Http::assertSentCount(2); // the first unreadable answer was retried once

    // A name you typed is never overwritten.
    Livewire::actingAs($admin)->test(ProductEditor::class)
        ->set('tr.ar.name', 'طاولة قهوة خشبية')->set('tr.en.name', 'My Own English Name')->call('describeWithAi')->assertSet('tr.en.name', 'My Own English Name');
});

it('reads the answer even when the model puts a thinking block before the text', function () {
    catalogStaff();
    app(Settings::class)->set('ai.anthropic_api_key', 'sk-ant-test-key-1234567890', secret: true);
    $body = json_encode(['name' => ['en' => 'Table', 'ar' => 'طاولة'], 'en' => ['short' => 'S', 'description' => '<p>D</p>'], 'ar' => ['short' => 'ق', 'description' => '<p>و</p>']], JSON_UNESCAPED_UNICODE);
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'thinking', 'thinking' => '...'], ['type' => 'text', 'text' => $body]]])]);
    $this->postJson('/api/v1/admin/catalog/ai/describe', ['title_ar' => 'طاولة'])->assertOk()->assertJsonPath('data.name.en', 'Table')->assertJsonPath('data.ar.short', 'ق');
});
