<?php

namespace App\Domain\Cms\Models;

use App\Domain\Cms\Services\HomepageService;
use App\Domain\Cms\Support\SliderSettings;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One block of the home page, in admin order. A `banners` section is a slider; its slides are Banner rows. */
class HomepageSection extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'homepage_sections';

    protected $guarded = ['id'];

    protected $casts = ['settings' => 'array', 'sort_order' => 'integer', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        $flush = static fn () => HomepageService::flush();
        static::saved($flush);
        static::deleted($flush);
        static::restored($flush);
    }

    /** @return HasMany<HomepageSectionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(HomepageSectionItem::class)->orderBy('sort_order');
    }

    /** @return HasMany<Banner, $this> */
    public function slides(): HasMany
    {
        return $this->hasMany(Banner::class, 'homepage_section_id')->orderBy('sort_order')->orderBy('created_at')->orderBy('id');
    }

    public function isSlider(): bool
    {
        return $this->type === 'banners';
    }

    /** @return array{autoplay: bool, interval: int, aspect: string, dots: bool, show_titles: bool, rounded: bool} */
    public function slider(): array
    {
        return SliderSettings::normalize($this->settings['slider'] ?? null);
    }

    /** The name only staff see (the public heading is title_en / title_ar). */
    public function adminName(): string
    {
        return trim((string) ($this->settings['name'] ?? ''));
    }
}
