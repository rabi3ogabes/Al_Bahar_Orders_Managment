<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 — Marketing, loyalty, referrals, CMS, notifications, reviews and support.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->text('description')->nullable();
            $table->timestampsTz();
        });

        Schema::create('customer_group_members', function (Blueprint $table) {
            $table->foreignUuid('customer_group_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['customer_group_id', 'user_id']);
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name_en');
            $table->string('name_ar');
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            // percentage | fixed | free_delivery | bogo | bundle | flash_sale
            $table->string('type', 24);
            $table->decimal('value', 12, 2)->default(0); // percent or QAR depending on type
            $table->string('scope', 16)->default('order'); // order | products | categories
            $table->decimal('min_basket_amount', 12, 2)->nullable();
            $table->decimal('max_discount_amount', 12, 2)->nullable();
            $table->unsignedSmallInteger('buy_quantity')->nullable(); // BOGO / bundle
            $table->unsignedSmallInteger('get_quantity')->nullable();
            $table->decimal('get_discount_percent', 5, 2)->nullable(); // 100 = free
            $table->boolean('first_order_only')->default(false);
            $table->foreignUuid('customer_group_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('requires_coupon')->default(true); // false = automatic promotion
            $table->boolean('is_stackable')->default(false);
            $table->unsignedSmallInteger('priority')->default(100); // lower applies first
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->unsignedInteger('times_used')->default(0);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('applies_to_auctions')->default(false);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['is_active', 'requires_coupon', 'starts_at', 'ends_at']);
        });
        DB::statement("ALTER TABLE promotions ADD CONSTRAINT promotions_type_check CHECK (type IN ('percentage','fixed','free_delivery','bogo','bundle','flash_sale'))");
        DB::statement("ALTER TABLE promotions ADD CONSTRAINT promotions_value_check CHECK (value >= 0 AND (type NOT IN ('percentage','flash_sale') OR value <= 100))");

        Schema::create('promotion_products', function (Blueprint $table) {
            $table->foreignUuid('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['promotion_id', 'product_id']);
        });
        Schema::create('promotion_categories', function (Blueprint $table) {
            $table->foreignUuid('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('category_id')->constrained()->cascadeOnDelete();
            $table->primary(['promotion_id', 'category_id']);
        });
        Schema::create('promotion_branches', function (Blueprint $table) {
            $table->foreignUuid('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->primary(['promotion_id', 'branch_id']);
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('promotion_id')->constrained()->cascadeOnDelete();
            $table->string('code', 48)->unique(); // stored upper-case
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->unsignedInteger('times_used')->default(0);
            $table->foreignUuid('assigned_user_id')->nullable()->constrained('users')->nullOnDelete(); // personal coupons
            $table->boolean('is_active')->default(true);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('coupon_usage', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('promotion_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->decimal('discount_amount', 12, 2);
            $table->string('status', 16)->default('applied'); // applied | reversed
            $table->timestampTz('reversed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['promotion_id', 'order_id']);
            $table->index(['user_id', 'promotion_id', 'status']);
        });

        // The spec's order_coupons: codes applied to an order, with the discount snapshot.
        Schema::create('order_coupons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('promotion_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 48)->nullable();
            $table->string('label_en');
            $table->string('label_ar');
            $table->decimal('discount_amount', 12, 2);
            $table->boolean('free_delivery')->default(false);
            $table->timestampsTz();
        });

        Schema::create('loyalty_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->integer('balance')->default(0);
            $table->unsignedBigInteger('lifetime_earned')->default(0);
            $table->string('tier', 16)->default('classic'); // classic | silver | gold | platinum
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE loyalty_accounts ADD CONSTRAINT loyalty_balance_check CHECK (balance >= 0)');

        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('loyalty_account_id')->constrained()->restrictOnDelete();
            $table->string('type', 16); // earn | redeem | expire | reverse | adjust | referral
            $table->integer('points'); // signed
            $table->integer('balance_after');
            $table->integer('remaining')->nullable(); // for earn rows: points not yet redeemed/expired (FIFO expiry)
            $table->uuid('order_id')->nullable();
            $table->string('reference_type', 48)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->string('reason', 500)->nullable();
            $table->uuid('actor_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['loyalty_account_id', 'created_at']);
            $table->index(['type', 'expires_at']);
        });
        DB::statement("CREATE UNIQUE INDEX loyalty_tx_unique_order_type ON loyalty_transactions (order_id, type) WHERE order_id IS NOT NULL AND type IN ('earn','redeem')");

        Schema::create('referrals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('referee_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('code', 16);
            $table->string('status', 16)->default('pending'); // pending | qualified | rewarded | rejected
            $table->uuid('qualifying_order_id')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->timestampTz('rewarded_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE referrals ADD CONSTRAINT referrals_not_self CHECK (referrer_id <> referee_id)');

        Schema::create('wishlists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->default('Default');
            $table->timestampsTz();
            $table->unique(['user_id', 'name']);
        });
        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('wishlist_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX wishlist_items_unique ON wishlist_items (wishlist_id, product_id, COALESCE(product_variant_id, \'00000000-0000-0000-0000-000000000000\'::uuid))');

        Schema::create('reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->string('locale', 5)->default('en');
            $table->boolean('is_verified_purchase')->default(false);
            $table->string('status', 16)->default('pending'); // pending | approved | rejected | hidden
            $table->unsignedInteger('report_count')->default(0);
            $table->foreignUuid('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('moderated_at')->nullable();
            $table->string('moderation_note', 500)->nullable();
            $table->timestampsTz();

            $table->unique(['product_id', 'user_id']);
            $table->index(['product_id', 'status']);
        });
        DB::statement('ALTER TABLE reviews ADD CONSTRAINT reviews_rating_check CHECK (rating BETWEEN 1 AND 5)');

        Schema::create('review_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('review_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 32);
            $table->string('path');
            $table->timestampsTz();
        });
        Schema::create('review_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('review_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 48);
            $table->timestampsTz();
            $table->unique(['review_id', 'user_id']);
        });

        Schema::create('product_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->text('question');
            $table->text('answer')->nullable();
            $table->foreignUuid('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('answered_at')->nullable();
            $table->string('status', 16)->default('pending'); // pending | published | rejected
            $table->timestampsTz();
        });

        Schema::create('banners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('placement', 32)->default('home_hero'); // home_hero | home_strip | category_top | auctions
            $table->string('platform', 16)->default('all'); // all | mobile | web
            $table->string('title_en')->nullable();
            $table->string('title_ar')->nullable();
            $table->string('subtitle_en')->nullable();
            $table->string('subtitle_ar')->nullable();
            $table->string('image_en_path');
            $table->string('image_ar_path')->nullable();
            $table->string('link_type', 16)->nullable(); // product | category | brand | auction | url | page
            $table->string('link_value')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 16)->default('draft'); // draft | published
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestampsTz();

            $table->index(['placement', 'status', 'sort_order']);
        });

        Schema::create('homepage_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // banners | categories | featured_products | new_arrivals | best_sellers | promotions | brands
            // | upcoming_auctions | live_auctions | recommended | product_collection
            $table->string('type', 32);
            $table->string('title_en')->nullable();
            $table->string('title_ar')->nullable();
            $table->string('subtitle_en')->nullable();
            $table->string('subtitle_ar')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 16)->default('draft'); // draft | published
            $table->string('layout', 24)->default('carousel'); // carousel | grid | hero | chips
            $table->unsignedSmallInteger('item_limit')->default(12);
            $table->jsonb('settings')->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('homepage_section_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('homepage_section_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 16); // product | category | brand | banner | promotion | auction
            $table->uuid('item_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('cms_pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 96)->unique(); // about, contact, faq, terms, privacy, returns, auction-terms
            $table->string('status', 16)->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        Schema::create('cms_page_translations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cms_page_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('title');
            $table->longText('body');                 // sanitized HTML (published)
            $table->longText('draft_body')->nullable(); // preview before publish
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->timestampsTz();
            $table->unique(['cms_page_id', 'locale']);
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event', 64);
            $table->string('channel', 16); // push | sms | whatsapp | email | in_app
            $table->string('locale', 5);
            $table->string('subject')->nullable();
            $table->text('body'); // supports {{placeholder}}
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['event', 'channel', 'locale']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('event', 64);
            $table->string('category', 16)->default('transactional'); // transactional | marketing | auction
            $table->string('title');
            $table->text('body');
            $table->jsonb('data')->nullable(); // {"deep_link": "albahar://orders/..."}
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'read_at', 'created_at']);
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('notification_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 64);
            $table->string('channel', 16);
            $table->string('provider', 32)->nullable();
            $table->string('recipient', 191)->nullable(); // masked
            $table->string('status', 16)->default('queued'); // queued | sent | delivered | failed | skipped
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('provider_message_id', 191)->nullable();
            $table->string('error', 500)->nullable();
            $table->jsonb('response')->nullable(); // redacted
            $table->string('campaign_id', 64)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'event', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('category', 16);
            $table->boolean('is_enabled')->default(true);
            $table->timestampsTz();
            $table->unique(['user_id', 'channel', 'category']);
        });

        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->jsonb('channels'); // ["push","sms"]
            $table->string('title_en');
            $table->string('title_ar');
            $table->text('body_en');
            $table->text('body_ar');
            $table->string('deep_link')->nullable();
            $table->jsonb('segment'); // {branch_ids, category_ids, customer_group_ids, purchased_since, auction_participants}
            $table->string('status', 16)->default('draft'); // draft | scheduled | sending | sent | cancelled
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 24)->unique();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 32); // order | delivery | payment | product | auction | return | account | other
            $table->string('subject');
            $table->string('priority', 16)->default('normal'); // low | normal | high | urgent
            $table->string('status', 24)->default('open'); // open | pending_customer | pending_staff | resolved | closed
            $table->foreignUuid('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('first_response_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'priority', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
        Schema::create('support_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_type', 16); // customer | staff | system
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->jsonb('attachments')->nullable();
            $table->timestampsTz();
        });

        Schema::create('return_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 24)->unique();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('requested'); // requested | approved | rejected | received | refunded | closed
            $table->string('reason_code', 48);
            $table->text('reason')->nullable();
            $table->jsonb('items'); // [{order_item_id, quantity}]
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->foreignUuid('refund_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('data_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 48);
            $table->jsonb('filters')->nullable();
            $table->string('status', 16)->default('queued'); // queued | processing | completed | failed
            $table->string('disk', 32)->nullable();
            $table->string('path')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        foreach (['data_exports', 'return_requests', 'support_messages', 'support_tickets', 'marketing_campaigns',
            'notification_preferences', 'notification_logs', 'notifications', 'notification_templates',
            'cms_page_translations', 'cms_pages', 'homepage_section_items', 'homepage_sections', 'banners',
            'product_questions', 'review_reports', 'review_media', 'reviews', 'wishlist_items', 'wishlists', 'referrals',
            'loyalty_transactions', 'loyalty_accounts', 'order_coupons', 'coupon_usage', 'coupons', 'promotion_branches',
            'promotion_categories', 'promotion_products', 'promotions', 'customer_group_members', 'customer_groups'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
