<?php

use App\Domain\Cms\Services\ThemeService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A base theme is a complete "design": colours plus a layout style and a few look tokens for the store and the apps.
        Schema::table('brand_themes', function (Blueprint $table) {
            $table->string('layout', 16)->default('classic'); // classic | fresh
            $table->jsonb('tokens')->nullable();              // radius, card, header, nav, background, density
        });
        DB::statement("ALTER TABLE brand_themes ADD CONSTRAINT brand_themes_layout_check CHECK (layout IN ('classic','fresh'))");

        app(ThemeService::class)->seedDesigns();
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE brand_themes DROP CONSTRAINT IF EXISTS brand_themes_layout_check');
        Schema::table('brand_themes', fn (Blueprint $table) => $table->dropColumn(['layout', 'tokens']));
    }
};
