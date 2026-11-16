<?php

/*
 * Real concurrency: forked processes, each with its own PostgreSQL connection, move and add home sections and slides at the same moment.
 * HomeLayout orders under a lock, so the result is always one valid order (every position 1..n used exactly once), never a corrupted one.
 * Uses committed data (DatabaseTruncation), so it is excluded from the transactional Feature suite.
 * Run: DB_DATABASE=<scratch database> ./vendor/bin/pest tests/Concurrency/HomeBannerOrderRaceTest.php
 */

use App\Domain\Cms\Models\Banner;
use App\Domain\Cms\Models\HomepageSection;
use App\Domain\Cms\Services\HomeLayout;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, DatabaseTruncation::class);

/** Fork $n children running $work($i); returns their exit codes (0 = success). */
function raceHomeLayout(int $n, Closure $work): array
{
    DB::disconnect();
    $pids = [];
    for ($i = 0; $i < $n; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::purge();
            try {
                $code = $work($i) === false ? 1 : 0;
            } catch (Throwable) {
                $code = 1;
            }
            exit($code); // child
        }
        $pids[] = $pid;
    }
    $codes = [];
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $codes[] = pcntl_wexitstatus($status);
    }
    DB::purge();

    return $codes;
}

it('keeps the home sections in one valid order when many admins move them at once', function () {
    $sections = collect(range(1, 6))->map(fn (int $i) => HomepageSection::create(['type' => $i % 2 ? 'banners' : 'categories', 'layout' => 'carousel', 'status' => 'published', 'sort_order' => 0]));

    $codes = raceHomeLayout(12, function (int $i) use ($sections) {
        $layout = app(HomeLayout::class);
        $layout->moveSection($sections[$i % 6]->id, $i % 3 === 0 ? 1 : -1);
        $layout->placeSection($sections[($i + 2) % 6]->id, $i % 4);

        return true;
    });

    $orders = HomepageSection::orderBy('sort_order')->pluck('sort_order')->all();
    expect($codes)->each->toBe(0)->and($orders)->toBe([1, 2, 3, 4, 5, 6])->and(HomepageSection::distinct()->count('id'))->toBe(6);
})->skip(! function_exists('pcntl_fork'), 'pcntl not available');

it('adds slides at the same moment without two of them sharing a place, and stops at the limit', function () {
    $slider = HomepageSection::create(['type' => 'banners', 'layout' => 'carousel', 'status' => 'published', 'sort_order' => 1]);

    $codes = raceHomeLayout(8, fn (int $i) => (bool) app(HomeLayout::class)->addSlide(['image_en_path' => "banners/race-{$i}.jpg", 'status' => 'published'], $slider->id, 'home_hero'));

    expect($codes)->each->toBe(0)->and(Banner::where('homepage_section_id', $slider->id)->orderBy('sort_order')->pluck('sort_order')->all())->toBe([1, 2, 3, 4, 5, 6, 7, 8]);

    // 14 more, with room for 12: exactly 12 get in, the rest are refused, and the places stay 1..20.
    $codes = raceHomeLayout(14, fn (int $i) => (bool) app(HomeLayout::class)->addSlide(['image_en_path' => "banners/more-{$i}.jpg", 'status' => 'published'], $slider->id, 'home_hero'));
    expect(count(array_filter($codes, fn ($c) => $c === 0)))->toBe(12)
        ->and(Banner::where('homepage_section_id', $slider->id)->orderBy('sort_order')->pluck('sort_order')->all())->toBe(range(1, 20));
})->skip(! function_exists('pcntl_fork'), 'pcntl not available');

it('keeps the slides of a slider in one valid order when many admins move them at once', function () {
    $slider = HomepageSection::create(['type' => 'banners', 'layout' => 'carousel', 'status' => 'published', 'sort_order' => 1]);
    $slides = collect(range(1, 6))->map(fn (int $i) => Banner::create(['homepage_section_id' => $slider->id, 'placement' => 'home_hero', 'image_en_path' => "banners/s{$i}.jpg", 'status' => 'published', 'sort_order' => 0]));

    $codes = raceHomeLayout(12, function (int $i) use ($slides) {
        $layout = app(HomeLayout::class);
        $layout->moveSlide($slides[$i % 6]->id, $i % 2 === 0 ? 1 : -1);
        $layout->placeSlide($slides[($i + 3) % 6]->id, $i % 5);

        return true;
    });

    expect($codes)->each->toBe(0)->and(Banner::where('homepage_section_id', $slider->id)->orderBy('sort_order')->pluck('sort_order')->all())->toBe([1, 2, 3, 4, 5, 6]);
})->skip(! function_exists('pcntl_fork'), 'pcntl not available');
