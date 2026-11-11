<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Look of the store and the apps: brand colours, artwork and a seasonal banner. One theme is shown at a time:
        // the one the admin activated by hand, else an enabled event whose dates match today, else the default theme.
        Schema::create('brand_themes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 48)->unique();
            $table->string('kind', 12)->default('custom'); // base | event | custom
            $table->string('name_en');
            $table->string('name_ar');
            $table->jsonb('colors');                        // primary, primary_soft, accent, background, surface, ink
            $table->string('hero_image_path')->nullable();   // store hero + app home banner
            $table->string('banner_image_path')->nullable(); // wide strip
            $table->string('logo_path')->nullable();
            $table->string('splash_image_path')->nullable();
            $table->string('banner_title_en')->nullable();
            $table->string('banner_title_ar')->nullable();
            $table->string('banner_subtitle_en')->nullable();
            $table->string('banner_subtitle_ar')->nullable();
            $table->string('recur_start', 5)->nullable();    // MM-DD, every year
            $table->string('recur_end', 5)->nullable();      // MM-DD, may wrap over new year
            $table->timestampTz('starts_at')->nullable();    // one-off window (Ramadan, Eid…), Qatar time entered by admin
            $table->timestampTz('ends_at')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_forced')->default(false);    // "Activate now"
            $table->boolean('is_enabled')->default(true);    // takes part in the schedule
            $table->unsignedSmallInteger('priority')->default(100);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE brand_themes ADD CONSTRAINT brand_themes_kind_check CHECK (kind IN ('base','event','custom'))");
        DB::statement('CREATE UNIQUE INDEX brand_themes_one_default ON brand_themes (is_default) WHERE is_default');
        DB::statement('CREATE UNIQUE INDEX brand_themes_one_forced ON brand_themes (is_forced) WHERE is_forced');
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_themes');
    }
};
