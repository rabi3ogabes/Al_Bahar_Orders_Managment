<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * "Shop by room" (roadmap 3.9): rooms the owner manages (Content > Rooms) and the home page shows as large tiles.
 *
 *  - `rooms`: a title in English and Arabic, a picture, an order, a switch, a short text for search engines, and `tags` (words an owner puts on a
 *    product, Product > Tags). A product belongs to a room when it is in one of the room's categories (their sub-categories too) or carries one of its tags.
 *  - `room_categories`: which categories belong to which room (the index on the category serves "which rooms is this category in").
 *  - Seeded once with Bedroom, Kitchen, Bathroom, Living room and Dining, mapped to the real categories of the store when they exist (a category that is
 *    not there is simply not linked). A room with no products is not shown to customers, so a default that matches nothing yet (Dining until a product is
 *    tagged "dining") stays out of sight instead of showing an empty page.
 *  - The home page's `rooms` section is NOT made here: RoomService::ensureHomeSection() adds it once (after the categories section) from the scheduler or the first
 *    visit of Content > Rooms, so a migration never leaves an unexpected section in a home page that tests, or a fresh install, start empty.
 */
return new class extends Migration
{
    /** slug => [English, Arabic, English text, Arabic text, category slugs, tags] */
    private const DEFAULTS = [
        'bedroom' => ['Bedroom', 'غرفة النوم', 'Sheets, duvets, pillows and everything for a better night\'s sleep.', 'شراشف ولحف ووسائد وكل ما يساعد على نوم أفضل.', ['bedding-linens'], ['bedroom']],
        'kitchen' => ['Kitchen', 'المطبخ', 'Cookware, food storage and everyday kitchen helpers.', 'أواني الطبخ وحافظات الطعام ومستلزمات المطبخ اليومية.', ['kitchen-dining'], ['kitchen']],
        'bathroom' => ['Bathroom', 'الحمّام', 'Towels, mats and accessories for a calm, tidy bathroom.', 'مناشف وسجاد وإكسسوارات لحمّام هادئ ومرتّب.', ['bath-personal'], ['bathroom']],
        'living-room' => ['Living room', 'غرفة المعيشة', 'Cushions, vases, mirrors and the scents that finish a living space.', 'وسائد ومزهريات ومرايا وعطور تكمل أجواء المعيشة.', ['decor-living', 'home-fragrance'], ['living room']],
        'dining' => ['Dining', 'السفرة', 'Plates, glasses and serving pieces for the table. Tag a product "dining" to show it here.', 'صحون وأكواب وقطع تقديم للسفرة. أضف الوسم «dining» لمنتج ليظهر هنا.', [], ['dining']],
    ];

    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 60)->unique();
            $table->string('title_en', 80);
            $table->string('title_ar', 80);
            $table->string('description_en', 400)->nullable();
            $table->string('description_ar', 400)->nullable();
            $table->string('image_path')->nullable();
            $table->jsonb('tags')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->index(['is_active', 'sort_order']);
        });
        Schema::create('room_categories', function (Blueprint $table) {
            $table->foreignUuid('room_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('category_id')->constrained()->cascadeOnDelete();
            $table->primary(['room_id', 'category_id']);
            $table->index('category_id');
        });

        $categories = DB::table('categories')->pluck('id', 'slug');
        $order = 0;
        foreach (self::DEFAULTS as $slug => [$en, $ar, $textEn, $textAr, $categorySlugs, $tags]) {
            $id = (string) Str::uuid();
            DB::table('rooms')->insert(['id' => $id, 'slug' => $slug, 'title_en' => $en, 'title_ar' => $ar, 'description_en' => $textEn, 'description_ar' => $textAr,
                'tags' => json_encode($tags), 'is_active' => true, 'sort_order' => $order++, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($categorySlugs as $categorySlug) {
                if (isset($categories[$categorySlug])) {
                    DB::table('room_categories')->insert(['room_id' => $id, 'category_id' => $categories[$categorySlug]]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('homepage_section_items')->whereIn('homepage_section_id', DB::table('homepage_sections')->where('type', 'rooms')->select('id'))->delete();
        DB::table('homepage_sections')->where('type', 'rooms')->delete();
        Schema::dropIfExists('room_categories');
        Schema::dropIfExists('rooms');
    }
};
