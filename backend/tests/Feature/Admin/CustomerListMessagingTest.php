<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\Journey;
use App\Domain\Marketing\Models\JourneyDelivery;
use App\Domain\Marketing\Models\JourneyRun;
use App\Domain\Messaging\Data\AudienceSelection;
use App\Domain\Messaging\Models\CustomerMessage;
use App\Domain\Messaging\Models\MessageBatch;
use App\Domain\Messaging\Services\CustomerMessaging;
use App\Domain\Orders\Models\Cart;
use App\Domain\Orders\Models\CartItem;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Messaging\MessageComposer;
use App\Livewire\Admin\Sales\CustomerIndex;
use App\Livewire\Admin\Sales\CustomerShow;
use App\Support\BusinessTime;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

require_once __DIR__.'/MessagingSupport.php';

/* The customers list as the messaging feature uses it: ticking, "everyone matching", the cart and last-message filters, the last-message column. */

function clCustomer(array $attrs = []): User
{
    return User::factory()->create($attrs + ['marketing_consent_at' => now(), 'locale' => 'en']);
}

function clCart(User $u, bool $withItem = true, string $status = 'active'): Cart
{
    $cart = Cart::create(['user_id' => $u->id, 'status' => $status, 'last_activity_at' => now()->subHour()]);
    if ($withItem) {
        CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => Product::factory()->create()->variants()->first()->id, 'quantity' => 1]);
    }

    return $cart;
}

/** A message that really went out to this customer, $ago ago. */
function clSent(User $u, DateTimeInterface $at): void
{
    static $batch = null;
    $batch ??= MessageBatch::create(['created_by' => User::factory()->staff()->create()->id, 'template_name' => 'x', 'kind' => 'custom', 'source' => 'bulk', 'channels' => ['notification'], 'content' => [],
        'audience' => [], 'recipients_count' => 1, 'status' => 'done']);
    if (! MessageBatch::whereKey($batch->id)->exists()) {
        $batch = MessageBatch::create(['created_by' => User::factory()->staff()->create()->id, 'template_name' => 'x', 'kind' => 'custom', 'source' => 'bulk', 'channels' => ['notification'], 'content' => [],
            'audience' => [], 'recipients_count' => 1, 'status' => 'done']);
    }
    CustomerMessage::create(['batch_id' => $batch->id, 'user_id' => $u->id, 'channel' => 'notification', 'locale' => 'en', 'status' => 'sent', 'sent_at' => $at]);
}

/** @return list<string> */
function clIds(Testable $t): array
{
    return $t->viewData('customers')->getCollection()->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = staffWithRole('marketing_manager');
    $this->actingAs($this->staff);
});

describe('filters', function () {
    it('finds customers with an order left in the cart, or with none', function () {
        $with = clCustomer(['name' => 'With Cart']);
        clCart($with);
        $emptyCart = clCustomer(['name' => 'Empty Cart']);
        clCart($emptyCart, withItem: false);
        $converted = clCustomer(['name' => 'Bought Already']);
        clCart($converted, status: 'converted');
        $none = clCustomer(['name' => 'No Cart']);

        expect(clIds(Livewire::test(CustomerIndex::class)->set('filterCart', 'yes')))->toBe([(string) $with->id])
            ->and(clIds(Livewire::test(CustomerIndex::class)->set('filterCart', 'no')))->toBe(collect([$emptyCart, $converted, $none])->pluck('id')->map(fn ($i) => (string) $i)->sort()->values()->all())
            ->and(clIds(Livewire::test(CustomerIndex::class)->set('filterCart', '')))->toHaveCount(4);
    });

    it('finds customers who were not messaged for a while', function () {
        $never = clCustomer(['name' => 'Never']);
        $hours = clCustomer(['name' => 'Two Hours']);
        clSent($hours, now()->subHours(2));
        $days = clCustomer(['name' => 'Five Days']);
        clSent($days, now()->subDays(5));
        $old = clCustomer(['name' => 'Two Months']);
        clSent($old, now()->subDays(60));
        $journey = clCustomer(['name' => 'Journey Only']);
        $j = Journey::create(['key' => 'abandoned_cart', 'name' => 'x', 'is_enabled' => true]);
        $run = JourneyRun::create(['journey_id' => $j->id, 'user_id' => $journey->id, 'anchor_at' => now()->subHour()]);
        JourneyDelivery::create(['run_id' => $run->id, 'user_id' => $journey->id, 'channel' => 'push', 'locale' => 'en', 'status' => 'sent', 'sent_at' => now()->subHours(3)]);

        $names = fn (string $f) => Livewire::test(CustomerIndex::class)->set('filterContact', $f)->viewData('customers')->pluck('name')->sort()->values()->all();

        expect($names('never'))->toBe(['Never'])
            ->and($names('24h'))->toBe(['Five Days', 'Never', 'Two Months'])
            ->and($names('3d'))->toBe(['Five Days', 'Never', 'Two Months'])
            ->and($names('7d'))->toBe(['Never', 'Two Months'])
            ->and($names('30d'))->toBe(['Never', 'Two Months'])
            ->and($names(''))->toHaveCount(5);
    });

    it('keeps the search, status and consent filters working', function () {
        clCustomer(['name' => 'Layla Hassan', 'email' => 'layla@example.com', 'phone' => '+97455001122']);
        clCustomer(['name' => 'Omar Khalid', 'marketing_consent_at' => null]);
        clCustomer(['name' => 'Suspended Sam', 'status' => 'suspended']);

        expect(Livewire::test(CustomerIndex::class)->set('search', 'layla@')->viewData('customers')->pluck('name')->all())->toBe(['Layla Hassan'])
            ->and(Livewire::test(CustomerIndex::class)->set('search', '+97455001122')->viewData('customers')->pluck('name')->all())->toBe(['Layla Hassan'])
            ->and(Livewire::test(CustomerIndex::class)->set('filterConsent', 'no')->viewData('customers')->pluck('name')->all())->toBe(['Omar Khalid'])
            ->and(Livewire::test(CustomerIndex::class)->set('filterStatus', 'suspended')->viewData('customers')->pluck('name')->all())->toBe(['Suspended Sam']);
    });

    it('keeps the filters in the address bar', function () {
        Livewire::withQueryParams(['filterCart' => 'yes', 'filterContact' => '7d'])->test(CustomerIndex::class)->assertSet('filterCart', 'yes')->assertSet('filterContact', '7d');
    });

    it('can never disagree with "select everyone matching": the list and the audience are the same customers', function (array $filters) {
        foreach (range(1, 6) as $i) {
            $u = clCustomer(['name' => "Customer $i", 'marketing_consent_at' => $i % 2 ? now() : null, 'status' => $i === 5 ? 'suspended' : 'active']);
            $i % 3 === 0 ? clCart($u) : null;
            $i % 4 === 0 ? clSent($u, now()->subHours(3)) : null;
        }
        $map = ['search' => 'search', 'status' => 'filterStatus', 'consent' => 'filterConsent', 'cart' => 'filterCart', 'contact' => 'filterContact'];
        $list = Livewire::test(CustomerIndex::class);
        foreach ($filters as $key => $value) {
            $list->set($map[$key], $value);
        }
        $audience = app(CustomerMessaging::class)->audienceQuery($this->staff, AudienceSelection::matching($filters))->pluck('users.id')->map(fn ($id) => (string) $id)->sort()->values()->all();

        expect(clIds($list))->toBe($audience);
    })->with([
        'nothing' => [[]],
        'consent' => [['consent' => 'yes']],
        'no consent' => [['consent' => 'no']],
        'cart' => [['cart' => 'yes']],
        'no cart' => [['cart' => 'no']],
        'never' => [['contact' => 'never']],
        'not for 24h' => [['contact' => '24h']],
        'search' => [['search' => 'Customer 3']],
        'status + cart' => [['status' => 'active', 'cart' => 'no']],
        'everything' => [['consent' => 'yes', 'cart' => 'no', 'contact' => '24h', 'status' => 'active']],
    ]);
});

describe('last message column', function () {
    it('says Never, or how long ago, with the exact time to hover', function () {
        $never = clCustomer(['name' => 'Zed Never']);
        $recent = clCustomer(['name' => 'Ann Recent']);
        clSent($recent, now()->subDays(3));

        $page = Livewire::test(CustomerIndex::class);
        $page->assertSee(__('messaging_customers.col_last_message'))->assertSee(__('messaging_customers.never'))->assertSee('3 days ago')
            ->assertSeeHtml('title="'.BusinessTime::display(now()->subDays(3)->startOfSecond()).'"')->assertSeeHtml('data-testid="last-message"');
        $rows = $page->viewData('customers')->keyBy('name');
        expect($rows['Zed Never']->last_contact_at)->toBeNull()->and($rows['Ann Recent']->last_contact_at)->not->toBeNull();
    });

    it('counts an automatic journey message too, and shows the newest', function () {
        $u = clCustomer();
        clSent($u, now()->subDays(10));
        $j = Journey::create(['key' => 'abandoned_cart', 'name' => 'x', 'is_enabled' => true]);
        $run = JourneyRun::create(['journey_id' => $j->id, 'user_id' => $u->id, 'anchor_at' => now()->subHour()]);
        JourneyDelivery::create(['run_id' => $run->id, 'user_id' => $u->id, 'channel' => 'push', 'locale' => 'en', 'status' => 'sent', 'sent_at' => now()->subHours(5)]);

        Livewire::test(CustomerIndex::class)->assertSee('5 hours ago');
    });

    it('ignores messages that never went out', function () {
        $u = clCustomer();
        $batch = MessageBatch::create(['created_by' => $this->staff->id, 'template_name' => 'x', 'kind' => 'custom', 'source' => 'single', 'channels' => ['notification'], 'content' => [], 'audience' => [],
            'recipients_count' => 1, 'status' => 'scheduled']);
        CustomerMessage::create(['batch_id' => $batch->id, 'user_id' => $u->id, 'channel' => 'notification', 'locale' => 'en', 'status' => 'queued']);

        expect(Livewire::test(CustomerIndex::class)->viewData('customers')->first()->last_contact_at)->toBeNull();
    });

    it('is on both the table and the phone cards', function () {
        $u = clCustomer();
        clSent($u, now()->subHours(2));

        $html = Livewire::test(CustomerIndex::class)->html();
        expect(substr_count($html, '2 hours ago'))->toBe(2);
    });
});

describe('ticking customers', function () {
    it('hands the ticked customers to the message window', function () {
        $a = clCustomer();
        $b = clCustomer();
        clCustomer();

        Livewire::test(CustomerIndex::class)->set('selected', [$a->id, $b->id])->call('composeSelected')
            ->assertDispatched('compose-message', mode: 'ids', ids: [$a->id, $b->id], filters: [], except: [], source: 'selection');
    });

    it('hands "everyone matching" with the filters and the customers un-ticked since', function () {
        $left = clCustomer();
        clCustomer();
        clCustomer(['marketing_consent_at' => null]);

        Livewire::test(CustomerIndex::class)->set('filterConsent', 'yes')->set('search', 'a')->set('allMatching', true)->set('except', [$left->id])->call('composeSelected')
            ->assertDispatched('compose-message', mode: 'all', ids: [], filters: ['search' => 'a', 'consent' => 'yes'], except: [$left->id], source: 'selection');
    });

    it('says so when nothing is ticked, or everything was un-ticked again', function () {
        $a = clCustomer();

        Livewire::test(CustomerIndex::class)->call('composeSelected')->assertNotDispatched('compose-message')->assertDispatched('toast', type: 'error');
        Livewire::test(CustomerIndex::class)->set('selected', ['junk', 12])->call('composeSelected')->assertNotDispatched('compose-message')->assertDispatched('toast', type: 'error');
        Livewire::test(CustomerIndex::class)->set('allMatching', true)->set('except', [$a->id])->call('composeSelected')->assertNotDispatched('compose-message')->assertDispatched('toast', type: 'error');
    });

    it('knows how many customers match and which are on the page', function () {
        collect(range(1, 25))->each(fn () => clCustomer());

        $page = Livewire::test(CustomerIndex::class)->assertSet('matching', 25);
        expect($page->get('pageIds'))->toHaveCount(20)->and($page->get('pageIds'))->toEqual(clIds($page) === [] ? [] : $page->viewData('customers')->pluck('id')->map(fn ($i) => (string) $i)->all());
        $page->set('filterConsent', 'yes')->assertSet('matching', 25)->set('search', 'zzzz-nobody')->assertSet('matching', 0)->assertSet('pageIds', []);
    });

    it('keeps the ticks across pages of the same filters', function () {
        collect(range(1, 25))->each(fn () => clCustomer());
        $first = Livewire::test(CustomerIndex::class)->get('pageIds')[0];

        $page = Livewire::test(CustomerIndex::class)->set('selected', [$first])->set('allMatching', true)->set('except', [$first]);
        $page->call('gotoPage', 2)->assertSet('selected', [$first])->assertSet('allMatching', true)->assertSet('except', [$first])->assertSet('pageIds', fn ($ids) => count($ids) === 5);
        $page->call('previousPage')->assertSet('selected', [$first]);
    });

    it('starts again when the search or a filter changes', function () {
        $a = clCustomer();

        foreach ([['search', 'x'], ['filterStatus', 'active'], ['filterConsent', 'yes'], ['filterCart', 'no'], ['filterContact', 'never']] as [$prop, $value]) {
            Livewire::test(CustomerIndex::class)->set('selected', [$a->id])->set('allMatching', true)->set('except', [$a->id])->set($prop, $value)
                ->assertSet('selected', [])->assertSet('allMatching', false)->assertSet('except', []);
        }
        Livewire::test(CustomerIndex::class)->set('selected', [$a->id])->call('clearSelection')->assertSet('selected', []);
    });

    it('clears the ticks after a send that came from them, and only then', function () {
        $a = clCustomer();

        Livewire::test(CustomerIndex::class)->set('selected', [$a->id])->dispatch('messages-sent', batch: 'b1', source: 'selection')->assertSet('selected', []);
        foreach (['row', 'customer', ''] as $source) {
            Livewire::test(CustomerIndex::class)->set('selected', [$a->id])->dispatch('messages-sent', batch: 'b1', source: $source)->assertSet('selected', [$a->id]);
        }
    });

    it('shows the largest send that is allowed', function () {
        app(Settings::class)->set('messaging.bulk_max', 7);

        Livewire::test(CustomerIndex::class)->assertViewHas('bulkMax', 7)->assertSeeHtml('&quot;cap&quot;:7');
    });
});

describe('sending from a row or a customer', function () {
    it('opens the window for that one customer from a row', function () {
        $c = clCustomer();

        Livewire::test(CustomerIndex::class)->call('composeFor', $c->id)->assertDispatched('compose-message', mode: 'ids', ids: [$c->id], filters: [], except: [], source: 'row');
    });

    it('opens nothing for a customer outside the staff member’s branches, or for a made-up id', function () {
        $c = clCustomer();
        $branch = Branch::factory()->create();
        $scoped = mcStaff(['customers.view', 'notifications.create'], $branch);

        $this->actingAs($scoped);
        Livewire::test(CustomerIndex::class)->call('composeFor', $c->id)->assertNotDispatched('compose-message')->assertDispatched('toast', type: 'error');
        Livewire::test(CustomerIndex::class)->call('composeFor', 'not-a-uuid')->assertNotDispatched('compose-message');
        Livewire::test(CustomerIndex::class)->call('composeFor', (string) Str::uuid())->assertNotDispatched('compose-message');
    });

    it('opens the window from the customer page and from the customer window', function () {
        $c = clCustomer();

        foreach ([false, true] as $drawer) {
            Livewire::test(CustomerShow::class, ['customer' => $c, 'drawer' => $drawer])->call('composeMessage')
                ->assertDispatched('compose-message', mode: 'ids', ids: [$c->id], filters: [], except: [], source: 'customer');
        }
    });

    it('has exactly one message window on a page, below the customer window on the list', function () {
        $c = clCustomer();

        Livewire::test(CustomerIndex::class)->assertSeeLivewire(MessageComposer::class);
        Livewire::withQueryParams(['view' => $c->id])->test(CustomerIndex::class)->assertSeeLivewire(CustomerShow::class)->assertSeeLivewire(MessageComposer::class);
        expect(substr_count(Livewire::withQueryParams(['view' => $c->id])->test(CustomerIndex::class)->html(), 'wire:name="admin.messaging.message-composer"'))->toBe(1);
        Livewire::test(CustomerShow::class, ['customer' => $c, 'drawer' => true])->assertDontSeeLivewire(MessageComposer::class);
        Livewire::test(CustomerShow::class, ['customer' => $c])->assertSeeLivewire(MessageComposer::class);
    });

    it('keeps the window away from staff who may not send', function () {
        $this->actingAs(staffWithRole('customer_support'));

        Livewire::test(CustomerIndex::class)->assertDontSeeLivewire(MessageComposer::class);
    });
});
