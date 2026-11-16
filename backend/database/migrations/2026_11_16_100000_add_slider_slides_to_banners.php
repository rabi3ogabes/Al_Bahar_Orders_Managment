<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Home sliders: a `banners` home-page section is a SLIDER and the banner rows that belong to it are its SLIDES.
 *
 *  - banners.homepage_section_id  the slider a slide belongs to (null = one of the "other places": category top, auctions).
 *  - banners.deleted_at / homepage_sections.deleted_at  soft deletes, so "Delete" in the dashboard can be undone (a nightly job purges old ones).
 *  - banners.link_value is widened: a web address can be longer than 255 characters.
 *  - The per-slider settings (autoplay, interval, aspect, dots, titles, rounded corners) live in homepage_sections.settings.slider.
 *
 * Existing data is moved, never dropped: every home banner lands in a slider, hand-picked banner lists keep their order,
 * and banners that were never shown (no banners section existed) go into a hidden (draft) slider the admin can publish.
 * Idempotent (a section that already has `settings.slider` is skipped) and reversible.
 */
return new class extends Migration
{
    private const SLIDER_DEFAULTS = ['autoplay' => true, 'interval' => 5, 'aspect' => '16:9', 'dots' => true, 'show_titles' => true, 'rounded' => true];

    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->foreignUuid('homepage_section_id')->nullable()->constrained('homepage_sections')->cascadeOnDelete();
            $table->softDeletesTz();
            $table->index(['homepage_section_id', 'sort_order']);
        });
        Schema::table('banners', fn (Blueprint $table) => $table->string('link_value', 1000)->nullable()->change());
        Schema::table('homepage_sections', fn (Blueprint $table) => $table->softDeletesTz());

        $this->moveBannersIntoSliders();
    }

    public function down(): void
    {
        // Back to the old model: slides become plain banners again and each section remembers its placement.
        foreach (DB::table('homepage_sections')->where('type', 'banners')->get() as $section) {
            $settings = json_decode((string) $section->settings, true) ?: [];
            if (! isset($settings['slider'])) {
                continue;
            }
            DB::table('banners')->where('homepage_section_id', $section->id)->update(['homepage_section_id' => null]);
            if (! empty($settings['created_by_migration'])) {
                DB::table('homepage_sections')->where('id', $section->id)->delete();

                continue;
            }
            DB::table('homepage_sections')->where('id', $section->id)->update([
                'settings' => json_encode(['placement' => $settings['legacy_placement'] ?? 'home_hero']),
            ]);
        }

        Schema::table('homepage_sections', fn (Blueprint $table) => $table->dropSoftDeletesTz());
        Schema::table('banners', function (Blueprint $table) {
            $table->dropIndex(['homepage_section_id', 'sort_order']);
            $table->dropConstrainedForeignId('homepage_section_id');
            $table->dropSoftDeletesTz();
        });
    }

    private function moveBannersIntoSliders(): void
    {
        $now = now();

        foreach (DB::table('homepage_sections')->where('type', 'banners')->orderBy('sort_order')->orderBy('created_at')->get() as $section) {
            $settings = json_decode((string) $section->settings, true) ?: [];
            if (isset($settings['slider'])) {
                continue;
            }
            $placement = (string) ($settings['placement'] ?? 'home_hero');
            $picked = DB::table('homepage_section_items')->where('homepage_section_id', $section->id)->where('item_type', 'banner')->orderBy('sort_order')->pluck('item_id')->all();

            $query = DB::table('banners')->whereNull('homepage_section_id');
            $ids = $picked !== []
                ? $query->whereIn('id', $picked)->pluck('id')->sortBy(fn ($id) => array_search($id, $picked, true))->values()->all()
                : $query->where('placement', $placement)->orderBy('sort_order')->orderBy('created_at')->pluck('id')->all();

            foreach ($ids as $i => $id) {
                DB::table('banners')->where('id', $id)->update(['homepage_section_id' => $section->id, 'sort_order' => $i + 1]);
            }
            DB::table('homepage_sections')->where('id', $section->id)->update([
                'layout' => 'carousel',
                'settings' => json_encode(['slider' => self::SLIDER_DEFAULTS, 'legacy_placement' => $placement]),
                'updated_at' => $now,
            ]);
        }

        // Banners that no section ever showed: keep them, in a hidden slider per old placement, so nothing is lost and nothing goes live by surprise.
        foreach (['home_hero', 'home_strip'] as $placement) {
            $ids = DB::table('banners')->whereNull('homepage_section_id')->where('placement', $placement)->orderBy('sort_order')->orderBy('created_at')->pluck('id')->all();
            if ($ids === []) {
                continue;
            }
            $sectionId = (string) Str::uuid();
            DB::table('homepage_sections')->insert([
                'id' => $sectionId, 'type' => 'banners', 'layout' => 'carousel', 'status' => 'draft', 'item_limit' => 12,
                'sort_order' => (int) DB::table('homepage_sections')->max('sort_order') + 1,
                'settings' => json_encode(['slider' => self::SLIDER_DEFAULTS, 'legacy_placement' => $placement, 'created_by_migration' => true]),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($ids as $i => $id) {
                DB::table('banners')->where('id', $id)->update(['homepage_section_id' => $sectionId, 'sort_order' => $i + 1]);
            }
        }
    }
};
