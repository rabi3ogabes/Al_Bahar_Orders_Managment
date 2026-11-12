<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Yearly event looks get a start and end time as well (Qatar time), e.g. 18 Dec 00:00 → 19 Dec 23:59. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_themes', function (Blueprint $table) {
            $table->string('recur_start_time', 5)->default('00:00')->after('recur_end');
            $table->string('recur_end_time', 5)->default('23:59')->after('recur_start_time');
        });
    }

    public function down(): void
    {
        Schema::table('brand_themes', fn (Blueprint $table) => $table->dropColumn(['recur_start_time', 'recur_end_time']));
    }
};
