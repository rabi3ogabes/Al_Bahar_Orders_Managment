<?php

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\CategoryTranslation;
use Illuminate\Support\Facades\DB;

it('renames the source store brand to Al Bahar and is idempotent', function () {
    $cat = Category::create(['slug' => 'al-marwah-specials', 'sort_order' => 1]);
    CategoryTranslation::create(['category_id' => $cat->id, 'locale' => 'en', 'name' => 'Al Marwah Specials', 'description' => 'By Almarwah']);
    CategoryTranslation::create(['category_id' => $cat->id, 'locale' => 'ar', 'name' => 'منتجات المروة']);

    $this->artisan('catalog:rebrand')->assertSuccessful();

    expect(DB::table('categories')->value('slug'))->toBe('al-bahar-specials')
        ->and(CategoryTranslation::where('locale', 'en')->value('name'))->toBe('Al Bahar Specials')
        ->and(CategoryTranslation::where('locale', 'en')->value('description'))->toBe('By Al Bahar')
        ->and(CategoryTranslation::where('locale', 'ar')->value('name'))->toBe('منتجات البحار');

    $this->artisan('catalog:rebrand')->expectsOutputToContain('Changed 0')->assertSuccessful();
});
