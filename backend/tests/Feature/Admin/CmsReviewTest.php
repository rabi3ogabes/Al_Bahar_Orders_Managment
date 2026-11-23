<?php

use App\Domain\Cms\Models\CmsPage;
use App\Livewire\Admin\Content\PageIndex;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->marketing = staffWithRole('marketing_manager');
});

it('refuses to publish a page that has no Arabic or no English text, but saves it as a draft', function () {
    $this->actingAs($this->marketing);
    $c = Livewire::test(PageIndex::class)->call('create')->set('slug', 'about-us')->set('tr.en.title', 'About')->set('tr.ar.title', 'من نحن')
        ->set('tr.en.body', '<p>Hello</p>')->call('publish')->assertHasErrors('tr.ar.body');
    expect(CmsPage::count())->toBe(0);
    $c->call('save')->assertHasNoErrors();
    expect(CmsPage::firstOrFail()->status)->toBe('draft');
    $c->set('tr.ar.body', '<p>أهلا</p>')->call('publish')->assertHasNoErrors();
    expect(CmsPage::firstOrFail()->status)->toBe('published');
});

it('serves the English text of a published page when its Arabic body is empty', function () {
    $p = CmsPage::create(['slug' => 'faq', 'status' => 'published', 'published_at' => now()]);
    $p->translations()->create(['locale' => 'en', 'title' => 'FAQ', 'body' => '<p>English body</p>']);
    $p->translations()->create(['locale' => 'ar', 'title' => 'الأسئلة', 'body' => '']);
    $this->withHeader('Accept-Language', 'ar')->getJson('/api/v1/pages/faq')->assertOk()->assertJsonPath('data.body', '<p>English body</p>');
});
