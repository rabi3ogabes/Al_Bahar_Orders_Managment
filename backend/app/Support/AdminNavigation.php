<?php

namespace App\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessControl;
use Illuminate\Support\Facades\Route;

/**
 * Role-aware admin navigation. Visibility is a UX convenience only — every route and action
 * is independently authorized server-side.
 */
final class AdminNavigation
{
    /** @return list<array{label: string, items: list<array>}> */
    public static function for(User $user): array
    {
        $acl = app(AccessControl::class);
        $groups = [
            ['label' => 'nav.overview', 'items' => [
                ['label' => 'nav.dashboard', 'route' => 'admin.dashboard', 'icon' => 'dashboard', 'permission' => 'dashboard.view'],
            ]],
            ['label' => 'nav.sales', 'items' => [
                ['label' => 'nav.orders', 'route' => 'admin.orders.index', 'icon' => 'bag', 'permission' => 'orders.view'],
                ['label' => 'nav.pickup', 'route' => 'admin.pickup.index', 'icon' => 'qr', 'permission' => 'pickup.view'],
                ['label' => 'nav.deliveries', 'route' => 'admin.deliveries.index', 'icon' => 'truck', 'permission' => 'deliveries.view'],
                ['label' => 'nav.customers', 'route' => 'admin.customers.index', 'icon' => 'users', 'permission' => 'customers.view'],
                ['label' => 'nav.returns', 'route' => 'admin.returns.index', 'icon' => 'refund', 'permission' => 'orders.view'],
            ]],
            ['label' => 'nav.catalog', 'items' => [
                ['label' => 'nav.products', 'route' => 'admin.products.index', 'icon' => 'sofa', 'permission' => 'catalog.view'],
                ['label' => 'nav.categories', 'route' => 'admin.categories.index', 'icon' => 'layers', 'permission' => 'catalog.view'],
                ['label' => 'nav.brands', 'route' => 'admin.brands.index', 'icon' => 'crown', 'permission' => 'catalog.view'],
                ['label' => 'nav.attributes', 'route' => 'admin.attributes.index', 'icon' => 'tag', 'permission' => 'catalog.view'],
                ['label' => 'nav.imports', 'route' => 'admin.imports.index', 'icon' => 'upload', 'permission' => 'catalog.create'],
            ]],
            ['label' => 'nav.inventory', 'items' => [
                ['label' => 'nav.stock', 'route' => 'admin.inventory.index', 'icon' => 'boxes', 'permission' => 'inventory.view'],
                ['label' => 'nav.movements', 'route' => 'admin.inventory.movements', 'icon' => 'history', 'permission' => 'inventory.view'],
                ['label' => 'nav.adjustments', 'route' => 'admin.adjustments.index', 'icon' => 'scale', 'permission' => 'inventory.create'],
                ['label' => 'nav.transfers', 'route' => 'admin.transfers.index', 'icon' => 'transfer', 'permission' => 'transfers.view'],
                ['label' => 'nav.stocktaking', 'route' => 'admin.stocktaking.index', 'icon' => 'clipboard', 'permission' => 'stocktaking.view'],
                ['label' => 'nav.purchase_orders', 'route' => 'admin.purchase-orders.index', 'icon' => 'receipt', 'permission' => 'purchasing.view'],
                ['label' => 'nav.suppliers', 'route' => 'admin.suppliers.index', 'icon' => 'building', 'permission' => 'suppliers.view'],
            ]],
            ['label' => 'nav.finance', 'items' => [
                ['label' => 'nav.payments', 'route' => 'admin.payments.index', 'icon' => 'credit-card', 'permission' => 'payments.view'],
                ['label' => 'nav.cash', 'route' => 'admin.cash.index', 'icon' => 'cash', 'permission' => 'payments.view'],
                ['label' => 'nav.refunds', 'route' => 'admin.refunds.index', 'icon' => 'refund', 'permission' => 'refunds.view'],
                ['label' => 'nav.reconciliation', 'route' => 'admin.reconciliation.index', 'icon' => 'scale', 'permission' => 'reconciliation.view'],
            ]],
            ['label' => 'nav.auctions', 'items' => [
                ['label' => 'nav.auctions_all', 'route' => 'admin.auctions.index', 'icon' => 'gavel', 'permission' => 'auctions.view'],
            ]],
            ['label' => 'nav.marketing', 'items' => [
                ['label' => 'nav.promotions', 'route' => 'admin.promotions.index', 'icon' => 'percent', 'permission' => 'marketing.view'],
                ['label' => 'nav.campaigns', 'route' => 'admin.campaigns.index', 'icon' => 'megaphone', 'permission' => 'marketing.view'],
                ['label' => 'nav.loyalty', 'route' => 'admin.loyalty.index', 'icon' => 'gift', 'permission' => 'loyalty.view'],
                ['label' => 'nav.reviews', 'route' => 'admin.reviews.index', 'icon' => 'star', 'permission' => 'reviews.view'],
                ['label' => 'nav.support', 'route' => 'admin.support.index', 'icon' => 'life-buoy', 'permission' => 'support.view'],
            ]],
            ['label' => 'nav.content', 'items' => [
                ['label' => 'nav.homepage', 'route' => 'admin.homepage.index', 'icon' => 'sparkles', 'permission' => 'cms.view'],
                ['label' => 'nav.banners', 'route' => 'admin.banners.index', 'icon' => 'image', 'permission' => 'cms.view'],
                ['label' => 'nav.pages', 'route' => 'admin.pages.index', 'icon' => 'file-text', 'permission' => 'cms.view'],
                ['label' => 'nav.notification_templates', 'route' => 'admin.notification-templates.index', 'icon' => 'bell', 'permission' => 'notifications.view'],
            ]],
            ['label' => 'nav.insights', 'items' => [
                ['label' => 'nav.reports', 'route' => 'admin.reports.index', 'icon' => 'chart', 'permission' => 'reports.view'],
                ['label' => 'nav.exports', 'route' => 'admin.exports.index', 'icon' => 'download', 'permission' => 'reports.export'],
                ['label' => 'nav.audit', 'route' => 'admin.audit.index', 'icon' => 'history', 'permission' => 'audit.view'],
            ]],
            ['label' => 'nav.organization', 'items' => [
                ['label' => 'nav.branches', 'route' => 'admin.branches.index', 'icon' => 'store', 'permission' => 'branches.view'],
                ['label' => 'nav.zones', 'route' => 'admin.zones.index', 'icon' => 'map', 'permission' => 'zones.view'],
                ['label' => 'nav.employees', 'route' => 'admin.employees.index', 'icon' => 'user', 'permission' => 'employees.view'],
                ['label' => 'nav.roles', 'route' => 'admin.roles.index', 'icon' => 'shield', 'permission' => 'roles.view'],
                ['label' => 'nav.delivery_providers', 'route' => 'admin.delivery-providers.index', 'icon' => 'truck', 'permission' => 'settings.view'],
                ['label' => 'nav.settings', 'route' => 'admin.settings.index', 'icon' => 'settings', 'permission' => 'settings.view'],
            ]],
        ];

        $out = [];
        foreach ($groups as $group) {
            $items = array_values(array_filter($group['items'], fn ($i) => Route::has($i['route']) && $acl->allows($user, $i['permission'])));
            if ($items) {
                $out[] = ['label' => $group['label'], 'items' => $items];
            }
        }

        return $out;
    }
}
