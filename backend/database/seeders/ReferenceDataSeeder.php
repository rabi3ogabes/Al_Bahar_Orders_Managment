<?php

namespace Database\Seeders;

use App\Domain\Cms\Models\CmsPage;
use App\Domain\Cms\Models\HomepageSection;
use Illuminate\Database\Seeder;

/**
 * Production-safe reference data. Idempotent: never overwrites content that administrators have edited.
 *  - CMS page shells (About, Contact, FAQ, Terms, Privacy, Returns, Auction terms) as DRAFTS for legal review.
 *  - A default homepage layout (admins reorder/retitle/unpublish freely in the Homepage builder).
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $pages = [
            'about' => ['About us', 'من نحن', '<p>Al Bahar Home & Living curates furniture and décor for calm, considered homes across Qatar.</p>', '<p>تقدّم البحر للمنزل والمعيشة أثاثاً وديكوراً منتقى بعناية لمنازل هادئة وراقية في جميع أنحاء قطر.</p>'],
            'contact' => ['Contact us', 'تواصل معنا', '<p>Our customer care team is available daily. Visit any showroom or open a support request in the app.</p>', '<p>فريق خدمة العملاء متاح يومياً. يمكنك زيارة أي معرض أو فتح طلب دعم من التطبيق.</p>'],
            'faq' => ['Frequently asked questions', 'الأسئلة الشائعة', '<h3>How long does delivery take?</h3><p>Delivery times are shown at checkout for your address.</p>', '<h3>كم يستغرق التوصيل؟</h3><p>تظهر مواعيد التوصيل عند الدفع حسب عنوانك.</p>'],
            'terms' => ['Terms & conditions', 'الشروط والأحكام', '<p>To be reviewed and approved by legal counsel before launch.</p>', '<p>تتم مراجعتها واعتمادها من المستشار القانوني قبل الإطلاق.</p>'],
            'privacy' => ['Privacy policy', 'سياسة الخصوصية', '<p>To be reviewed and approved by legal counsel before launch.</p>', '<p>تتم مراجعتها واعتمادها من المستشار القانوني قبل الإطلاق.</p>'],
            'returns' => ['Returns & refunds', 'الإرجاع والاسترداد', '<p>Return requests can be made from your order within 14 days of delivery.</p>', '<p>يمكن طلب الإرجاع من صفحة طلبك خلال 14 يوماً من الاستلام.</p>'],
            'auction-terms' => ['Auction terms', 'شروط المزادات', '<p>Bids are binding. Winners must complete payment before the published deadline.</p>', '<p>المزايدات ملزمة. يجب على الفائز إتمام الدفع قبل الموعد المعلن.</p>'],
        ];
        foreach ($pages as $slug => [$en, $ar, $bodyEn, $bodyAr]) {
            $page = CmsPage::firstOrCreate(['slug' => $slug], ['status' => app()->isProduction() ? 'draft' : 'published', 'published_at' => app()->isProduction() ? null : now()]);
            $page->translations()->firstOrCreate(['locale' => 'en'], ['title' => $en, 'body' => $bodyEn]);
            $page->translations()->firstOrCreate(['locale' => 'ar'], ['title' => $ar, 'body' => $bodyAr]);
        }

        if (HomepageSection::exists()) {
            return;
        }
        $sections = [
            ['banners', 'hero', null, null],
            ['categories', 'chips', 'Shop by room', 'تسوّق حسب الغرفة'],
            ['featured_products', 'carousel', 'Curated for you', 'مختارات لك'],
            ['live_auctions', 'carousel', 'Live auctions', 'مزادات مباشرة'],
            ['new_arrivals', 'grid', 'New arrivals', 'وصل حديثاً'],
            ['promotions', 'carousel', 'Offers', 'العروض'],
            ['brands', 'chips', 'Our brands', 'علاماتنا التجارية'],
            ['upcoming_auctions', 'carousel', 'Upcoming auctions', 'مزادات قادمة'],
            ['best_sellers', 'carousel', 'Best sellers', 'الأكثر مبيعاً'],
        ];
        foreach ($sections as $i => [$type, $layout, $en, $ar]) {
            HomepageSection::create(['type' => $type, 'layout' => $layout, 'title_en' => $en, 'title_ar' => $ar, 'sort_order' => $i, 'status' => 'published', 'item_limit' => 12]);
        }
    }
}
