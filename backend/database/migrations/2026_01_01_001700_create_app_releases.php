<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Store release rules per app and platform: what is the newest build, and which builds must update before they can be used. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_releases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('app', 12);       // customer | driver | staff
            $table->string('platform', 8);   // android | ios
            $table->string('latest_version', 24)->nullable();
            $table->unsignedInteger('latest_build')->default(0);
            $table->unsignedInteger('min_build')->default(0); // builds below this are blocked until updated (0 = nobody is forced)
            $table->string('store_url', 500)->nullable();
            $table->text('message_en')->nullable();
            $table->text('message_ar')->nullable();
            $table->timestampTz('forced_at')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['app', 'platform']);
        });
        DB::statement("ALTER TABLE app_releases ADD CONSTRAINT app_releases_enums CHECK (app IN ('customer','driver','staff') AND platform IN ('android','ios'))");

        $ids = ['customer' => 'qa.albahar.customer', 'driver' => 'qa.albahar.driver', 'staff' => 'qa.albahar.staff'];
        foreach ($ids as $app => $pkg) {
            foreach (['android', 'ios'] as $platform) {
                DB::table('app_releases')->insert([
                    'id' => (string) Str::uuid7(), 'app' => $app, 'platform' => $platform,
                    'store_url' => $platform === 'android' ? 'https://play.google.com/store/apps/details?id='.$pkg : null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_releases');
    }
};
