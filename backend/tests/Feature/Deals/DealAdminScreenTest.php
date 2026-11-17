<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\DealProduct;
use App\Domain\Deals\Services\DealService;
use App\Domain\Deals\Support\DealDesign;
use App\Livewire\Admin\Marketing\DealIndex;
use App\Support\DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

require_once __DIR__.'/DealFixtures.php';

beforeEach(function () {
    $this->w = commerceWorld();
    $this->admin = dealAdmin();
});

/** The editor as a marketing manager fills it for a simple 30 % deal on the world's sofa. */
function fillDealEditor($c, array $form = []): mixed
{
    $c->call('create')->call('addProduct', test()->w->p1->id)
        ->set('form.name_en', $form['name_en'] ?? 'Weekend Drop')->set('form.name_ar', $form['name_ar'] ?? 'عرض نهاية الأسبوع')
        ->set('form.ends_at', $form['ends_at'] ?? now('Asia/Qatar')->addDay()->format('Y-m-d\TH:i'))
        ->set('products.0.quantity_total', '10')->set('form.limit_per_order', '2');
    foreach (array_diff_key($form, array_flip(['name_en', 'name_ar', 'ends_at'])) as $k => $v) {
        $c->set('form.'.$k, $v);
    }

    return $c;
}

it('opens for marketing staff, shows nothing to others and puts Deals in the Marketing menu', function () {
    $this->actingAs($this->admin)->get(route('admin.deals.index'))->assertOk()->assertSee('Deals')->assertSee(route('admin.deals.index'), false);
    $this->actingAs(staffWithRole('pickup_employee', null))->get(route('admin.deals.index'))->assertForbidden();
    Livewire::actingAs(staffWithRole('pickup_employee', null))->test(DealIndex::class)->assertForbidden();
});

it('creates a deal from the side window with its product, quantity and limits, and writes an audit entry', function () {
    $c = Livewire::actingAs($this->admin)->test(DealIndex::class)->assertSet('showEditor', false);
    fillDealEditor($c)->assertViewHas('editor', fn ($e) => $e['dirty'] === true)->call('save')->assertHasNoErrors()->assertSet('showEditor', false);

    $deal = Deal::firstOrFail();
    $row = $deal->products()->first();
    expect($deal->name_en)->toBe('Weekend Drop')->and($deal->is_active)->toBeTrue()->and($deal->limit_per_order)->toBe(2)->and($deal->discount_type)->toBe('percent')
        ->and((string) $deal->discount_value)->toBe('30.00')->and($row->product_id)->toBe($this->w->p1->id)->and($row->quantity_total)->toBe(10)
        ->and($deal->design['palette'])->toBe('brand')->and($deal->created_by)->toBe($this->admin->id);
    $log = AuditLog::where('action', 'deals.created')->firstOrFail();
    expect($log->auditable_id)->toBe($deal->id)->and($log->actor_id)->toBe($this->admin->id)->and($log->metadata['products'])->toBe([$this->w->p1->id]);
});

it('keeps Save off until something changed, and shows the customer-pays example from the first product', function () {
    $c = Livewire::actingAs($this->admin)->test(DealIndex::class)->call('create')->assertViewHas('editor', fn ($e) => $e['dirty'] === false);
    $c->call('addProduct', $this->w->p1->id)->assertViewHas('editor', fn ($e) => $e['dirty'] === true
        && $e['example']['normal']->toDecimal() === '1000.00' && $e['example']['deal']->toDecimal() === '700.00' && $e['example']['percent'] === 30);
    $c->set('form.discount_type', 'amount_off')->set('form.discount_value', '150')->assertViewHas('editor', fn ($e) => $e['example']['deal']->toDecimal() === '850.00');
    $c->set('form.discount_type', 'fixed_price')->assertViewHas('editor', fn ($e) => $e['example']['deal'] === null);
    $c->set('products.0.deal_price', '640')->assertViewHas('editor', fn ($e) => $e['example']['deal']->toDecimal() === '640.00');
});

it('answers every mistake in plain words next to the field', function () {
    $c = Livewire::actingAs($this->admin)->test(DealIndex::class)->call('create')->call('save')
        ->assertHasErrors(['form.name_en', 'form.name_ar', 'form.ends_at', 'products']);
    // A fixed price deal needs a price on every product; a quantity cannot be below what is sold.
    fillDealEditor($c->call('create'), ['discount_type' => 'fixed_price'])->call('save')->assertHasErrors(['products.0.deal_price']);
    // The end time must be after the start.
    fillDealEditor($c->call('create'), ['starts_at' => now('Asia/Qatar')->addDays(3)->format('Y-m-d\TH:i'), 'ends_at' => now('Asia/Qatar')->addDay()->format('Y-m-d\TH:i')])
        ->call('save')->assertHasErrors(['form.ends_at']);
    expect(Deal::count())->toBe(0);
});

it('refuses colours that cannot be read and warns about weak ones', function () {
    $c = fillDealEditor(Livewire::actingAs($this->admin)->test(DealIndex::class))
        ->set('design.palette', 'custom')->set('design.colors.background', '#FFFFFF')->set('design.colors.text', '#FAFAFA')->set('design.colors.accent', '#000000')
        ->assertViewHas('editor', fn ($e) => $e['warning'] !== null);
    $c->call('save')->assertHasErrors(['design.colors.text']);
    $c->set('design.colors.text', '#111111')->call('save')->assertHasNoErrors();
    expect(Deal::first()->design['palette'])->toBe('custom')->and(Deal::first()->design['colors']['text'])->toBe('#111111');
});

it('names the other deal when a product is already in a running deal', function () {
    makeDeal(['name_en' => 'Ramadan Majlis'], [$this->w->p1->id => 5]);

    $c = fillDealEditor(Livewire::actingAs($this->admin)->test(DealIndex::class))->call('save');

    $c->assertHasErrors(['products']);
    expect(collect($c->errors()->get('products'))->first())->toContain('Ramadan Majlis')->toContain('already in the deal');
    expect(Deal::count())->toBe(1);
    // A switched-off draft with the same product is fine, until it is switched on.
    $c->set('form.is_active', false)->call('save')->assertHasNoErrors();
    expect(Deal::count())->toBe(2);
    $draft = Deal::where('name_en', 'Weekend Drop')->first();
    Livewire::actingAs($this->admin)->test(DealIndex::class)->call('toggle', $draft->id);
    expect($draft->fresh()->is_active)->toBeFalse();
});

it('pauses and resumes at once and writes the audit entries', function () {
    $deal = makeDeal();
    $c = Livewire::actingAs($this->admin)->test(DealIndex::class);

    $c->call('toggle', $deal->id);
    expect($deal->fresh()->is_active)->toBeFalse()->and($deal->fresh()->phase())->toBe('paused');
    $c->call('toggle', $deal->id);
    expect($deal->fresh()->is_active)->toBeTrue()->and($deal->fresh()->phase())->toBe('live');
    expect(AuditLog::whereIn('action', ['deals.paused', 'deals.resumed'])->orderBy('created_at')->pluck('action')->all())->toBe(['deals.paused', 'deals.resumed']);
});

it('ends a deal only after the confirmation, and an ended deal cannot be switched on again', function () {
    $deal = makeDeal();
    $c = Livewire::actingAs($this->admin)->test(DealIndex::class)->call('askEnd', $deal->id)->assertSet('showEnd', true);
    expect($deal->fresh()->ended_at)->toBeNull();

    $c->call('endNow')->assertSet('showEnd', false);
    expect($deal->fresh()->ended_at)->not->toBeNull()->and($deal->fresh()->phase())->toBe('ended')->and(AuditLog::where('action', 'deals.ended')->count())->toBe(1);

    $c->call('toggle', $deal->id);
    expect($deal->fresh()->phase())->toBe('ended');
    $c->call('editDeal', $deal->id)->call('save')->assertHasErrors(['form.ends_at']);
});

it('duplicates a deal as a switched-off copy with nothing sold', function () {
    $deal = makeDeal(['limit_per_customer' => 3]);
    DealProduct::first()->forceFill(['quantity_sold' => 4])->save();

    Livewire::actingAs($this->admin)->test(DealIndex::class)->call('duplicate', $deal->id);

    $copy = Deal::where('id', '!=', $deal->id)->firstOrFail();
    expect($copy->is_active)->toBeFalse()->and($copy->name_en)->toBe('Weekend Furniture Drop (copy)')->and($copy->limit_per_customer)->toBe(3)
        ->and($copy->products()->first()->quantity_sold)->toBe(0)->and($copy->products()->first()->quantity_total)->toBe(10)
        ->and(AuditLog::where('action', 'deals.created')->where('metadata->duplicate_of', $deal->id)->count())->toBe(1);
});

it('deletes a deal nobody ordered from, and refuses one with orders', function () {
    $free = makeDeal(['name_en' => 'Free'], [$this->w->p2->id => 3]);
    $c = Livewire::actingAs($this->admin)->test(DealIndex::class)->call('askDelete', $free->id)->assertSet('showDelete', true)->call('deleteDeal');
    expect(Deal::find($free->id))->toBeNull()->and(AuditLog::where('action', 'deals.deleted')->count())->toBe(1);

    $sold = makeDeal(['name_en' => 'Sold'], [$this->w->p1->id => 5]);
    actingAsCustomer($this->w->customer);
    addToCart($this->w->v1->id, 1);
    placeDealOrder()->assertCreated();
    $c = Livewire::actingAs($this->admin)->test(DealIndex::class);
    $c->call('askDelete', $sold->id)->call('deleteDeal');
    expect(Deal::find($sold->id))->not->toBeNull();
    expect(fn () => app(DealService::class)->delete($this->admin, $sold))->toThrow(DomainException::class);
});

it('lets a read-only role look but never change', function () {
    $deal = makeDeal();
    $auditor = staffWithRole('auditor');

    $c = Livewire::actingAs($auditor)->test(DealIndex::class)->assertOk()->assertSee('Weekend Furniture Drop');
    $c->call('toggle', $deal->id)->assertForbidden();
    Livewire::actingAs($auditor)->test(DealIndex::class)->call('create')->assertForbidden();
    Livewire::actingAs($auditor)->test(DealIndex::class)->call('askEnd', $deal->id)->assertForbidden();
    Livewire::actingAs($auditor)->test(DealIndex::class)->call('duplicate', $deal->id)->assertForbidden();
    Livewire::actingAs($auditor)->test(DealIndex::class)->call('askDelete', $deal->id)->assertForbidden();
    expect($deal->fresh()->is_active)->toBeTrue();
    expect(fn () => app(DealService::class)->setActive($auditor, $deal, false))->toThrow(AuthorizationException::class);
    expect(fn () => app(DealService::class)->save($auditor, null, []))->toThrow(AuthorizationException::class);
});

it('filters the list by phase and keeps the filter in the address', function () {
    makeDeal(['name_en' => 'Running']);
    makeDeal(['name_en' => 'Later', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(3)], [$this->w->p2->id => 4]);
    $p3 = dealProduct('80.00');
    makeDeal(['name_en' => 'Draft', 'is_active' => false], [$p3->id => 4]);

    $c = Livewire::actingAs($this->admin)->test(DealIndex::class);
    $c->assertSee('Running')->assertSee('Later')->assertSee('Draft');
    $c->set('filterState', 'live')->assertSee('Running')->assertDontSee('Later')->assertDontSee('Draft');
    $c->set('filterState', 'scheduled')->assertSee('Later')->assertDontSee('Running');
    $c->set('filterState', 'paused')->assertSee('Draft')->assertDontSee('Later');
    $c->set('filterState', 'ended')->assertDontSee('Running')->assertSee(__('deals_admin.empty'));
});

it('opens the editor from the address (?edit=) and renders the live widget with every style', function () {
    $deal = makeDeal();
    $this->actingAs($this->admin)->get(route('admin.deals.index', ['edit' => $deal->id]))->assertOk()->assertSee('Weekend Furniture Drop')->assertSee('role="timer"', false);

    $c = Livewire::actingAs($this->admin)->withQueryParams(['edit' => $deal->id])->test(DealIndex::class)->assertSet('showEditor', true)->assertSet('editingId', $deal->id);
    foreach (DealDesign::STYLES as $style) {
        foreach (['boxes', 'digits', 'text'] as $look) {
            $c->set('design.style', $style)->set('design.timer.look', $look)->assertOk()->assertSee('role="timer"', false);
        }
    }
    $c->set('design.palette', 'night')->assertSee('#0F172A', false)->set('design.timer.units', ['h', 'm'])->assertOk();
});
