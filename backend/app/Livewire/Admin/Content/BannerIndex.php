<?php

namespace App\Livewire\Admin\Content;

use App\Domain\Auctions\Models\Auction;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CategoryService;
use App\Domain\Cms\Models\Banner;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Cms\Models\HomepageSection;
use App\Domain\Cms\Services\BannerLinks;
use App\Domain\Cms\Services\HomeLayout;
use App\Domain\Cms\Support\SliderSettings;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Livewire\Admin\Content\Concerns\ManagesSliders;
use App\Livewire\Admin\Content\Concerns\ManagesSlides;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Content → Home banners. The page is a map of the home page: every section in order, with each SLIDER (a `banners` section) opened up
 * to show its slides. The admin adds sliders where he wants them, moves them up or down among the other sections, and fills them with
 * slides (an image per language, an optional link, a schedule). Banners of the other places (category top, auctions) are managed below.
 */
class BannerIndex extends Component
{
    use InteractsWithAdmin, ManagesSliders, ManagesSlides;

    /** Placements outside the home page, in the order they are listed. */
    public const PLACEMENTS = Banner::OTHER_PLACEMENTS;

    public bool $showPreview = false;

    #[Locked]
    public ?string $previewId = null;

    public function mount(): void
    {
        abort_unless($this->can('cms.view'), 403);
    }

    public function openPreview(string $id): void
    {
        abort_unless($this->can('cms.view'), 403);
        $this->previewId = HomepageSection::where('type', 'banners')->findOrFail($id)->id;
        $this->showPreview = true;
    }

    /** The name staff see for a section: the slider's own name, its public heading, or "Slider 2". */
    public static function sectionName(HomepageSection $s, int $number): string
    {
        $ar = app()->getLocale() === 'ar';
        $heading = trim((string) ($ar ? ($s->title_ar ?: $s->title_en) : $s->title_en));
        if ($s->isSlider()) {
            return $s->adminName() ?: ($heading ?: __('banners_admin.slider_n', ['n' => $number]));
        }

        return $heading ?: __('content_admin.type_'.$s->type);
    }

    public function render(HomeLayout $layout, BannerLinks $links, CategoryService $categories)
    {
        $sections = $layout->sections();
        $sliderIds = $sections->where('type', 'banners')->pluck('id')->all();
        $banners = Banner::query()->where(fn ($q) => $q->whereIn('homepage_section_id', $sliderIds)->orWhereNull('homepage_section_id'))
            ->orderBy('sort_order')->orderBy('created_at')->orderBy('id')->get();
        $described = $links->describeMany($banners);
        $inSliders = $banners->whereNotNull('homepage_section_id')->groupBy('homepage_section_id');
        $elsewhere = $banners->whereNull('homepage_section_id');
        $places = collect(self::PLACEMENTS)->mapWithKeys(fn ($p) => [$p => $elsewhere->where('placement', $p)->values()]);
        $unassigned = $elsewhere->reject(fn (Banner $b) => in_array($b->placement, self::PLACEMENTS, true))->values();

        $sliderNumber = 0;
        $blocks = $sections->map(function (HomepageSection $s, int $i) use (&$sliderNumber, $inSliders, $sections) {
            $n = $s->isSlider() ? ++$sliderNumber : 0;
            $slides = $s->isSlider() ? ($inSliders[$s->id] ?? collect()) : collect();

            return (object) ['section' => $s, 'name' => self::sectionName($s, $n), 'position' => $i + 1, 'total' => $sections->count(), 'slides' => $slides,
                'live' => $slides->filter(fn (Banner $b) => $b->isLive())->count(), 'settings' => $s->isSlider() ? $s->slider() : null,
                'after' => $i > 0 ? self::sectionName($sections[$i - 1], 0) : null];
        });

        return view('livewire.admin.content.banners', [
            'blocks' => $blocks, 'places' => $places, 'unassigned' => $unassigned, 'described' => $described,
            'canEdit' => $this->can('cms.edit'), 'canCreate' => $this->can('cms.create'), 'canDelete' => $this->can('cms.delete'),
            'maxSlides' => SliderSettings::MAX_SLIDES,
            'sliderOptions' => $blocks->filter(fn ($b) => $b->section->isSlider())->mapWithKeys(fn ($b) => ['slider:'.$b->section->id => $b->name])->all(),
            'positions' => $this->showSlider && ! $this->sliderId ? $blocks->map(fn ($b) => ['id' => $b->section->id, 'name' => $b->name])->all() : [],
            'editor' => $this->showSlide ? $this->editorData($links, $categories, $blocks) : null,
            'preview' => $this->showPreview ? $this->previewData($blocks, $links) : null,
        ])->title(__('nav.banners'));
    }

    /**
     * Everything the slide window needs, loaded only while it is open: the slide being edited, its slider's look, the friendly summary of
     * the chosen link and the choices of the link picker for the chosen kind of link.
     *
     * @param  Collection<int, object>  $blocks
     * @return array<string, mixed>
     */
    private function editorData(BannerLinks $links, CategoryService $categories, Collection $blocks): array
    {
        $current = $this->slideId ? Banner::find($this->slideId) : null;
        $dest = (string) ($this->slide['destination'] ?? '');
        $block = str_starts_with($dest, 'slider:') ? $blocks->first(fn ($b) => 'slider:'.$b->section->id === $dest) : null;
        $settings = $block?->settings ?? SliderSettings::normalize(null);
        $aspect = $block ? $settings['aspect'] : '16:7';
        $type = (string) ($this->slide['link_type'] ?? '');
        $value = (string) ($this->slide['link_value'] ?? '');
        $term = trim($this->linkSearch);
        $like = '%'.addcslashes($term, '%_\\').'%';

        $choices = match ($type) {
            'category' => $this->flatten($categories->tree()),
            'page' => CmsPage::with('translations')->where('status', 'published')->orderBy('slug')->limit(100)->get()->map(function (CmsPage $p) {
                $t = $p->translations->firstWhere('locale', app()->getLocale()) ?? $p->translations->firstWhere('locale', 'en') ?? $p->translations->first();

                return ['value' => $p->slug, 'label' => (string) ($t?->title ?: $p->slug), 'sub' => '/'.$p->slug, 'depth' => 0];
            })->all(),
            'brand' => Brand::where('status', 'active')->orderBy('sort_order')->orderBy('name_en')->limit(200)->get()
                ->map(fn (Brand $b) => ['value' => $b->id, 'label' => (string) $b->localized('name'), 'sub' => null, 'depth' => 0])->all(),
            'auction' => Auction::with('translations')->where('status', '!=', 'draft')->latest('starts_at')->limit(60)->get()
                ->map(fn (Auction $a) => ['value' => $a->id, 'label' => (string) $a->trans('title'), 'sub' => $a->reference, 'depth' => 0])->all(),
            'product' => mb_strlen($term) >= 2 ? Product::active()->with('translations')->where(fn ($q) => $q->where('sku', 'ilike', $like)
                ->orWhereHas('translations', fn ($t) => $t->where('name', 'ilike', $like)))->limit(8)->get()
                ->map(fn (Product $p) => ['value' => $p->id, 'label' => (string) $p->trans('name'), 'sub' => $p->sku, 'depth' => 0])->all() : [],
            default => [],
        };

        return [
            'current' => $current, 'aspect' => $aspect, 'settings' => $settings, 'sliderName' => $block?->name,
            'recommended' => $block ? SliderSettings::recommended($aspect) : [1600, 700],
            'summary' => $type !== '' && $value !== '' ? $links->describe($type, $value) : null,
            'choices' => $choices, 'maxKb' => (int) config('platform.media.max_image_kb', 8192),
            'warn' => ['en' => $this->imageWarning($this->imageEn, $aspect), 'ar' => $this->imageWarning($this->imageAr, $aspect)],
            'imageEn' => self::mediaUrl($current?->image_en_path), 'imageAr' => self::mediaUrl($current?->image_ar_path),
        ];
    }

    /** A soft warning (never an error) about a picked picture: the wrong shape for this slider or too small for a sharp result. */
    private function imageWarning(mixed $file, string $aspect): ?string
    {
        $path = is_object($file) && method_exists($file, 'getRealPath') ? $file->getRealPath() : null;
        $info = $path && is_file($path) ? @getimagesize($path) : false;
        if (! $info) {
            return null;
        }
        [$w, $h] = $info;
        [$aw, $ah] = array_map('floatval', explode(':', $aspect) + [1 => 1]);
        if ($h > 0 && $ah > 0 && abs(($w / $h) / ($aw / $ah) - 1) > 0.12) {
            return __('banners_admin.warn_ratio', ['have' => $w.' × '.$h, 'aspect' => $aspect]);
        }
        $recommended = $aspect === '16:7' ? 1600 : SliderSettings::recommended($aspect)[0];

        return $w < $recommended * 0.6 ? __('banners_admin.warn_small', ['w' => $w, 'rec' => $recommended]) : null;
    }

    /**
     * @param  Collection<int, Category>  $nodes
     * @return list<array{value: string, label: string, sub: ?string, depth: int}>
     */
    private function flatten(Collection $nodes, int $depth = 0): array
    {
        $out = [];
        foreach ($nodes as $c) {
            $out[] = ['value' => $c->id, 'label' => (string) $c->trans('name'), 'sub' => null, 'depth' => $depth];
            array_push($out, ...$this->flatten($c->children, $depth + 1));
        }

        return $out;
    }

    /**
     * What the slider preview plays: exactly the slides customers get right now (published and inside their window), in order, with the
     * slider's own settings.
     *
     * @param  Collection<int, object>  $blocks
     * @return array<string, mixed>|null
     */
    private function previewData(Collection $blocks, BannerLinks $links): ?array
    {
        $block = $blocks->first(fn ($b) => $b->section->id === $this->previewId);
        if (! $block) {
            return null;
        }
        $live = $block->slides->filter(fn (Banner $b) => $b->isLive() && $b->image_en_path)->values();
        $described = $links->describeMany($live);

        return ['name' => $block->name, 'settings' => $block->settings, 'hidden' => $block->slides->count() - $live->count(),
            'slides' => $live->map(fn (Banner $b) => ['id' => $b->id, 'title_en' => $b->title_en, 'title_ar' => $b->title_ar ?: $b->title_en, 'subtitle_en' => $b->subtitle_en,
                'subtitle_ar' => $b->subtitle_ar ?: $b->subtitle_en, 'image_en' => self::mediaUrl($b->image_en_path), 'image_ar' => self::mediaUrl($b->image_ar_path ?: $b->image_en_path),
                'platform' => $b->platform, 'linked' => (bool) ($described[$b->id]['ok'] ?? false)])->all()];
    }
}
