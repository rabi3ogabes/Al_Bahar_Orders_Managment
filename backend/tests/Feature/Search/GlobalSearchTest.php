<?php

use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\Domain\Search\Services\GlobalSearch;
use App\Domain\Search\Support\SearchQuery;
use App\Livewire\Admin\Sales\CustomerIndex;
use App\Support\Phone;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/../Operations/OpsSupport.php';

/**
 * The dashboard's global search: every group is checked against the person's permission and branches, the query is read safely, and each group
 * is a bounded lookup. (The screen itself is tested in CommandPaletteTest and tests/js/command-palette-core.test.mjs.)
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(qatar('2026-11-19 12:00'));
    $this->a = opsBranch(attributes: ['code' => 'AAA']);
    $this->b = opsBranch(attributes: ['code' => 'BBB']);
    $this->mgrA = opsStaff('branch_manager', $this->a);
    $this->admin = User::factory()->superAdmin()->create();
});

/** The groups of an answer by key. @return array<string, array<string, mixed>> */
function searchGroups(User $who, string $q, string $locale = 'en'): array
{
    $res = test()->actingAs($who)->withSession(['locale' => $locale])->getJson(route('admin.search', ['q' => $q]))->assertOk();

    return collect($res->json('groups'))->keyBy('key')->all();
}

function searchTitles(array $group): array
{
    return array_column($group['items'] ?? [], 'title');
}

// ── Orders ──────────────────────────────────────────────────────────────────────────────────────────────────────────

it('finds an order by its whole number, by a piece of it, with a # or in lower case, and by the number of one of its branch parts', function (string $typed) {
    $o = opsOrder(test()->a, ['number' => 'AB100123']);
    opsOrder(test()->a, ['number' => 'AB100999']);

    $g = searchGroups(test()->mgrA, $typed);
    expect(searchTitles($g['orders']))->toContain('AB100123');
    expect($g['orders']['items'][0])->toMatchArray(['id' => $o['order'], 'title' => 'AB100123', 'icon' => 'bag', 'ltr' => true, 'url' => route('admin.orders.index', ['view' => $o['order']])]);
})->with(['AB100123', 'ab100123', '#AB100123', 'ab-100123', '100123', '0012', 'AB100123-1', ' ab 100123 ']);

it('puts the order whose number is exactly what was typed before newer ones that merely contain it', function () {
    $exact = opsOrder(test()->a, ['number' => 'AB100123'], [], opsAgo(5000));
    opsOrder(test()->a, ['number' => 'AB1001239'], [], opsAgo(10));
    $g = searchGroups(test()->mgrA, 'AB100123');
    expect(searchTitles($g['orders']))->toBe(['AB100123', 'AB1001239'])->and($g['orders']['items'][0]['id'])->toBe($exact['order']);
});

it('finds orders by the customer\'s phone however it is written: +974, 974, eight digits, Arabic digits, spaces and dashes', function (string $typed) {
    opsOrder(test()->a, ['number' => 'AB300001', 'customer_phone' => '+97455123456']);
    opsOrder(test()->a, ['number' => 'AB300002', 'customer_phone' => '+97466000111']);

    expect(searchTitles(searchGroups(test()->mgrA, $typed)['orders']))->toBe(['AB300001']);
})->with(['+974 5512 3456', '974 55123456', '55123456', '٥٥١٢٣٤٥٦', '5512-3456', '0097455123456', '(+974) 55 12 34 56']);

it('finds orders by the start or a piece of a phone number, but needs four digits', function () {
    opsOrder(test()->a, ['number' => 'AB300003', 'customer_phone' => '+97455123456']);
    expect(searchGroups(test()->mgrA, '5512'))->toHaveKey('orders')
        ->and(searchGroups(test()->mgrA, '+974 5512'))->toHaveKey('orders')
        ->and(searchGroups(test()->mgrA, '1234'))->toHaveKey('orders')
        ->and(searchGroups(test()->mgrA, '+974 1234'))->not->toHaveKey('orders')   // a country code starts the number: 1234 is not its start
        ->and(searchGroups(test()->mgrA, '551'))->not->toHaveKey('orders');         // three digits: only an order number could match, and none does
});

it('shows five orders and says there are more, with a link to the whole list', function () {
    foreach (range(1, 7) as $i) {
        opsOrder(test()->a, ['number' => 'AB4000'.$i]);
    }
    $g = searchGroups(test()->mgrA, 'AB4000');
    expect($g['orders']['items'])->toHaveCount(5)->and($g['orders']['more'])->toBe(['label' => __('search.see_all.orders'), 'url' => route('admin.orders.index', ['search' => 'AB4000'])]);
    expect(searchGroups(test()->mgrA, 'AB40001')['orders']['more'])->toBeNull();
});

it('shows an order as the Orders list does: customer, phone, status and total', function () {
    opsOrder(test()->a, ['number' => 'AB500001', 'customer_name' => 'Noor Al-Thani', 'customer_phone' => '+97455123456', 'status' => 'out_for_delivery', 'grand_total' => '1250.50']);
    $item = searchGroups(test()->mgrA, 'AB500001')['orders']['items'][0];
    expect($item['subtitle'])->toBe("Noor Al-Thani · \u{2066}+97455123456\u{2069}")->and($item['badge'])->toBe(__('status.out_for_delivery'))->and($item['meta'])->toContain('1,250.50');
});

// ── Branches: nobody finds what they could not open ───────────────────────────────────────────────────────────────────

it('never finds the orders or customers of another branch', function () {
    $cust = User::factory()->create(['name' => 'Hessa Al-Kuwari', 'phone' => '+97455777888']);
    opsOrder(test()->b, ['number' => 'AB600001', 'user_id' => $cust->id, 'customer_name' => 'Hessa Al-Kuwari', 'customer_phone' => '+97455777888']);
    $mine = User::factory()->create(['name' => 'Hessa Al-Marri', 'phone' => '+97455777999']);
    opsOrder(test()->a, ['number' => 'AB600002', 'user_id' => $mine->id, 'customer_name' => 'Hessa Al-Marri', 'customer_phone' => '+97455777999']);

    foreach (['AB600001', '55777888', 'Al-Kuwari'] as $typed) {
        expect(searchGroups(test()->mgrA, $typed))->not->toHaveKey('orders')->not->toHaveKey('customers');
    }
    expect(searchTitles(searchGroups(test()->mgrA, 'Hessa')['customers']))->toBe(['Hessa Al-Marri']);
    // the company-wide owner finds both, and the manager of branch B finds the first
    expect(searchTitles(searchGroups(test()->admin, 'Hessa')['customers']))->toBe(['Hessa Al-Kuwari', 'Hessa Al-Marri']);
    expect(searchTitles(searchGroups(opsStaff('branch_manager', test()->b), 'AB600001')['orders']))->toBe(['AB600001']);
});

it('finds an order that has a part in one of the person\'s branches, even when its other part is elsewhere', function () {
    $o = opsOrder(test()->b, ['number' => 'AB600010']);
    DB::table('order_fulfillments')->insert(['id' => (string) Str::uuid(), 'order_id' => $o['order'], 'branch_id' => test()->a->id, 'number' => 'AB600010-2', 'type' => 'delivery', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    expect(searchTitles(searchGroups(test()->mgrA, 'AB600010')['orders']))->toBe(['AB600010']);
});

// ── Permissions ─────────────────────────────────────────────────────────────────────────────────────────────────────

it('gives each group only to people who may open its page', function () {
    opsOrder(test()->a, ['number' => 'AB700001', 'customer_name' => 'Maryam Hassan', 'customer_phone' => '+97455000111']);
    Product::factory()->create(['sku' => 'ZZ-TABLE-1']);
    $driver = opsStaffWith(test()->a, ['deliveries.view', 'deliveries.edit']);      // no orders, customers or catalogue
    $ordersOnly = opsStaffWith(test()->a, ['orders.view']);
    $catalogOnly = opsStaffWith(test()->a, ['catalog.view']);

    expect(searchGroups($driver, 'AB700001'))->not->toHaveKeys(['orders', 'customers', 'products'])
        ->and(searchGroups($driver, 'Maryam'))->not->toHaveKeys(['orders', 'customers', 'products'])
        ->and(searchGroups($driver, 'ZZ-TABLE'))->not->toHaveKeys(['orders', 'customers', 'products']);
    expect(searchGroups($ordersOnly, 'AB700001'))->toHaveKey('orders')->not->toHaveKey('customers')->not->toHaveKey('products');
    expect(searchGroups($catalogOnly, 'ZZ-TABLE'))->toHaveKey('products')->not->toHaveKey('orders');
    expect(searchGroups($catalogOnly, 'AB700001'))->not->toHaveKey('orders');
});

it('answers only signed-in staff: a visitor and a customer are turned away', function () {
    $this->getJson(route('admin.search', ['q' => 'AB100123']))->assertStatus(401);
    $customer = User::factory()->create();
    $this->actingAs($customer)->getJson(route('admin.search', ['q' => 'AB100123']))->assertForbidden();
    $this->actingAs(opsStaff('delivery_employee', test()->a))->getJson(route('admin.search', ['q' => 'cash']))->assertOk();   // any staff member may use the menu search
});

// ── Customers and the masking rule ────────────────────────────────────────────────────────────────────────────────────

it('masks a customer\'s phone and e-mail unless the person may edit customers, exactly as the Customers list does', function () {
    $c = User::factory()->create(['name' => 'Fatima Al-Naimi', 'phone' => '+97455123456', 'email' => 'fatima.naimi@example.com']);
    opsOrder(test()->a, ['user_id' => $c->id]);
    $support = opsStaff('customer_support');                                   // customers.view AND customers.edit, company-wide

    $masked = searchGroups(test()->mgrA, 'Fatima')['customers']['items'][0];
    expect($masked['title'])->toBe('Fatima Al-Naimi')->and($masked['subtitle'])->not->toContain('55123456')->not->toContain('fatima.naimi')
        ->and($masked['subtitle'])->toBe("\u{2066}".Phone::mask('+97455123456')."\u{2069} · \u{2066}".CustomerIndex::maskEmail('fatima.naimi@example.com')."\u{2069}")
        ->and($masked['url'])->toBe(route('admin.customers.index', ['view' => $c->id]));
    expect(searchGroups($support, 'Fatima')['customers']['items'][0]['subtitle'])->toBe("\u{2066}+97455123456\u{2069} · \u{2066}fatima.naimi@example.com\u{2069}")
        ->and(searchGroups($this->admin, 'Fatima')['customers']['items'][0]['subtitle'])->toContain('+97455123456');
});

it('finds a customer by phone in any written form, by e-mail and by Arabic name, and flags a suspended one', function () {
    $c = User::factory()->create(['name' => 'فاطمة الكواري', 'phone' => '+97466123456', 'email' => 'fk@example.com', 'status' => 'suspended']);
    foreach (['+974 6612 3456', '66123456', '٦٦١٢٣٤٥٦', 'fk@example', 'فاطمة', 'الكواري'] as $typed) {
        $g = searchGroups($this->admin, $typed);
        expect(searchTitles($g['customers']))->toBe(['فاطمة الكواري'], "typed {$typed}")->and($g['customers']['items'][0]['badge'])->toBe(__('status.suspended'));
    }
    expect(searchGroups($this->admin, 'فاطمة', 'ar')['customers']['label'])->toBe(__('search.group.customers', [], 'ar'));
});

it('does not list staff accounts as customers', function () {
    $staff = User::factory()->staff()->create(['name' => 'Zainab Staffer']);
    expect(searchGroups($this->admin, 'Zainab'))->not->toHaveKey('customers');
});

// ── Products and variants ─────────────────────────────────────────────────────────────────────────────────────────────

it('finds products by English or Arabic name, by SKU and by the SKU or barcode of a variant', function () {
    $p = Product::factory()->create(['sku' => 'SOFA-ROME-1', 'barcode' => '6290001112223']);
    $p->translations()->where('locale', 'en')->first()->update(['name' => 'Rome Velvet Sofa']);
    $p->translations()->where('locale', 'ar')->first()->update(['name' => 'كنبة روما المخمل']);
    $v = Product::factory()->withVariants(2)->create(['sku' => 'CHAIR-X']);
    $v->variants()->where('sku', 'CHAIR-X-V2')->update(['barcode' => '6290009998887', 'name_en' => 'Grey', 'name_ar' => 'رمادي']);

    foreach (['velvet sofa', 'روما', 'كنبه روما', 'SOFA-ROME', 'sofa-rome-1', '6290001112'] as $typed) {
        expect(searchTitles(searchGroups($this->admin, $typed)['products']))->toBe(['Rome Velvet Sofa'], "typed {$typed}");
    }
    $item = searchGroups($this->admin, 'chair-x-v2')['products']['items'][0];
    expect($item['subtitle'])->toBe('CHAIR-X-V2 · Grey')->and($item['url'])->toBe(route('admin.products.index', ['view' => $v->id]))->and($item['icon'])->toBe('sofa');
    expect(searchGroups($this->admin, '6290009998')['products']['items'][0]['id'])->toBe($v->id)
        ->and(searchGroups($this->admin, 'chair-x-v2', 'ar')['products']['items'][0]['subtitle'])->toBe('CHAIR-X-V2 · رمادي');
    // a product with two options that both match appears once
    expect(searchGroups($this->admin, 'CHAIR-X')['products']['items'])->toHaveCount(1);
});

it('shows the product name in the dashboard language and says when a product is not on sale', function () {
    $p = Product::factory()->draft()->create(['sku' => 'LAMP-77']);
    $p->translations()->where('locale', 'en')->first()->update(['name' => 'Brass Lamp']);
    $p->translations()->where('locale', 'ar')->first()->update(['name' => 'مصباح نحاسي']);
    expect(searchGroups($this->admin, 'LAMP-77')['products']['items'][0])->toMatchArray(['title' => 'Brass Lamp', 'badge' => __('status.draft')])
        ->and(searchGroups($this->admin, 'LAMP-77', 'ar')['products']['items'][0]['title'])->toBe('مصباح نحاسي');
});

it('finds a product for a branch-limited person too: the catalogue is the same for every branch', function () {
    Product::factory()->create(['sku' => 'RUG-900']);
    expect(searchTitles(searchGroups(test()->mgrA, 'RUG-900')['products']))->toHaveCount(1);
});

// ── Go to ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('finds pages by name in English and Arabic and by the words people use for them, only those the person may open', function () {
    $find = fn (User $u, string $typed, string $locale = 'en') => array_column(searchGroups($u, $typed, $locale)['commands']['items'] ?? [], 'id');
    expect($find($this->admin, 'cash'))->toContain('admin.cash.index')
        ->and($find($this->admin, 'stocktake'))->toContain('admin.stocktaking.index')
        ->and($find($this->admin, 'returns'))->toContain('admin.returns.index')
        ->and($find($this->admin, 'جرد'))->toContain('admin.stocktaking.index')
        ->and($find($this->admin, 'نقد', 'ar'))->toContain('admin.cash.index')
        ->and($find($this->admin, 'الطلبات', 'ar'))->toContain('admin.orders.index')
        ->and($find($this->admin, 'طلبات'))->toContain('admin.orders.index')            // the Arabic name is found while the dashboard is in English
        ->and($find($this->admin, 'orders', 'ar'))->toContain('admin.orders.index');    // and the English name while it is in Arabic
    // a delivery person has no Cash page in the menu: it is not offered
    $driver = opsStaffWith(test()->a, ['deliveries.view', 'deliveries.edit']);
    expect($find($driver, 'cash'))->toBe([])->and($find($driver, 'deliveries'))->toContain('admin.deliveries.index');
});

it('lists a page before the other groups when its name is what was typed, and after them when a number was', function () {
    opsOrder(test()->a, ['number' => 'AB800001']);
    $keys = fn (string $q) => array_keys(searchGroups(test()->admin, $q));
    expect($keys('cash')[0])->toBe('commands')->and($keys('AB800001'))->toBe(['orders']);
    User::factory()->create(['name' => 'Cashier Jones']);
    expect($keys('Cashier'))->toBe(['customers']);   // "Cashier" is no page, and a person is not a page: only the customer
});

it('puts the group titles and the page names in the dashboard language', function () {
    Product::factory()->create(['sku' => 'TBL-1']);
    $g = searchGroups($this->admin, 'TBL-1', 'ar');
    expect($g['products']['label'])->toBe(__('search.group.products', [], 'ar'));
    $pages = searchGroups($this->admin, 'orders', 'ar')['commands'];
    expect($pages['label'])->toBe(__('search.group.commands', [], 'ar'))->and($pages['items'][0]['title'])->toBe(__('nav.orders', [], 'ar'));
});

// ── Safe with any input ───────────────────────────────────────────────────────────────────────────────────────────────

it('reads a one-letter query as nothing to look for and a two-letter one as pages only', function () {
    opsOrder(test()->a, ['number' => 'AB900123']);
    $one = test()->actingAs(test()->mgrA)->getJson(route('admin.search', ['q' => 'o']))->assertOk()->json();
    expect($one['state'])->toBe('short')->and($one['groups'])->toBe([]);
    $two = test()->actingAs(test()->mgrA)->getJson(route('admin.search', ['q' => 'or']))->assertOk()->json();
    expect($two['state'])->toBe('pages')->and(array_column($two['groups'], 'key'))->toBe(['commands']);
    $blank = test()->actingAs(test()->mgrA)->getJson(route('admin.search'))->assertOk()->json();
    expect($blank['state'])->toBe('short')->and($blank['groups'])->toBe([]);
    expect(test()->actingAs(test()->mgrA)->getJson(route('admin.search', ['q' => ['x', 'y']]))->assertOk()->json('state'))->toBe('short');   // ?q[]= is not text
});

it('treats SQL, LIKE characters, quotes, emoji and invalid text as plain words that find nothing', function (string $typed) {
    opsOrder(test()->a, ['number' => 'AB910001', 'customer_name' => "O'Brien 100% sure_ok"]);
    User::factory()->create(['name' => 'Ordinary Customer']);
    $res = test()->actingAs(test()->admin)->getJson(route('admin.search', ['q' => $typed]));
    $res->assertOk();
    expect(DB::table('users')->count())->toBeGreaterThan(0)->and(Schema::hasTable('orders'))->toBeTrue();
    expect(array_diff(array_column($res->json('groups'), 'key'), ['commands']))->toBe([]);
})->with([
    "'; DROP TABLE users; --", '" OR 1=1 --', '%%%', '___', '\\\\', '100%', '😀😀😀😀', "abc\x00def\x00", '<script>alert(1)</script>', '{{7*7}}', '..%2f..%2fetc', "AB'||'0",
]);

it('does not let % or _ stand for "anything" in a name or an order number', function () {
    opsOrder(test()->a, ['number' => 'AB910002']);
    User::factory()->create(['name' => 'Plain Name']);
    expect(searchGroups($this->admin, '%%%'))->toBe([])->and(searchGroups($this->admin, 'P_ain'))->not->toHaveKey('customers')
        ->and(searchGroups($this->admin, 'AB91000_'))->not->toHaveKey('orders');
});

it('cuts a very long query and still answers', function () {
    opsOrder(test()->a, ['number' => 'AB910003']);
    $long = str_repeat('AB910003 ', 400);
    $res = test()->actingAs(test()->admin)->getJson(route('admin.search', ['q' => $long]))->assertOk();
    expect(mb_strlen($res->json('q')))->toBeLessThanOrEqual(SearchQuery::MAX_LENGTH);
    $arabic = str_repeat('فاطمة ', 300);
    expect(mb_strlen(test()->actingAs(test()->admin)->getJson(route('admin.search', ['q' => $arabic]))->assertOk()->json('q')))->toBeLessThanOrEqual(SearchQuery::MAX_LENGTH);
});

it('survives text that is not valid UTF-8', function () {
    $res = test()->actingAs(test()->admin)->get('/admin/search?q=%FF%FEabc%C3', ['Accept' => 'application/json']);
    $res->assertOk();
    expect($res->json('groups'))->toBeArray();
});

it('stays answerable when nothing at all matches', function () {
    $res = test()->actingAs(test()->admin)->getJson(route('admin.search', ['q' => 'qqqqqqqq']))->assertOk()->json();
    expect($res['state'])->toBe('ready')->and($res['groups'])->toBe([]);
});

// ── Rate limit, privacy, headers ──────────────────────────────────────────────────────────────────────────────────────

it('answers 429 after 90 searches in a minute, per person, and starts again after the minute', function () {
    foreach (range(1, 90) as $i) {
        test()->actingAs(test()->mgrA)->getJson(route('admin.search', ['q' => 'ord']))->assertOk();
    }
    test()->actingAs(test()->mgrA)->getJson(route('admin.search', ['q' => 'ord']))->assertStatus(429)->assertHeader('Retry-After');
    test()->actingAs(test()->admin)->getJson(route('admin.search', ['q' => 'ord']))->assertOk();           // somebody else is not affected
    $this->travel(61)->seconds();
    test()->actingAs(test()->mgrA)->getJson(route('admin.search', ['q' => 'ord']))->assertOk();
});

it('is never cached and never writes the query to the log', function () {
    Log::spy();
    $res = test()->actingAs(test()->admin)->getJson(route('admin.search', ['q' => '+974 5512 3456']))->assertOk();
    expect($res->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('debug');
    Log::shouldNotHaveReceived('warning');
});

it('runs each group as one bounded statement however many rows exist', function () {
    foreach (range(1, 30) as $i) {
        opsOrder(test()->a, ['number' => 'AB9300'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'customer_phone' => '+97455123456']);
        User::factory()->create(['name' => 'Many Names '.$i]);
        Product::factory()->create(['sku' => 'MANY-'.$i]);
    }
    DB::enableQueryLog();
    $g = searchGroups($this->admin, 'many');
    $lookups = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'ilike') || str_contains($sql, 'with m as'));
    expect($g['customers']['items'])->toHaveCount(5)->and($g['products']['items'])->toHaveCount(5);
    expect($lookups)->toHaveCount(3);                                           // orders, customers, products: one statement each, however many rows match
    foreach ($lookups as $sql) {
        expect($sql)->toContain('limit');
    }
    expect(searchGroups($this->admin, 'AB9300')['orders']['items'])->toHaveCount(5);
});

it('keeps the search words of a page in step in English and Arabic', function () {
    $en = (require lang_path('en/search.php'))['aliases'];
    $ar = (require lang_path('ar/search.php'))['aliases'];
    expect(array_keys($en))->toBe(array_keys($ar))                                         // the same pages in both languages
        ->and(count($en))->toBeGreaterThan(40);                                           // a page without words is still found by its name, but most have some
    foreach ($en + $ar as $key => $words) {
        expect(trim((string) $words))->not->toBe('', "no words for {$key}");
    }
    foreach ($ar as $key => $words) {
        expect(preg_match('/\p{Arabic}/u', $words))->toBe(1, "Arabic words for {$key}");
    }
});

it('is the same answer through the service as through the route', function () {
    opsOrder(test()->a, ['number' => 'AB940001']);
    $direct = app(GlobalSearch::class)->run($this->admin, 'AB940001');
    expect($direct['groups'][0]['key'])->toBe('orders')->and($direct['state'])->toBe('ready');
});
