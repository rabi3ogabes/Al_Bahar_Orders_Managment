<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Dual control: a refund the requester approved themselves (nobody else could) is flagged, with the written reason. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->boolean('self_approved')->default(false);
            $table->text('self_approval_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropColumn(['self_approved', 'self_approval_reason']);
        });
    }
};
