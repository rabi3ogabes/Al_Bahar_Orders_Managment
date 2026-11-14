<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A version number per order for the real-time apps: it grows by one every time something about the order, one of its branch parts or one of its
    // deliveries changes (see App\Domain\Orders\Realtime\OrderRealtime). The same number travels in every socket message and in the REST answers, so an app can
    // tell a stale or repeated message from a newer one and merge a re-read with what the socket already told it.
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('progress_seq')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('progress_seq');
        });
    }
};
