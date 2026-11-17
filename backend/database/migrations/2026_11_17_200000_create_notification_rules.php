<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification controls: what the admin switched off or on per notification event (see App\Domain\Notifications\Services\NotificationRules).
 *
 * One row per event the admin has CHANGED; no row means "the platform default" (the catalog's channels), so a fresh install and an
 * install where nobody touched the page behave exactly as before. `is_enabled` is the master switch of the event. `channels` holds only the
 * per-channel overrides, as {channel: bool} (push, in_app, sms, whatsapp, email, live_card); a channel that is missing follows the default
 * of that event. `updated_by` is the staff member who made the last change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event', 64)->unique();            // e.g. order.confirmed, staff.driver_assigned
            $table->boolean('is_enabled')->default(true);     // the master switch of the event
            $table->jsonb('channels')->default('{}');         // {push: bool, in_app: bool, sms: bool, whatsapp: bool, email: bool, live_card: bool}
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_rules');
    }
};
