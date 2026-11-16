<?php

namespace App\Domain\Cms\Services;

use App\Domain\Cms\Models\Banner;
use App\Domain\Cms\Models\HomepageSection;
use App\Domain\Cms\Support\SliderSettings;
use App\Support\DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The order of the home page: its sections (sliders included) and the slides inside each slider.
 *
 * Every change runs in one transaction under a lock for that list, then reads the list again and renumbers it 1..n, so two admins
 * who move or add things at the same moment queue up instead of corrupting the order (the second one acts on the first one's result).
 * Ties (old data) are broken by creation time then id, so the order is always the same one everybody sees.
 */
final class HomeLayout
{
    private const SECTIONS_LOCK = 'cms.home.sections';

    /** @return Collection<int, HomepageSection> every section in home-page order */
    public function sections(): Collection
    {
        return $this->orderedSections()->get();
    }

    /** @return list<string> */
    public function sectionIds(): array
    {
        return $this->orderedSections()->pluck('id')->all();
    }

    /**
     * Moves a section one step (-1 up, +1 down) among all the home sections.
     *
     * @return array{before: list<string>, after: list<string>}
     */
    public function moveSection(string $id, int $delta): array
    {
        return $this->inSectionsLock(function () use ($id, $delta) {
            $ids = $this->sectionIds();
            $at = array_search($id, $ids, true);
            if ($at === false) {
                $this->fail('gone');
            }

            return $this->applySections($ids, $this->moved($ids, $at, $at + ($delta < 0 ? -1 : 1)));
        });
    }

    /**
     * Puts a section at a zero-based position among all the home sections (drag and drop).
     *
     * @return array{before: list<string>, after: list<string>}
     */
    public function placeSection(string $id, int $position): array
    {
        return $this->inSectionsLock(function () use ($id, $position) {
            $ids = $this->sectionIds();
            $at = array_search($id, $ids, true);
            if ($at === false) {
                $this->fail('gone');
            }

            return $this->applySections($ids, $this->moved($ids, $at, $position));
        });
    }

    /**
     * Undo of a move: the sections named in `$order` go back to that order; sections added meanwhile keep their place at the end.
     *
     * @param  list<string>  $order
     */
    public function restoreSectionOrder(array $order): void
    {
        $this->inSectionsLock(function () use ($order) {
            $ids = $this->sectionIds();
            $known = array_values(array_intersect($order, $ids));
            $this->applySections($ids, array_merge($known, array_values(array_diff($ids, $known))));
        });
    }

    /**
     * Creates a section and puts it where the admin chose: `top`, `bottom` or `after:<section id>`.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addSection(array $attributes, string $at = 'bottom'): HomepageSection
    {
        return $this->inSectionsLock(function () use ($attributes, $at) {
            $ids = $this->sectionIds();
            $section = HomepageSection::create($attributes + ['sort_order' => count($ids) + 1]);
            $position = match (true) {
                $at === 'top' => 0,
                str_starts_with($at, 'after:') && ($i = array_search(substr($at, 6), $ids, true)) !== false => $i + 1,
                default => count($ids),
            };
            array_splice($ids, $position, 0, [$section->id]);
            $this->applySections($ids, $ids);

            return $section->refresh();
        });
    }

    /** Deletes a section; its slides go to the bin with it (restorable until the nightly purge). */
    public function removeSection(HomepageSection $section): void
    {
        $this->inSectionsLock(function () use ($section) {
            $section->delete();
            $this->applySections($this->sectionIds(), $this->sectionIds());
        });
    }

    /** Brings a deleted section back at the end of the page, or where it was when that place is still free. */
    public function restoreSection(string $id): ?HomepageSection
    {
        return $this->inSectionsLock(function () use ($id) {
            $section = HomepageSection::onlyTrashed()->find($id);
            $section?->restore();

            if ($section) {
                $ids = $this->sectionIds();
                $this->applySections($ids, $ids);
            }

            return $section;
        });
    }

    /** @return Collection<int, Banner> the slides of a slider (or of an "other places" placement) in order */
    public function slides(?string $sectionId, ?string $placement = null): Collection
    {
        return $this->orderedSlides($sectionId, $placement)->get();
    }

    /**
     * Adds a slide at the end of its slider (or placement).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addSlide(array $attributes, ?string $sectionId, string $placement): Banner
    {
        return $this->inSlidesLock($this->slideKey($sectionId, $placement), function () use ($attributes, $sectionId, $placement) {
            if ($sectionId && $this->orderedSlides($sectionId, $placement)->count() >= SliderSettings::MAX_SLIDES) {
                $this->fail('too_many_slides', ['max' => SliderSettings::MAX_SLIDES]);
            }
            $next = (int) $this->orderedSlides($sectionId, $placement)->max('sort_order') + 1;

            return Banner::create($attributes + ['homepage_section_id' => $sectionId, 'placement' => $placement, 'sort_order' => $next]);
        });
    }

    /**
     * Moves a slide one step (-1 up, +1 down) inside its slider.
     *
     * @return array{before: list<string>, after: list<string>}
     */
    public function moveSlide(string $slideId, int $delta): array
    {
        return $this->changeSlideOrder($slideId, fn (array $ids, int $at) => $this->moved($ids, $at, $at + ($delta < 0 ? -1 : 1)));
    }

    /**
     * Puts a slide at a zero-based position inside its slider (drag and drop).
     *
     * @return array{before: list<string>, after: list<string>}
     */
    public function placeSlide(string $slideId, int $position): array
    {
        return $this->changeSlideOrder($slideId, fn (array $ids, int $at) => $this->moved($ids, $at, $position));
    }

    /** Undo of a move or restore of a deleted slide: the slides named go back to that order inside the slider. @param  list<string>  $order */
    public function restoreSlideOrder(?string $sectionId, string $placement, array $order): void
    {
        $this->inSlidesLock($this->slideKey($sectionId, $placement), function () use ($sectionId, $placement, $order) {
            $ids = $this->orderedSlides($sectionId, $placement)->pluck('id')->all();
            $known = array_values(array_intersect($order, $ids));
            $this->applySlides($ids, array_merge($known, array_values(array_diff($ids, $known))));
        });
    }

    /** Puts a duplicate right after its original. */
    public function placeAfter(Banner $slide, Banner $after): void
    {
        $this->inSlidesLock($this->slideKey($after->homepage_section_id, $after->placement), function () use ($slide, $after) {
            $ids = array_values(array_diff($this->orderedSlides($after->homepage_section_id, $after->placement)->pluck('id')->all(), [$slide->id]));
            $at = array_search($after->id, $ids, true);
            array_splice($ids, $at === false ? count($ids) : $at + 1, 0, [$slide->id]);
            $this->applySlides($ids, $ids);
        });
    }

    /** Takes a slide (maybe from another slider) into a slider / placement, at its end. */
    public function relocateSlide(Banner $slide, ?string $sectionId, string $placement): void
    {
        $from = $this->slideKey($slide->homepage_section_id, $slide->placement);
        $to = $this->slideKey($sectionId, $placement);
        if ($from === $to) {
            return;
        }
        // Both lists are locked, always in the same (alphabetical) order, so two admins moving slides the other way cannot deadlock.
        $keys = [$from, $to];
        sort($keys);
        DB::transaction(function () use ($slide, $sectionId, $placement, $keys) {
            foreach ($keys as $key) {
                $this->lock($key);
            }
            if ($sectionId && $this->orderedSlides($sectionId, $placement)->count() >= SliderSettings::MAX_SLIDES) {
                $this->fail('too_many_slides', ['max' => SliderSettings::MAX_SLIDES]);
            }
            $slide->forceFill(['homepage_section_id' => $sectionId, 'placement' => $placement,
                'sort_order' => (int) $this->orderedSlides($sectionId, $placement)->max('sort_order') + 1])->save();
        });
    }

    /** @return Builder<HomepageSection> */
    private function orderedSections(): Builder
    {
        return HomepageSection::query()->orderBy('sort_order')->orderBy('created_at')->orderBy('id');
    }

    /** @return Builder<Banner> */
    private function orderedSlides(?string $sectionId, ?string $placement): Builder
    {
        return Banner::query()
            ->when($sectionId, fn (Builder $q) => $q->where('homepage_section_id', $sectionId), fn (Builder $q) => $q->whereNull('homepage_section_id')->where('placement', $placement))
            ->orderBy('sort_order')->orderBy('created_at')->orderBy('id');
    }

    /**
     * @param  callable(list<string>, int): list<string>  $reorder
     * @return array{before: list<string>, after: list<string>}
     */
    private function changeSlideOrder(string $slideId, callable $reorder): array
    {
        $slide = Banner::find($slideId) ?? $this->fail('gone');

        return $this->inSlidesLock($this->slideKey($slide->homepage_section_id, $slide->placement), function () use ($slide, $reorder) {
            $ids = $this->orderedSlides($slide->homepage_section_id, $slide->placement)->pluck('id')->all();
            $at = array_search($slide->id, $ids, true);
            if ($at === false) {
                $this->fail('gone');
            }

            return $this->applySlides($ids, $reorder($ids, $at));
        });
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function moved(array $ids, int $from, int $to): array
    {
        $id = $ids[$from];
        array_splice($ids, $from, 1);
        array_splice($ids, max(0, min($to, count($ids))), 0, [$id]);

        return $ids;
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return array{before: list<string>, after: list<string>}
     */
    private function applySections(array $before, array $after): array
    {
        foreach (array_values($after) as $i => $id) {
            HomepageSection::query()->whereKey($id)->where('sort_order', '!=', $i + 1)->update(['sort_order' => $i + 1]);
        }
        HomepageService::flush();

        return ['before' => $before, 'after' => array_values($after)];
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return array{before: list<string>, after: list<string>}
     */
    private function applySlides(array $before, array $after): array
    {
        foreach (array_values($after) as $i => $id) {
            Banner::query()->whereKey($id)->where('sort_order', '!=', $i + 1)->update(['sort_order' => $i + 1]);
        }
        HomepageService::flush();

        return ['before' => $before, 'after' => array_values($after)];
    }

    private function slideKey(?string $sectionId, ?string $placement): string
    {
        return 'cms.home.slides.'.($sectionId ?: 'placement-'.$placement);
    }

    /** @template T @param  callable(): T  $work @return T */
    private function inSectionsLock(callable $work): mixed
    {
        return DB::transaction(function () use ($work) {
            $this->lock(self::SECTIONS_LOCK);

            return $work();
        });
    }

    /** @template T @param  callable(): T  $work @return T */
    private function inSlidesLock(string $key, callable $work): mixed
    {
        return DB::transaction(function () use ($key, $work) {
            $this->lock($key);

            return $work();
        });
    }

    /** @param  array<string, mixed>  $replace */
    private function fail(string $key, array $replace = []): never
    {
        throw new DomainException('banners_'.$key, __('banners_admin.'.$key, $replace));
    }

    /** A transaction-scoped advisory lock: whoever asks second waits for the first commit, then reads the list as the first left it. */
    private function lock(string $key): void
    {
        DB::selectOne('select pg_advisory_xact_lock(hashtext(?))', [$key]);
    }
}
