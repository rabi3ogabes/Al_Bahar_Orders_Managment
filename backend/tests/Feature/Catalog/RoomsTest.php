<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductBranchSetting;
use App\Domain\Catalog\Models\Room;
use App\Domain\Catalog\Services\CategoryService;
use App\Domain\Catalog\Services\RoomService;
use App\Domain\Cms\Models\HomepageSection;
use App\Domain\Cms\Services\HomeLayout;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRole;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Content\HomepageIndex;
use App\Livewire\Admin\Content\RoomIndex;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| "Shop by room": the rooms the owner manages, what belongs to a room (categories with their sub-categories, and tags), the API the home page and the shop filter
| read, the room page for search engines, and the dashboard page.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Cache::flush();
    Storage::fake(config('platform.media.disk'));
    Room::query()->delete(); // the migration's default rooms: each test builds the ones it needs
});

function roomCategory(string $slug, ?Category $parent = null, ?string $image = null): Category
{
    $c = app(CategoryService::class)->create(['slug' => $slug, 'parent_id' => $parent?->id, 'translations' => ['en' => ['name' => ucfirst($slug)], 'ar' => ['name' => 'فئة '.$slug]]]);
    if ($image) {
        $c->update(['image_path' => $image]);
    }

    return $c->fresh();
}

function roomProduct(?Category $category = null, array $tags = [], array $attrs = []): Product
{
    $p = Product::factory()->create(['category_id' => $category?->id, 'tags' => $tags ?: null] + $attrs);

    return $p->fresh();
}

function makeRoom(string $slug, array $categories = [], array $tags = [], array $over = []): Room
{
    $room = Room::create(['slug' => $slug, 'title_en' => ucfirst($slug), 'title_ar' => 'غرفة '.$slug, 'tags' => $tags ?: null, 'sort_order' => Room::count()] + $over);
    $room->categories()->sync(array_map(fn (Category $c) => $c->id, $categories));
    roomsFlush();

    return $room;
}

function roomsFlush(): void
{
    RoomService::flush();
}

// ── The defaults ─────────────────────────────────────────────────────────────────────────────────────────────────

it('ships five default rooms mapped to the real categories, and adds no home section by itself', function () {
    $migration = require database_path('migrations/2026_11_21_140000_create_rooms.php');
    $migration->down();
    HomepageSection::query()->forceDelete();
    $cats = collect(['bedding-linens', 'kitchen-dining', 'bath-personal', 'decor-living', 'home-fragrance'])->mapWithKeys(fn ($s) => [$s => roomCategory($s)]);
    $top = HomepageSection::create(['type' => 'categories', 'layout' => 'grid', 'title_en' => 'Categories', 'sort_order' => 3, 'status' => 'published']);
    $migration->up();

    $rooms = Room::with('categories')->orderBy('sort_order')->get();
    expect($rooms->pluck('slug')->all())->toBe(['bedroom', 'kitchen', 'bathroom', 'living-room', 'dining']);
    expect($rooms[0]->title_en)->toBe('Bedroom')->and($rooms[0]->title_ar)->toBe('غرفة النوم')->and($rooms[0]->categories->pluck('slug')->all())->toBe(['bedding-linens'])
        ->and($rooms[3]->categories->pluck('slug')->sort()->values()->all())->toBe(['decor-living', 'home-fragrance'])
        ->and($rooms[4]->categories)->toHaveCount(0)->and($rooms[4]->tags)->toBe(['dining']);
    expect(HomepageSection::where('type', 'rooms')->count())->toBe(0)->and($top->fresh()->sort_order)->toBe(3); // the migration leaves the home page alone
});

it('puts the rooms section on the home page once, right after the categories, and never brings it back after the owner removed it', function () {
    HomepageSection::query()->forceDelete();
    HomepageSection::create(['type' => 'categories', 'layout' => 'grid', 'title_en' => 'Categories', 'sort_order' => 3, 'status' => 'published']);
    HomepageSection::create(['type' => 'new_arrivals', 'layout' => 'carousel', 'title_en' => 'New', 'sort_order' => 4, 'status' => 'published']);
    $service = app(RoomService::class);

    expect($service->ensureHomeSection())->toBeTrue();
    $section = HomepageSection::where('type', 'rooms')->firstOrFail();
    expect($section->status)->toBe('published')->and($section->title_ar)->toBe('تسوّق حسب الغرفة')
        ->and(HomepageSection::orderBy('sort_order')->pluck('type')->all())->toBe(['categories', 'rooms', 'new_arrivals']);

    expect($service->ensureHomeSection())->toBeFalse()->and(HomepageSection::where('type', 'rooms')->count())->toBe(1); // a second run changes nothing
    app(HomeLayout::class)->removeSection($section);
    expect($service->ensureHomeSection())->toBeFalse()->and(HomepageSection::where('type', 'rooms')->count())->toBe(0); // removed on purpose: stays removed
});

it('renames the seeded "Shop by room" categories section so the page does not show the same heading twice, but leaves a title the owner chose alone', function () {
    HomepageSection::query()->forceDelete();
    $cats = HomepageSection::create(['type' => 'categories', 'layout' => 'chips', 'title_en' => 'Shop by room', 'title_ar' => 'تسوّق حسب الغرفة', 'sort_order' => 1, 'status' => 'published']);
    app(RoomService::class)->ensureHomeSection();
    expect($cats->fresh()->title_en)->toBe('Shop by category')->and($cats->fresh()->title_ar)->toBe('تسوّق حسب الفئة')
        ->and(HomepageSection::where('type', 'rooms')->value('title_en'))->toBe('Shop by room');

    HomepageSection::query()->forceDelete();
    app(Settings::class)->set('rooms.home_section_seeded', false);
    $mine = HomepageSection::create(['type' => 'categories', 'layout' => 'chips', 'title_en' => 'Our departments', 'title_ar' => 'أقسامنا', 'sort_order' => 1, 'status' => 'published']);
    app(RoomService::class)->ensureHomeSection();
    expect($mine->fresh()->title_en)->toBe('Our departments');
});

it('puts the rooms section at the bottom when the home page has no categories section, and does not duplicate one the owner already added', function () {
    HomepageSection::query()->forceDelete();
    HomepageSection::create(['type' => 'new_arrivals', 'layout' => 'carousel', 'title_en' => 'New', 'sort_order' => 1, 'status' => 'published']);
    expect(app(RoomService::class)->ensureHomeSection())->toBeTrue()
        ->and(HomepageSection::where('type', 'rooms')->value('sort_order'))->toBe(2);

    app(Settings::class)->set('rooms.home_section_seeded', false);
    expect(app(RoomService::class)->ensureHomeSection())->toBeFalse()->and(HomepageSection::where('type', 'rooms')->count())->toBe(1);
});

it('adds the home section from the command and when staff who may edit content open the rooms page, but not for staff who may only look', function () {
    HomepageSection::query()->forceDelete();
    $this->artisan('rooms:ensure-home')->expectsOutputToContain('was added')->assertSuccessful();
    expect(HomepageSection::where('type', 'rooms')->count())->toBe(1);
    $this->artisan('rooms:ensure-home')->expectsOutputToContain('Nothing to do')->assertSuccessful();

    HomepageSection::query()->forceDelete();
    app(Settings::class)->set('rooms.home_section_seeded', false);
    $this->actingAs(staffWithRole('admin'));
    Livewire::test(RoomIndex::class)->assertOk();
    expect(HomepageSection::where('type', 'rooms')->count())->toBe(1);

    // someone who may only look at the content changes nothing by opening the page
    HomepageSection::query()->forceDelete();
    app(Settings::class)->set('rooms.home_section_seeded', false);
    $viewer = User::factory()->staff()->create();
    $role = Role::create(['key' => 'content_viewer', 'name_en' => 'Content viewer', 'name_ar' => 'مشاهد', 'scope' => 'global', 'is_system' => false]);
    $role->permissions()->attach(Permission::where('key', 'cms.view')->first());
    UserRole::create(['user_id' => $viewer->id, 'role_id' => $role->id]);
    $this->actingAs($viewer);
    Livewire::test(RoomIndex::class)->assertOk();
    expect(HomepageSection::where('type', 'rooms')->count())->toBe(0);
});

// ── What belongs to a room ───────────────────────────────────────────────────────────────────────────────────────

it('puts a product in a room by category (sub-categories included) or by tag, in any case, and nothing else', function () {
    $bedding = roomCategory('bedding');
    $duvets = roomCategory('duvets', $bedding);
    $kitchen = roomCategory('kitchen-dining');
    $inBedding = roomProduct($bedding);
    $inDuvets = roomProduct($duvets);
    $tagged = roomProduct($kitchen, ['Bedroom', 'cosy']);
    $other = roomProduct($kitchen, ['dining']);
    $none = roomProduct(null);
    makeRoom('bedroom', [$bedding], ['bedroom']);

    $ids = collect($this->getJson('/api/v1/products?room=bedroom&per_page=50')->assertOk()->json('data'))->pluck('id');
    expect($ids->all())->toEqualCanonicalizing([$inBedding->id, $inDuvets->id, $tagged->id])->and($ids->contains($other->id))->toBeFalse()->and($ids->contains($none->id))->toBeFalse();

    // It combines with the other filters, and its total is the room's.
    $this->getJson('/api/v1/products?room=bedroom&category_id='.$duvets->id)->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/products?room=bedroom&price_min=5000')->assertOk()->assertJsonPath('meta.total', 0);
});

it('shows an unknown, switched-off or empty room nothing at all, never the whole catalogue', function () {
    roomProduct();
    makeRoom('off', [], ['x'], ['is_active' => false]);
    makeRoom('empty');
    foreach (['nope', 'off', 'empty'] as $slug) {
        $this->getJson("/api/v1/products?room={$slug}")->assertOk()->assertJsonPath('meta.total', 0);
    }
    $this->getJson('/api/v1/products?room='.str_repeat('x', 61))->assertStatus(422);
    $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('meta.total', 1);
});

it('is safe with odd tags: quotes, commas, Arabic and a product with no tags', function () {
    roomProduct(null, ['Say "hi", ok']);
    roomProduct(null, ['غرفة نوم']);
    roomProduct(null);
    makeRoom('odd', [], ['say "hi", ok', 'غرفة نوم']);
    $this->getJson('/api/v1/products?room=odd')->assertOk()->assertJsonPath('meta.total', 2);
});

// ── The tiles ────────────────────────────────────────────────────────────────────────────────────────────────────

it('serves only rooms that have products, in the owner order, with a picture (its own or its first category\'s) and a count, in both languages', function () {
    $bed = roomCategory('bedding-linens', null, 'categories/bedding.webp');
    $kit = roomCategory('kitchen-dining');
    roomProduct($bed);
    roomProduct($bed);
    roomProduct($kit);
    makeRoom('kitchen', [$kit], [], ['image_path' => 'rooms/kitchen.webp', 'description_en' => 'Pots and pans']);
    makeRoom('bedroom', [$bed]);
    makeRoom('dining', [], ['dining']);                                                // nothing tagged yet: not shown
    makeRoom('hidden', [$bed], [], ['is_active' => false]);

    $en = $this->getJson('/api/v1/rooms')->assertOk()->json('data');
    expect(collect($en)->pluck('slug')->all())->toBe(['kitchen', 'bedroom'])
        ->and($en[0])->toMatchArray(['name' => 'Kitchen', 'products_count' => 1, 'description' => 'Pots and pans', 'link' => ['type' => 'room', 'value' => 'kitchen']])
        ->and($en[0]['image_url'])->toContain('rooms/kitchen.webp')->and($en[1]['image_url'])->toContain('categories/bedding.webp')->and($en[1]['products_count'])->toBe(2);
    $ar = $this->withHeaders(['Accept-Language' => 'ar'])->getJson('/api/v1/rooms')->assertOk()->json('data');
    expect($ar[0]['name'])->toBe('غرفة kitchen');
});

it('counts what the shopper can see at the chosen branch, and forgets its tiles the moment the owner changes a room', function () {
    $branchA = Branch::factory()->create();
    $branchB = Branch::factory()->create();
    $cat = roomCategory('bedding-linens');
    $p = roomProduct($cat, [], ['available_in_all_branches' => false]);
    ProductBranchSetting::create(['product_id' => $p->id, 'branch_id' => $branchA->id, 'is_enabled' => true, 'is_visible' => true]);
    roomProduct($cat);
    $room = makeRoom('bedroom', [$cat]);

    expect($this->getJson('/api/v1/rooms?branch_id='.$branchA->id)->json('data.0.products_count'))->toBe(2)
        ->and($this->getJson('/api/v1/rooms?branch_id='.$branchB->id)->json('data.0.products_count'))->toBe(1);

    $room->update(['title_en' => 'Master bedroom']);
    expect($this->getJson('/api/v1/rooms')->json('data.0.name'))->toBe('Master bedroom');
    $room->update(['is_active' => false]);
    expect($this->getJson('/api/v1/rooms')->json('data'))->toBe([]);
});

it('puts the rooms section on the home feed, after the categories, and leaves it out when no room has products', function () {
    HomepageSection::query()->forceDelete();
    HomepageSection::create(['type' => 'categories', 'layout' => 'grid', 'title_en' => 'Categories', 'sort_order' => 1, 'status' => 'published']);
    HomepageSection::create(['type' => 'rooms', 'layout' => 'grid', 'title_en' => 'Shop by room', 'title_ar' => 'تسوّق حسب الغرفة', 'sort_order' => 2, 'status' => 'published', 'item_limit' => 8]);
    $cat = roomCategory('bedding-linens');
    HomepageSection::query()->where('type', 'categories')->update(['updated_at' => now()]);
    expect(collect($this->getJson('/api/v1/home')->json('data.sections'))->pluck('type')->all())->not->toContain('rooms'); // no room has products yet

    roomProduct($cat);
    makeRoom('bedroom', [$cat]);
    $sections = collect($this->getJson('/api/v1/home')->assertOk()->json('data.sections'));
    $rooms = $sections->firstWhere('type', 'rooms');
    expect($rooms)->not->toBeNull()->and($rooms['title'])->toBe('Shop by room')->and($rooms['items'])->toHaveCount(1)->and($rooms['items'][0])->toMatchArray(['slug' => 'bedroom', 'name' => 'Bedroom', 'products_count' => 1]);
    $ar = collect($this->withHeaders(['Accept-Language' => 'ar'])->getJson('/api/v1/home')->json('data.sections'))->firstWhere('type', 'rooms');
    expect($ar['title'])->toBe('تسوّق حسب الغرفة')->and($ar['items'][0]['name'])->toBe('غرفة bedroom');
});

// ── Search engines ───────────────────────────────────────────────────────────────────────────────────────────────

it('gives a room page its own title, description, canonical link and structured data, and lists it in the sitemap only when it has products', function () {
    $dir = sys_get_temp_dir().'/albahar-rooms-'.getmypid().'-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($dir);
    file_put_contents($dir.'/index.html', '<!doctype html><html><head><meta charset="UTF-8" /><meta name="description" content="Base" /><title>Al Bahar Home &amp; Living</title></head><body><div id="root"></div></body></html>');
    config(['platform.storefront_shell' => $dir.'/index.html', 'app.url' => 'https://shop.example']);
    $cat = roomCategory('bedding-linens', null, 'categories/bedding.webp');
    roomProduct($cat);
    makeRoom('bedroom', [$cat], [], ['description_en' => 'Sheets & duvets for better sleep.']);
    makeRoom('dining', [], ['dining']);

    $html = $this->get('/shop?room=bedroom')->assertOk()->getContent();
    expect($html)->toContain('<title>Bedroom · Al Bahar Home &amp; Living</title>')->and($html)->toContain('content="Sheets &amp; duvets for better sleep."')
        ->and($html)->toContain('<link rel="canonical" href="https://shop.example/shop?room=bedroom" />')->and($html)->toContain('"@type":"CollectionPage"')->and($html)->toContain('categories/bedding.webp');
    $generic = $this->get('/shop?room=nope')->assertOk()->getContent();
    expect($generic)->toContain('<title>Shop · Al Bahar Home &amp; Living</title>')->and($generic)->toContain('href="https://shop.example/shop"');

    Cache::flush();
    $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
    expect($sitemap)->toContain('<loc>https://shop.example/shop?room=bedroom</loc>')->and($sitemap)->not->toContain('room=dining');
    File::deleteDirectory($dir);
});

// ── The dashboard ────────────────────────────────────────────────────────────────────────────────────────────────

it('lets the owner add a room with a picture, titles, categories and tags, edit it, hide it, reorder and delete it, all audited', function () {
    $admin = staffWithRole('admin');
    $this->actingAs($admin);
    $cat = roomCategory('bedding-linens');
    $sub = roomCategory('duvets', $cat);

    $c = Livewire::test(RoomIndex::class)->call('create')->set('form.title_en', 'Master Bedroom')->assertSet('form.slug', 'master-bedroom')->set('form.title_ar', 'غرفة النوم الرئيسية')
        ->set('form.description_en', 'Sleep well')->set('form.tags', ' Bedroom, SLEEP ,bedroom, , Cosy ')->set('form.category_ids', [$cat->id, $sub->id])
        ->set('image', UploadedFile::fake()->image('room.jpg', 800, 1000))->call('save')->assertHasNoErrors();
    $room = Room::where('slug', 'master-bedroom')->with('categories')->firstOrFail();
    expect($room->tags)->toBe(['bedroom', 'sleep', 'cosy'])->and($room->categories)->toHaveCount(2)->and($room->image_path)->toStartWith('rooms/')->and($room->is_active)->toBeTrue()
        ->and(AuditLog::where('action', 'cms.room_created')->exists())->toBeTrue();
    Storage::disk(config('platform.media.disk'))->assertExists($room->image_path);
    $firstImage = $room->image_path;

    $c->call('edit', $room->id)->set('form.title_en', 'Bedroom')->set('form.category_ids', [$cat->id])->set('image', UploadedFile::fake()->image('new.jpg', 800, 1000))->call('save')->assertHasNoErrors();
    expect($room->fresh()->title_en)->toBe('Bedroom')->and($room->fresh()->categories)->toHaveCount(1)->and($room->fresh()->image_path)->not->toBe($firstImage)
        ->and(AuditLog::where('action', 'cms.room_updated')->exists())->toBeTrue();
    Storage::disk(config('platform.media.disk'))->assertMissing($firstImage);

    $other = makeRoom('kitchen');
    $c->call('move', $other->id, -1);
    expect(Room::orderBy('sort_order')->pluck('slug')->all())->toBe(['kitchen', 'master-bedroom'])->and(AuditLog::where('action', 'cms.rooms_reordered')->exists())->toBeTrue();
    $c->call('toggle', $other->id);
    expect($other->fresh()->is_active)->toBeFalse()->and(AuditLog::where('action', 'cms.room_hidden')->exists())->toBeTrue();
    $image = $room->fresh()->image_path;
    $c->call('delete', $room->id);
    expect(Room::where('slug', 'master-bedroom')->exists())->toBeFalse()->and(AuditLog::where('action', 'cms.room_deleted')->exists())->toBeTrue();
    Storage::disk(config('platform.media.disk'))->assertMissing($image);
});

it('checks what the owner types: names, a unique address word, real categories, a real picture', function () {
    $this->actingAs(staffWithRole('admin'));
    makeRoom('kitchen');
    Livewire::test(RoomIndex::class)->call('create')->set('form.title_en', '')->set('form.title_ar', '')->set('form.slug', 'Not Valid!')->call('save')
        ->assertHasErrors(['form.title_en', 'form.title_ar', 'form.slug']);
    Livewire::test(RoomIndex::class)->call('create')->set('form.title_en', 'K')->set('form.title_ar', 'ك')->set('form.slug', 'kitchen')->call('save')->assertHasErrors(['form.slug']);
    Livewire::test(RoomIndex::class)->call('create')->set('form.title_en', 'X')->set('form.title_ar', 'س')->set('form.slug', 'x')->set('form.category_ids', ['not-a-uuid'])->call('save')->assertHasErrors(['form.category_ids.0']);
    Livewire::test(RoomIndex::class)->call('create')->set('form.title_en', 'X')->set('form.title_ar', 'س')->set('form.slug', 'x')->set('image', UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'))->call('save')->assertHasErrors(['image']);
    expect(Room::where('slug', 'x')->exists())->toBeFalse();
});

it('keeps the page for people who may see it, and every change for people who may edit', function () {
    $room = makeRoom('kitchen');
    $this->actingAs(staffWithRole('auditor'));
    Livewire::test(RoomIndex::class)->assertSee('Kitchen')->assertDontSeeHtml('wire:click="create"');
    Livewire::test(RoomIndex::class)->call('create')->assertForbidden();
    Livewire::test(RoomIndex::class)->call('toggle', $room->id)->assertForbidden();
    Livewire::test(RoomIndex::class)->call('delete', $room->id)->assertForbidden();
    expect($room->fresh()->is_active)->toBeTrue();
    $this->actingAs(roomsNoRightsUser());
    $this->get('/admin/rooms')->assertForbidden();
});

function roomsNoRightsUser()
{
    return staffWithRole('delivery_employee');
}

it('says which rooms customers do not see yet, and offers the room section in the home page builder', function () {
    $this->actingAs(staffWithRole('admin'));
    makeRoom('dining', [], ['dining']);
    Livewire::test(RoomIndex::class)->assertSee('No products yet');
    expect(HomepageIndex::TYPES)->toContain('rooms');
    Livewire::test(HomepageIndex::class)->call('create')->assertSee('Shop by room');
    DB::table('rooms')->count();
    $c = Product::factory()->create(['tags' => ['dining']]);
    app(InventoryLedger::class)->apply(Branch::factory()->create()->id, $c->variants()->first()->id, MovementType::OpeningBalance, 1);
    roomsFlush();
    Livewire::test(RoomIndex::class)->assertSee('1 product')->assertDontSee('No products yet');
});
