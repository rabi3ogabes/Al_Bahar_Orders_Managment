<?php

namespace App\Domain\Cms\Services;

use App\Domain\Cms\Models\Banner;
use App\Domain\Cms\Models\HomepageSection;
use Illuminate\Support\Facades\Storage;

/**
 * Deleting a slide or a slider in the dashboard only moves it to the bin (so Undo works). This removes what has been in the bin for a
 * while, files included. A file is only deleted when no other slide still points at it.
 */
final class HomeContentPurger
{
    /** @return array{sliders: int, slides: int} how many were removed for good */
    public function purge(int $olderThanDays = 7): array
    {
        $cut = now()->subDays($olderThanDays);
        $counts = ['sliders' => 0, 'slides' => 0];

        foreach (HomepageSection::onlyTrashed()->where('deleted_at', '<', $cut)->get() as $section) {
            foreach (Banner::withTrashed()->where('homepage_section_id', $section->id)->get() as $slide) {
                $this->forget($slide);
                $counts['slides']++;
            }
            $section->forceDelete();
            $counts['sliders']++;
        }
        foreach (Banner::onlyTrashed()->where('deleted_at', '<', $cut)->get() as $slide) {
            $this->forget($slide);
            $counts['slides']++;
        }

        return $counts;
    }

    private function forget(Banner $slide): void
    {
        $disk = Storage::disk(config('platform.media.disk'));
        foreach ([$slide->image_en_path, $slide->image_ar_path] as $path) {
            if (! $path || str_starts_with($path, 'http')) {
                continue;
            }
            $shared = Banner::withTrashed()->whereKeyNot($slide->id)->where(fn ($q) => $q->where('image_en_path', $path)->orWhere('image_ar_path', $path))->exists();
            if (! $shared) {
                $disk->delete($path);
            }
        }
        $slide->forceDelete();
    }
}
