<?php

namespace App\Support\Scale;

use App\Domain\Identity\Models\User;
use App\Domain\Settings\Services\Settings;
use Illuminate\Support\Facades\DB;

/**
 * The requests the plan test replays (docs/guides/SCALE_PROOF.md): the customer API, the staff and driver API and the dashboard's busiest screens.
 * A scenario is signed in as a real person of the scale database: an ordinary customer, a branch manager, a driver, or the owner (super admin).
 */
class ScaleScenarios
{
    /** @return list<array{name: string, as: string, method: string, uri: string, params?: array<string, mixed>, budget?: int, user: ?User}> */
    public static function all(): array
    {
        $owner = User::where('email', 'scale.owner@albahar.test')->first() ?? tap(new User(['type' => 'staff', 'name' => 'Scale Owner', 'email' => 'scale.owner@albahar.test', 'password' => 'scale-not-a-real-password-1A!', 'status' => 'active']), function (User $u): void {
            $u->is_super_admin = true;
            $u->save();
        });
        // The scale database is throw-away: the owner opens the dashboard without the second-factor enrolment step.
        app(Settings::class)->set('auth.staff_two_factor_required', false);
        $customer = User::where('phone', '+97450000008')->firstOrFail();
        $manager = User::where('email', 'manager.westbay@albahar.qa')->firstOrFail();
        $driver = User::where('email', 'like', 'scale.driver.wb.1@%')->firstOrFail();
        $branchId = (string) DB::table('branches')->orderBy('code')->value('id');
        $orderId = (string) DB::table('orders')->where('user_id', $customer->id)->orderByDesc('placed_at')->value('id');
        // An order the West Bay manager may open (one of its parts is at West Bay), for the staff detail scenario.
        $branchOrderId = (string) DB::table('order_fulfillments')->where('branch_id', DB::table('branches')->where('code', 'WB')->value('id'))->orderByDesc('created_at')->value('order_id');
        $productId = (string) DB::table('products')->where('status', 'active')->where('sku', 'like', 'SC-%')->orderByDesc('sales_count')->orderBy('id')->value('id');
        $slug = (string) DB::table('product_translations')->where('product_id', $productId)->where('locale', 'en')->value('slug');
        $variantId = (string) DB::table('product_variants')->where('product_id', $productId)->orderBy('sku')->value('id');
        $categoryId = (string) DB::table('products')->where('id', $productId)->value('category_id');
        $orderNumber = (string) DB::table('orders')->where('id', $orderId)->value('number');
        $month = ['from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString()];
        $year = ['from' => now()->subDays(365)->toDateString(), 'to' => now()->toDateString()];

        $c = fn (string $name, string $uri, array $params = [], string $method = 'GET', int $budget = 50): array => compact('name', 'method', 'uri', 'params', 'budget') + ['as' => 'customer', 'user' => $customer];
        $g = fn (string $name, string $uri, array $params = [], int $budget = 50): array => ['name' => $name, 'as' => 'guest', 'method' => 'GET', 'uri' => $uri, 'params' => $params, 'budget' => $budget, 'user' => null];
        $s = fn (string $name, string $uri, array $params = [], int $budget = 50, ?User $as = null): array => ['name' => $name, 'as' => 'staff', 'method' => 'GET', 'uri' => $uri, 'params' => $params, 'budget' => $budget, 'user' => $as ?? $manager];
        $w = fn (string $name, string $uri, array $params = [], int $budget = 50): array => ['name' => $name, 'as' => 'web', 'method' => 'GET', 'uri' => $uri, 'params' => $params, 'budget' => $budget, 'user' => $owner];

        return [
            // ── Customer API (signed-out visitors and a signed-in customer) ──
            $g('api home', '/api/v1/home'),
            $g('api categories', '/api/v1/categories'),
            $g('api catalogue list', '/api/v1/products', ['per_page' => 24]),
            $g('api catalogue list, page 40', '/api/v1/products', ['per_page' => 24, 'page' => 40]),
            $g('api catalogue by category, sorted by price', '/api/v1/products', ['category_id' => $categoryId, 'sort' => 'price']),
            $g('api catalogue, in stock and on sale', '/api/v1/products', ['in_stock' => 1, 'on_sale' => 1, 'branch_id' => $branchId]),
            $g('api search (English)', '/api/v1/products/search', ['q' => 'sofa'], 100),
            $g('api search (Arabic)', '/api/v1/products/search', ['q' => 'كنبة'], 100),
            $g('api search suggestions', '/api/v1/search/suggest', ['q' => 'oslo'], 100),
            $g('api product page', "/api/v1/products/{$slug}"),
            $g('api product availability', "/api/v1/products/{$productId}/availability"),
            $g('api product delivery promise', "/api/v1/products/{$productId}/delivery-promise"),
            $g('api branch products', "/api/v1/branches/{$branchId}/products"),
            $g('api product reviews', "/api/v1/products/{$productId}/reviews"),
            $g('api deals', '/api/v1/deals'),
            $g('api branches', '/api/v1/branches'),
            $c('api my orders', '/api/v1/orders'),
            $c('api my orders, filtered', '/api/v1/orders', ['status' => 'completed,returned']),
            $c('api my active progress', '/api/v1/orders/active-progress'),
            $c('api order detail', "/api/v1/orders/{$orderId}"),
            $c('api order timeline', "/api/v1/orders/{$orderId}/timeline"),
            $c('api order tracking', "/api/v1/orders/{$orderId}/tracking"),
            $c('api my notifications', '/api/v1/me/notifications'),
            $c('api my addresses', '/api/v1/me/addresses'),
            $c('api my loyalty', '/api/v1/me/loyalty'),
            $c('api buy again', '/api/v1/me/buy-again'),
            $c('api my cart', '/api/v1/cart'),
            $c('api add to cart', '/api/v1/cart/items', ['variant_id' => $variantId, 'quantity' => 1], 'POST'),
            $c('api checkout quote', '/api/v1/checkout/quote', ['fulfillment_type' => 'pickup', 'branch_id' => $branchId, 'payment_method' => 'cod'], 'POST', 100),
            // ── Staff and driver API ──
            $s('staff driver jobs', '/api/v1/admin/dispatch/driver/jobs', [], 50, $driver),
            $s('staff driver day', '/api/v1/admin/dispatch/driver/day', [], 50, $driver),
            $s('staff driver history', '/api/v1/admin/dispatch/driver/history', [], 50, $driver),
            $s('staff my deliveries', '/api/v1/admin/me/deliveries', [], 50, $driver),
            $s('staff dispatch overview', '/api/v1/admin/dispatch/overview'),
            $s('staff dispatch queue', '/api/v1/admin/dispatch/queue'),
            $s('staff dispatch drivers', '/api/v1/admin/dispatch/drivers'),
            $s('staff needs attention', '/api/v1/admin/dispatch/attention'),
            $s('staff order list', '/api/v1/admin/orders'),
            $s('staff order list, filtered', '/api/v1/admin/orders', ['status' => 'confirmed']),
            $s('staff order detail', "/api/v1/admin/orders/{$branchOrderId}"),
            $s('staff stock list', '/api/v1/admin/inventory/stock'),
            $s('staff stock movements', '/api/v1/admin/inventory/movements'),
            $s('staff cash outstanding', '/api/v1/admin/cash/outstanding'),
            // ── Dashboard ──
            $w('dashboard home', '/admin'),
            $w('dashboard orders', '/admin/orders'),
            $w('dashboard orders, page 3', '/admin/orders', ['page' => 3]),
            $w('dashboard orders, filtered by status', '/admin/orders', ['status' => 'confirmed']),
            $w('dashboard orders, search by number', '/admin/orders', ['search' => $orderNumber], 100),
            $w('dashboard order detail', "/admin/orders/{$orderId}"),
            $w('dashboard live board', '/admin/operations/board'),
            $w('dashboard needs attention', '/admin/operations/attention'),
            $w('dashboard daily close', '/admin/daily-close'),
            $w('dashboard scorecard', '/admin/scorecard'),
            $w('dashboard owner pack', '/admin/owner-pack'),
            $w('dashboard customer value', '/admin/customers/value'),
            $w('dashboard customers', '/admin/customers'),
            $w('dashboard deliveries', '/admin/deliveries'),
            $w('dashboard returns', '/admin/returns'),
            $w('dashboard payments', '/admin/payments'),
            $w('dashboard refunds', '/admin/refunds'),
            $w('dashboard inventory', '/admin/inventory'),
            $w('dashboard stock movements', '/admin/inventory/movements'),
            $w('dashboard products', '/admin/products'),
            $w('dashboard audit log', '/admin/audit'),
            $w('dashboard reports page', '/admin/reports'),
            $w('dashboard global search (number)', '/admin/search', ['q' => $orderNumber], 100),
            $w('dashboard global search (name)', '/admin/search', ['q' => 'Customer 77'], 100),
            $w('dashboard global search (phone)', '/admin/search', ['q' => '50001234'], 100),
            $w('dashboard global search (product)', '/admin/search', ['q' => 'sofa'], 100),
            ...array_map(fn (string $r): array => $s("report {$r}, 30 days", "/api/v1/admin/reports/{$r}", $month, 100, $owner), ['sales_summary', 'sales_by_branch', 'sales_by_product', 'sales_by_category', 'sales_by_payment_method', 'sales_by_fulfillment', 'order_status', 'customers', 'deliveries', 'finance_collections', 'finance_refunds', 'finance_outstanding', 'inventory_levels', 'fast_slow_movers']),
            $s('report sales_summary, 365 days', '/api/v1/admin/reports/sales_summary', $year, 100, $owner),
            $s('report sales_by_product, 365 days', '/api/v1/admin/reports/sales_by_product', $year, 100, $owner),
        ];
    }
}
