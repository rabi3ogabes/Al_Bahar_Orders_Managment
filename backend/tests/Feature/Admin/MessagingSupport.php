<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRole;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Orders\Models\Cart;
use App\Domain\Orders\Models\CartItem;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Messaging\MessageComposer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

// Fixtures shared by the message window and the customers list tests (require_once keeps them defined once per run).

function mcCustomer(array $attrs = []): User
{
    return User::factory()->create($attrs + ['marketing_consent_at' => now(), 'locale' => 'en', 'name' => 'Noor Ali']);
}

function mcWhatsApp(User $u): void
{
    NotificationPreference::create(['user_id' => $u->id, 'channel' => 'whatsapp', 'category' => 'marketing', 'is_enabled' => true]);
}

function mcCart(User $u): Cart
{
    $variant = Product::factory()->create()->variants()->first();
    $cart = Cart::create(['user_id' => $u->id, 'status' => 'active', 'last_activity_at' => now()->subHour()]);
    CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'quantity' => 2]);

    return $cart;
}

function mcConnectN8n(): void
{
    $s = app(Settings::class);
    $s->set('n8n.enabled', true);
    $s->set('n8n.webhook_url', 'https://n8n.example.com/webhook/bahar');
    $s->set('n8n.secret', 'a-very-long-shared-secret-123', secret: true);
    Http::fake(['https://n8n.example.com/*' => Http::response(['id' => 'wamid.1'])]);
}

/** @return array<string, mixed> */
function mcContent(array $over = []): array
{
    return $over + ['title_en' => 'Hello {{name}}', 'title_ar' => 'مرحباً {{name}}', 'body_en' => 'A note for {{name}}', 'body_ar' => 'رسالة إلى {{name}}',
        'whatsapp_en' => '', 'whatsapp_ar' => '', 'deep_link' => 'albahar://home', 'coupon' => ['enabled' => false, 'promotion_id' => '', 'prefix' => 'BAHAR', 'valid_hours' => 72]];
}

/** A staff member holding exactly these permissions, company-wide or limited to one branch. */
function mcStaff(array $keys, ?Branch $branch = null): User
{
    $user = User::factory()->staff()->create();
    $role = Role::create(['key' => 'mc_'.Str::random(6), 'name_en' => 'Composer tester', 'name_ar' => 'مختبر', 'scope' => $branch ? 'branch' : 'global']);
    $role->permissions()->sync(Permission::whereIn('key', $keys)->pluck('id'));
    if ($branch) {
        BranchEmployee::create(['branch_id' => $branch->id, 'user_id' => $user->id, 'is_active' => true]);
    }
    UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'branch_id' => $branch?->id]);

    return $user;
}

function mcOffer(): Promotion
{
    return Promotion::create(['name_en' => 'Ten off', 'name_ar' => 'عشرة', 'type' => 'percentage', 'value' => '10', 'scope' => 'order', 'requires_coupon' => true, 'is_active' => true]);
}

/** @return list<string> every key of a language file, nested ones as a.b.c */
function mcKeys(array $lines, string $prefix = ''): array
{
    $out = [];
    foreach ($lines as $key => $value) {
        array_push($out, ...(is_array($value) ? mcKeys($value, $prefix.$key.'.') : [$prefix.$key]));
    }
    sort($out);

    return $out;
}

/** The composer, opened for these customers with a message already chosen. */
function mcOpen(array $ids, string $choose = 'custom'): Testable
{
    $c = Livewire::test(MessageComposer::class)->dispatch('compose-message', mode: 'ids', ids: $ids);
    if ($choose !== '') {
        $c->call('choose', $choose)->set('content', mcContent());
    }

    return $c;
}
