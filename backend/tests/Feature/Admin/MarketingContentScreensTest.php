<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Cms\Models\Banner;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Cms\Models\HomepageSection;
use App\Domain\Cms\Services\HomepageService;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\ProductQuestion;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Marketing\Models\Review;
use App\Domain\Notifications\Models\MarketingCampaign;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Models\NotificationTemplate;
use App\Domain\Notifications\Services\TemplateRenderer;
use App\Domain\Reports\Jobs\GenerateExportJob;
use App\Domain\Reports\Models\DataExport;
use App\Domain\Reports\Services\ReportService;
use App\Domain\Settings\Services\Settings;
use App\Domain\Support\Services\SupportService;
use App\Livewire\Admin\Content\BannerIndex;
use App\Livewire\Admin\Content\HomepageIndex;
use App\Livewire\Admin\Content\NotificationTemplateIndex;
use App\Livewire\Admin\Content\PageIndex;
use App\Livewire\Admin\Insights\ExportIndex;
use App\Livewire\Admin\Insights\ReportIndex;
use App\Livewire\Admin\Marketing\CampaignIndex;
use App\Livewire\Admin\Marketing\LoyaltyIndex;
use App\Livewire\Admin\Marketing\PromotionIndex;
use App\Livewire\Admin\Marketing\ReviewIndex;
use App\Livewire\Admin\Marketing\SupportIndex;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->superAdmin()->create();
    $this->marketing = staffWithRole('marketing_manager');
});

const MARKETING_CONTENT_SCREENS = [
    ['admin.promotions.index', 'marketing_manager'], ['admin.campaigns.index', 'marketing_manager'], ['admin.loyalty.index', 'marketing_manager'],
    ['admin.reviews.index', 'marketing_manager'], ['admin.support.index', 'customer_support'], ['admin.homepage.index', 'marketing_manager'],
    ['admin.banners.index', 'marketing_manager'], ['admin.pages.index', 'marketing_manager'], ['admin.notification-templates.index', 'marketing_manager'],
    ['admin.reports.index', 'accountant'], ['admin.exports.index', 'accountant'],
];

it('renders every marketing, content and insights screen for an authorized user', function (string $route, string $role) {
    $this->actingAs($this->admin)->get(route($route))->assertOk();
    $this->actingAs(staffWithRole($role))->get(route($route))->assertOk();
})->with(MARKETING_CONTENT_SCREENS);

it('forbids them to staff without the permission', function (string $route) {
    $branch = Branch::factory()->create();
    $this->actingAs(staffWithRole('delivery_employee', $branch))->get(route($route))->assertForbidden();
})->with(array_column(MARKETING_CONTENT_SCREENS, 0));

it('reorders, hides and edits homepage sections', function () {
    $a = HomepageSection::create(['type' => 'categories', 'title_en' => 'Shop by room', 'sort_order' => 1, 'status' => 'published']);
    $b = HomepageSection::create(['type' => 'new_arrivals', 'title_en' => 'New in', 'sort_order' => 2, 'status' => 'published']);
    $c = HomepageSection::create(['type' => 'brands', 'title_en' => 'Our houses', 'sort_order' => 3, 'status' => 'draft']);
    $this->actingAs($this->marketing);
    $order = fn () => HomepageSection::orderBy('sort_order')->pluck('id')->all();

    $cmp = Livewire::test(HomepageIndex::class)->call('reorder', $c->id, 0);
    expect($order())->toBe([$c->id, $a->id, $b->id]);
    $cmp->call('move', $a->id, 1);
    expect($order())->toBe([$c->id, $b->id, $a->id]);
    $cmp->call('move', $c->id, -1); // already first: stays
    expect($order())->toBe([$c->id, $b->id, $a->id]);

    $cmp->call('toggle', $b->id);
    expect($b->fresh()->status)->toBe('draft');
    $p = Product::factory()->create();
    $cmp->call('create')->set('form.type', 'product_collection')->set('form.title_en', 'Editor’s picks')->set('form.status', 'published')
        ->call('addItem', $p->id)->call('save')->assertHasNoErrors();
    $s = HomepageSection::where('type', 'product_collection')->firstOrFail();
    expect($s->items()->pluck('item_id')->all())->toBe([$p->id])->and($s->sort_order)->toBe(4);
    expect(collect(app(HomepageService::class)->render(null)['sections'])->pluck('id')->all())->not->toContain($c->id); // drafts never reach the app

    $this->actingAs(staffWithRole('auditor'));
    Livewire::test(HomepageIndex::class)->call('reorder', $a->id, 0)->assertForbidden();
});

it('previews notification templates through TemplateRenderer and falls back to defaults', function () {
    $this->actingAs($this->marketing);
    $c = Livewire::test(NotificationTemplateIndex::class, ['event' => 'order.ready_for_pickup'])
        ->assertViewHas('variables', fn ($v) => is_array($v));
    $default = app(TemplateRenderer::class)->render('order.ready_for_pickup', 'push', 'ar', NotificationTemplateIndex::SAMPLE);
    $c->assertViewHas('previews', fn ($p) => $p['ar'] === $default);

    $c->call('edit', 'push', 'ar')->set('form.subject', 'طلبك {{number}} جاهز')->set('form.body', 'مرحباً {{name}}، يمكنك الاستلام من {{branch}}.')
        ->assertViewHas('draftPreview', fn ($d) => $d['subject'] === 'طلبك AB-260412-0042 جاهز')->call('save')->assertHasNoErrors();
    expect(NotificationTemplate::where(['event' => 'order.ready_for_pickup', 'channel' => 'push', 'locale' => 'ar'])->exists())->toBeTrue();
    $c->assertViewHas('previews', fn ($p) => $p['ar']['body'] === 'مرحباً Noor، يمكنك الاستلام من West Bay Showroom.');
    expect(app(TemplateRenderer::class)->render('order.ready_for_pickup', 'push', 'ar', ['number' => 'X1', 'name' => 'Sara', 'branch' => 'Pearl'])['subject'])->toBe('طلبك X1 جاهز');

    $c->call('resetDefault', 'push', 'ar');
    expect(NotificationTemplate::count())->toBe(0);
    $this->actingAs(staffWithRole('auditor'));
    Livewire::test(NotificationTemplateIndex::class)->call('edit', 'push', 'en')->assertForbidden();
});

it('queues a report export for the requester and serves it only through a signed link', function () {
    Queue::fake();
    $acc = staffWithRole('accountant');
    $this->actingAs($acc);
    Livewire::test(ReportIndex::class, ['report' => 'finance_collections'])->assertViewHas('error', null)->call('export')->assertDispatched('toast', type: 'success');
    $export = DataExport::firstOrFail();
    expect($export->user_id)->toBe($acc->id)->and($export->type)->toBe('finance_collections')->and($export->status)->toBe('queued');
    Queue::assertPushed(GenerateExportJob::class, fn ($j) => $j->exportId === $export->id);

    // Run it, then download through the signed route only.
    (new GenerateExportJob($export->id))->handle(app(ReportService::class));
    Livewire::test(ExportIndex::class)->assertViewHas('links', fn ($l) => $l[$export->id] !== null);
    $this->get(route('admin.exports.download', $export))->assertForbidden();
    $signed = URL::temporarySignedRoute('admin.exports.download', now()->addMinutes(5), ['export' => $export->id]);
    $this->get($signed)->assertOk();
    $this->actingAs(staffWithRole('accountant'))->get($signed)->assertForbidden();

    // Reports the viewer may not run are not offered and cannot be exported.
    $this->actingAs($this->marketing);
    Livewire::test(ReportIndex::class)->assertViewHas('groups', fn ($g) => ! isset($g['finance']))->set('report', 'finance_collections')->call('export')->assertForbidden();
});

it('creates promotions and coupons with validation', function () {
    $this->actingAs($this->marketing);
    Livewire::test(PromotionIndex::class)->call('createPromotion')->set('p.name_en', 'Majlis week')->set('p.name_ar', 'أسبوع المجالس')
        ->set('p.type', 'percentage')->set('p.value', '150')->call('savePromotion')->assertHasErrors('p.value')
        ->set('p.value', '15')->set('p.is_stackable', true)->set('p.usage_limit', 100)->call('savePromotion')->assertHasNoErrors();
    $promo = Promotion::firstOrFail();
    expect($promo->is_stackable)->toBeTrue()->and($promo->usage_limit)->toBe(100)->and($promo->created_by)->toBe($this->marketing->id);
    Livewire::test(PromotionIndex::class)->call('createCoupon', $promo->id)->set('c.code', 'majlis15')->call('saveCoupon')->assertHasNoErrors();
    expect(Coupon::value('code'))->toBe('MAJLIS15');
    Livewire::test(PromotionIndex::class)->call('createCoupon', $promo->id)->set('c.code', 'MAJLIS15')->call('saveCoupon')->assertHasErrors('c.code');
    Livewire::test(PromotionIndex::class)->call('toggle', 'promotion', $promo->id);
    expect($promo->fresh()->is_active)->toBeFalse();
});

it('previews, test-sends and schedules a campaign', function () {
    $this->actingAs($this->marketing);
    Livewire::test(CampaignIndex::class)->call('create')->set('form.name', 'Eid')->set('form.title_en', 'Eid Mubarak')->set('form.title_ar', 'عيد مبارك')
        ->set('form.body_en', 'Gifts for the home.')->set('form.body_ar', 'هدايا للمنزل.')->set('form.when', 'later')->set('form.scheduled_at', now('Asia/Qatar')->addDay()->format('Y-m-d\TH:i'))
        ->assertViewHas('reach', 0)->call('save')->assertHasNoErrors();
    $c = MarketingCampaign::firstOrFail();
    expect($c->status)->toBe('scheduled');
    Livewire::test(CampaignIndex::class)->set('previewLocale', 'ar')->call('sendTest', $c->id);
    expect(Notification::where('user_id', $this->marketing->id)->value('title'))->toBe('[TEST] عيد مبارك')->and($c->fresh()->status)->toBe('scheduled');
});

it('moderates reviews, answers questions and replies to support tickets', function () {
    $customer = User::factory()->create();
    $product = Product::factory()->create();
    $review = Review::create(['product_id' => $product->id, 'user_id' => $customer->id, 'rating' => 4, 'title' => 'Lovely', 'status' => 'pending']);
    $q = ProductQuestion::create(['product_id' => $product->id, 'user_id' => $customer->id, 'question' => 'Is it solid wood?']);
    $this->actingAs($this->marketing);
    Livewire::test(ReviewIndex::class)->call('moderate', $review->id, 'rejected')->assertHasErrors('notes.'.$review->id)->call('moderate', $review->id, 'approved');
    expect($review->fresh()->status)->toBe('approved')->and($product->fresh()->rating_count)->toBe(1);
    Livewire::test(ReviewIndex::class, ['tab' => 'questions'])->call('answer', $q->id, 'published')->assertHasErrors()
        ->set("answers.{$q->id}", 'Yes — kiln-dried oak.')->call('answer', $q->id, 'published');
    expect($q->fresh()->status)->toBe('published')->and($q->fresh()->answered_by)->toBe($this->marketing->id);

    $ticket = app(SupportService::class)->open($customer, ['category' => 'product', 'subject' => 'Fabric care', 'message' => 'How do I clean it?']);
    $agent = staffWithRole('customer_support');
    $this->actingAs($agent);
    Livewire::test(SupportIndex::class)->call('open', $ticket->id)->set('reply', 'Use a dry brush.')->call('send')
        ->set('reply', 'Customer is VIP')->set('internal', true)->call('send')->call('assign', 'priority', 'high')->call('assign', 'assignee', $agent->id);
    $ticket->refresh();
    expect($ticket->messages()->count())->toBe(3)->and($ticket->messages()->where('is_internal', true)->count())->toBe(1)
        ->and($ticket->status)->toBe('pending_customer')->and($ticket->priority)->toBe('high')->and($ticket->assigned_to)->toBe($agent->id);
});

it('saves CMS pages as drafts, publishes them and manages banners', function () {
    Storage::fake(config('platform.media.disk'));
    $this->actingAs($this->marketing);
    $c = Livewire::test(PageIndex::class)->call('create')->set('slug', 'Terms')->set('tr.en.title', 'Terms')->set('tr.ar.title', 'الشروط')
        ->set('tr.en.body', '<p>Hello<script>alert(1)</script></p>')->call('save')->assertHasNoErrors();
    $page = CmsPage::with('translations')->firstOrFail();
    expect($page->slug)->toBe('terms')->and($page->status)->toBe('draft')->and($page->translations->firstWhere('locale', 'en')->draft_body)->not->toContain('<script');
    $c->call('publish');
    expect($page->fresh()->status)->toBe('published')->and($page->translations()->where('locale', 'en')->value('body'))->toContain('Hello');

    Livewire::test(BannerIndex::class)->call('create')->call('save')->assertHasErrors('imageEn')
        ->set('imageEn', UploadedFile::fake()->image('hero.jpg', 1600, 700))->set('form.title_en', 'Ramadan')->set('form.status', 'published')->call('save')->assertHasNoErrors();
    expect(Banner::where('title_en', 'Ramadan')->exists())->toBeTrue();
});

it('lets loyalty managers edit programme rules only with override permission', function () {
    $this->actingAs($this->marketing); // loyalty.view + loyalty.edit, no override
    Livewire::test(LoyaltyIndex::class, ['tab' => 'rules'])->set('rules.points_per_qar', '2')->call('saveRules')->assertForbidden();
    $this->actingAs($this->admin);
    Livewire::test(LoyaltyIndex::class, ['tab' => 'rules'])->set('rules.points_per_qar', '2')->call('saveRules')->assertHasNoErrors();
    expect(app(Settings::class)->get('loyalty.points_per_qar'))->toBe(2);
});
