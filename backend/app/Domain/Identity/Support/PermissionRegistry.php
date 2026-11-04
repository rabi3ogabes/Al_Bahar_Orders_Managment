<?php

namespace App\Domain\Identity\Support;

/**
 * Single source of truth for action-based permissions and default role templates.
 * Permission keys are "{module}.{action}". Roles remain editable in the admin dashboard;
 * these defaults are applied by RolesAndPermissionsSeeder (idempotent, never removes custom grants).
 */
final class PermissionRegistry
{
    public const ACTIONS = ['view', 'create', 'edit', 'delete', 'approve', 'export', 'assign', 'refund', 'override'];

    /** module => [actions, name_en, name_ar] */
    public const MODULES = [
        'dashboard' => [['view'], 'Dashboard', 'لوحة التحكم'],
        'branches' => [['view', 'create', 'edit', 'delete'], 'Branches', 'الفروع'],
        'employees' => [['view', 'create', 'edit', 'delete', 'assign'], 'Employees', 'الموظفون'],
        'roles' => [['view', 'create', 'edit', 'delete', 'assign'], 'Roles & permissions', 'الأدوار والصلاحيات'],
        'zones' => [['view', 'create', 'edit', 'delete'], 'Delivery zones', 'مناطق التوصيل'],
        'catalog' => [['view', 'create', 'edit', 'delete', 'approve', 'export'], 'Catalog', 'الكتالوج'],
        'pricing' => [['view', 'edit', 'approve', 'override'], 'Pricing', 'التسعير'],
        'inventory' => [['view', 'create', 'edit', 'approve', 'export', 'override'], 'Inventory', 'المخزون'],
        'transfers' => [['view', 'create', 'edit', 'approve'], 'Stock transfers', 'تحويلات المخزون'],
        'stocktaking' => [['view', 'create', 'edit', 'approve'], 'Stocktaking', 'الجرد'],
        'purchasing' => [['view', 'create', 'edit', 'approve', 'export'], 'Purchasing', 'المشتريات'],
        'suppliers' => [['view', 'create', 'edit', 'delete'], 'Suppliers', 'الموردون'],
        'orders' => [['view', 'create', 'edit', 'approve', 'export', 'assign', 'override'], 'Orders', 'الطلبات'],
        'pickup' => [['view', 'edit', 'approve'], 'Store pickup', 'الاستلام من الفرع'],
        'deliveries' => [['view', 'create', 'edit', 'assign', 'override'], 'Deliveries', 'التوصيل'],
        'payments' => [['view', 'create', 'edit', 'export', 'override'], 'Payments', 'المدفوعات'],
        'refunds' => [['view', 'create', 'approve', 'refund'], 'Refunds', 'المبالغ المستردة'],
        'reconciliation' => [['view', 'create', 'approve', 'export'], 'Reconciliation', 'التسويات'],
        'auctions' => [['view', 'create', 'edit', 'delete', 'approve', 'override'], 'Auctions', 'المزادات'],
        'customers' => [['view', 'edit', 'export'], 'Customers', 'العملاء'],
        'reviews' => [['view', 'approve', 'delete'], 'Reviews & Q&A', 'التقييمات والأسئلة'],
        'support' => [['view', 'create', 'edit', 'assign'], 'Support', 'الدعم'],
        'marketing' => [['view', 'create', 'edit', 'delete', 'approve'], 'Marketing', 'التسويق'],
        'loyalty' => [['view', 'edit', 'override'], 'Loyalty & referrals', 'الولاء والإحالات'],
        'cms' => [['view', 'create', 'edit', 'delete', 'approve'], 'Content', 'المحتوى'],
        'notifications' => [['view', 'create', 'edit'], 'Notifications', 'الإشعارات'],
        'reports' => [['view', 'export'], 'Reports', 'التقارير'],
        'audit' => [['view', 'export'], 'Audit log', 'سجل التدقيق'],
        'settings' => [['view', 'edit'], 'Settings & integrations', 'الإعدادات والتكاملات'],
    ];

    /** role key => [scope, name_en, name_ar, permissions (supports "module.*" and "*.view")] */
    public const ROLES = [
        'super_admin' => ['global', 'Super Admin', 'المشرف العام', ['*']],
        'admin' => ['global', 'Admin', 'مدير النظام', ['*']],
        'branch_manager' => ['branch', 'Branch Manager', 'مدير الفرع', [
            'dashboard.view', 'branches.view', 'branches.edit', 'employees.*', 'zones.view', 'catalog.view', 'pricing.view', 'pricing.edit',
            'inventory.*', 'transfers.*', 'stocktaking.*', 'purchasing.view', 'purchasing.create', 'purchasing.edit', 'purchasing.approve',
            'suppliers.view', 'orders.*', 'pickup.*', 'deliveries.*', 'payments.view', 'payments.create', 'refunds.view', 'refunds.create',
            'reconciliation.view', 'reconciliation.create', 'reports.view', 'reports.export', 'customers.view', 'support.view',
        ]],
        'inventory_manager' => ['branch', 'Inventory Manager', 'مدير المخزون', [
            'dashboard.view', 'catalog.view', 'inventory.*', 'transfers.*', 'stocktaking.*', 'purchasing.*', 'suppliers.*', 'reports.view',
        ]],
        'order_manager' => ['branch', 'Order Manager', 'مدير الطلبات', [
            'dashboard.view', 'catalog.view', 'inventory.view', 'orders.view', 'orders.edit', 'orders.approve', 'orders.assign', 'orders.export',
            'pickup.*', 'deliveries.view', 'deliveries.create', 'deliveries.assign', 'deliveries.edit', 'customers.view', 'payments.view',
            'refunds.view', 'refunds.create', 'support.view',
        ]],
        'sales_employee' => ['branch', 'Sales Employee', 'موظف مبيعات', [
            'dashboard.view', 'catalog.view', 'inventory.view', 'orders.view', 'orders.edit', 'customers.view', 'pickup.view',
        ]],
        'pickup_employee' => ['branch', 'Pickup Employee', 'موظف الاستلام', [
            'dashboard.view', 'orders.view', 'pickup.view', 'pickup.edit', 'pickup.approve', 'payments.create',
        ]],
        'delivery_employee' => ['branch', 'Delivery Employee', 'موظف التوصيل', [
            'deliveries.view', 'deliveries.edit', 'payments.create',
        ]],
        'auction_manager' => ['global', 'Auction Manager', 'مدير المزادات', [
            'dashboard.view', 'auctions.*', 'catalog.view', 'inventory.view', 'customers.view', 'reports.view',
        ]],
        'marketing_manager' => ['global', 'Marketing Manager', 'مدير التسويق', [
            'dashboard.view', 'marketing.*', 'cms.*', 'notifications.*', 'loyalty.view', 'loyalty.edit', 'catalog.view', 'customers.view',
            'reviews.*', 'reports.view',
        ]],
        'accountant' => ['global', 'Accountant', 'المحاسب', [
            'dashboard.view', 'payments.*', 'refunds.*', 'reconciliation.*', 'orders.view', 'customers.view', 'reports.view', 'reports.export',
            'purchasing.view', 'suppliers.view', 'inventory.view',
        ]],
        'customer_support' => ['global', 'Customer Support', 'خدمة العملاء', [
            'dashboard.view', 'customers.view', 'customers.edit', 'orders.view', 'orders.edit', 'support.*', 'reviews.*', 'refunds.view',
            'refunds.create', 'deliveries.view', 'pickup.view', 'payments.view', 'catalog.view', 'loyalty.view',
        ]],
        'auditor' => ['global', 'Auditor (read-only)', 'مدقق (قراءة فقط)', ['*.view', 'audit.export', 'reports.export']],
    ];

    /** @return list<array{key:string,module:string,action:string,name_en:string,name_ar:string}> */
    public static function permissions(): array
    {
        $actionNames = [
            'view' => ['View', 'عرض'], 'create' => ['Create', 'إنشاء'], 'edit' => ['Edit', 'تعديل'], 'delete' => ['Delete', 'حذف'],
            'approve' => ['Approve', 'اعتماد'], 'export' => ['Export', 'تصدير'], 'assign' => ['Assign', 'تعيين'],
            'refund' => ['Refund', 'استرداد'], 'override' => ['Override', 'تجاوز'],
        ];
        $out = [];
        foreach (self::MODULES as $module => [$actions, $en, $ar]) {
            foreach ($actions as $action) {
                $out[] = [
                    'key' => "{$module}.{$action}",
                    'module' => $module,
                    'action' => $action,
                    'name_en' => "{$actionNames[$action][0]} {$en}",
                    'name_ar' => "{$actionNames[$action][1]} {$ar}",
                ];
            }
        }

        return $out;
    }

    /** Expand wildcard patterns into concrete permission keys. */
    public static function expand(array $patterns): array
    {
        $all = array_column(self::permissions(), 'key');
        $keys = [];
        foreach ($patterns as $pattern) {
            if ($pattern === '*') {
                return $all;
            }
            [$m, $a] = explode('.', $pattern, 2);
            foreach ($all as $key) {
                [$km, $ka] = explode('.', $key, 2);
                if (($m === '*' || $m === $km) && ($a === '*' || $a === $ka)) {
                    $keys[] = $key;
                }
            }
        }

        return array_values(array_unique($keys));
    }
}
