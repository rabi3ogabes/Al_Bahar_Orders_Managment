<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which message brought the customer back: the order is credited to the last message sent before it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journey_runs', function (Blueprint $table) {
            $table->timestampTz('converted_at')->nullable()->after('revenue');
            $table->foreignUuid('attributed_delivery_id')->nullable()->after('converted_at')->constrained('journey_deliveries')->nullOnDelete();
            $table->index(['status', 'converted_at']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::table('journey_runs', function (Blueprint $table) {
            $table->dropIndex(['status', 'converted_at']);
            $table->dropIndex(['order_id']);
            $table->dropConstrainedForeignId('attributed_delivery_id');
            $table->dropColumn('converted_at');
        });
    }
};
