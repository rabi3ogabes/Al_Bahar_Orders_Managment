<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Two ready-made templates for the messages staff send by hand (customers pages → Send message): a reminder about an order left in the cart and
 * a special offer. They live with the journey templates, so the journeys page edits them too. Added only when missing, never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [
            ['name' => 'Forgotten order reminder', 'kind' => 'reminder',
                'title_en' => 'Your order is waiting', 'title_ar' => 'طلبك بانتظارك',
                'body_en' => 'Hi {{name}}, you left {{items}} item(s) in your cart, including {{first_item}}. Complete your order before they sell out.',
                'body_ar' => 'مرحباً {{name}}، لديك {{items}} منتج في السلة، منها {{first_item}}. أكمل طلبك قبل نفاد الكمية.',
                'whatsapp_en' => "Hi {{name}} 👋\nYou left {{items}} item(s) in your Al Bahar cart, including {{first_item}}. Complete your order here:\n{{app_link}}",
                'whatsapp_ar' => "مرحباً {{name}} 👋\nلديك {{items}} منتج في سلة البحار، منها {{first_item}}. أكمل طلبك من هنا:\n{{app_link}}",
                'deep_link' => 'albahar://cart', 'coupon_prefix' => 'BAHAR', 'coupon_valid_hours' => 72],
            ['name' => 'Special offer for you', 'kind' => 'offer',
                'title_en' => 'A little something for you', 'title_ar' => 'هدية صغيرة لك',
                'body_en' => 'Hi {{name}}, use code {{coupon}} on your next order before {{expires}}.',
                'body_ar' => 'مرحباً {{name}}، استخدم الرمز {{coupon}} في طلبك القادم قبل {{expires}}.',
                'whatsapp_en' => "Hi {{name}} 👋\nA little something from Al Bahar: use code {{coupon}} on your next order before {{expires}}.\n{{app_link}}",
                'whatsapp_ar' => "مرحباً {{name}} 👋\nهدية صغيرة من البحار: استخدم الرمز {{coupon}} في طلبك القادم قبل {{expires}}.\n{{app_link}}",
                'deep_link' => 'albahar://home', 'coupon_prefix' => 'BAHAR', 'coupon_valid_hours' => 72],
        ];
        foreach ($rows as $row) {
            if (! DB::table('journey_templates')->where('name', $row['name'])->exists()) {
                DB::table('journey_templates')->insert($row + ['id' => (string) Str::uuid7(), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        DB::table('journey_templates')->whereIn('name', ['Forgotten order reminder', 'Special offer for you'])->whereNotExists(
            fn ($q) => $q->selectRaw('1')->from('journey_steps')->whereColumn('journey_steps.template_id', 'journey_templates.id'))->delete();
    }
};
