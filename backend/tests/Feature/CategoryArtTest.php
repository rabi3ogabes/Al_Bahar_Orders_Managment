<?php

use App\Domain\Catalog\Models\Category;
use Illuminate\Support\Facades\Storage;

it('attaches the shared cover design to categories by slug', function () {
    Storage::fake(config('platform.media.disk'));
    $dir = sys_get_temp_dir().'/cat-art-'.uniqid();
    mkdir($dir);
    file_put_contents("{$dir}/tissues.webp", 'webp-bytes');
    $with = Category::create(['slug' => 'tissues', 'sort_order' => 1]);
    $without = Category::create(['slug' => 'no-art', 'sort_order' => 2]);

    $this->artisan('catalog:category-art', ['--dir' => $dir])->expectsOutputToContain('Cover set on 1 category')->assertSuccessful();

    expect($with->fresh()->image_path)->toBe('categories/tissues.webp')->and($without->fresh()->image_path)->toBeNull();
    Storage::disk(config('platform.media.disk'))->assertExists('categories/tissues.webp');
});
