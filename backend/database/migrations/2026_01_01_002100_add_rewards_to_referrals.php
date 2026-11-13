<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referral program: what each side was actually given (so later setting changes never rewrite history), the fraud
 * signals staff can see, and who rejected a referral by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            foreach (['referrer', 'referee'] as $side) {
                $table->string($side.'_reward_type', 16)->nullable(); // balance | coupon
                $table->unsignedInteger($side.'_reward_points')->nullable(); // loyalty points credited (balance rewards)
                $table->decimal($side.'_reward_value', 12, 2)->nullable(); // QAR value of the balance reward
                $table->string($side.'_coupon_code', 40)->nullable();
            }
            $table->jsonb('fraud_flags')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->index(['referrer_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropIndex(['referrer_id', 'status']);
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn(['referrer_reward_type', 'referrer_reward_points', 'referrer_reward_value', 'referrer_coupon_code',
                'referee_reward_type', 'referee_reward_points', 'referee_reward_value', 'referee_coupon_code', 'fraud_flags', 'rejected_by', 'rejected_at']);
        });
    }
};
