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
        // The wall display: an account whose ONLY right is board.view can open the live board in its read-only wall mode and nothing else (docs/guides/SEARCH_AND_LIVE_BOARD.md).
        'board' => [['view'], 'Live board wall display', 'شاشة الجدار للوحة المباشرة'],
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
        // The narrow right to stand behind a petty-cash payout above the limit (payments.override also counts, but opens much more): docs/guides/ASSEMBLY_SALES_AND_PETTY_CASH.md.
        'payouts' => [['approve'], 'Petty-cash payouts', 'مصروفات الصندوق النقدي'],
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
            'dashboard.view', 'board.view', 'branches.view', 'branches.edit', 'employees.*', 'zones.view', 'catalog.view', 'pricing.view', 'pricing.edit',
            'inventory.*', 'transfers.*', 'stocktaking.*', 'purchasing.view', 'purchasing.create', 'purchasing.edit', 'purchasing.approve',
            'suppliers.view', 'orders.*', 'pickup.*', 'deliveries.*', 'payments.view', 'payments.create', 'payments.edit', 'refunds.view', 'refunds.create',
            'reconciliation.view', 'reconciliation.create', 'reports.view', 'reports.export', 'customers.view', 'support.view',
        ]],
        'inventory_manager' => ['branch', 'Inventory Manager', 'مدير المخزون', [
            'dashboard.view', 'catalog.view', 'inventory.*', 'transfers.*', 'stocktaking.*', 'purchasing.*', 'suppliers.*', 'reports.view',
        ]],
        'order_manager' => ['branch', 'Order Manager', 'مدير الطلبات', [
            'dashboard.view', 'catalog.view', 'inventory.view', 'orders.view', 'orders.create', 'orders.edit', 'orders.approve', 'orders.assign', 'orders.export',
            'pickup.*', 'deliveries.view', 'deliveries.create', 'deliveries.assign', 'deliveries.edit', 'customers.view', 'payments.view',
            'refunds.view', 'refunds.create', 'support.view',
        ]],
        'sales_employee' => ['branch', 'Sales Employee', 'موظف مبيعات', [
            'dashboard.view', 'catalog.view', 'inventory.view', 'orders.view', 'orders.create', 'orders.edit', 'customers.view', 'pickup.view',
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
            'dashboard.view', 'customers.view', 'customers.edit', 'orders.view', 'orders.create', 'orders.edit', 'support.*', 'reviews.*', 'refunds.view',
            'refunds.create', 'deliveries.view', 'pickup.view', 'payments.view', 'catalog.view', 'loyalty.view',
        ]],
        'auditor' => ['global', 'Auditor (read-only)', 'مدقق (قراءة فقط)', ['*.view', 'audit.export', 'reports.export']],
    ];

    /**
     * Branch-employee features: the plain-words checklist an admin or branch manager ticks when adding a branch employee.
     * group => [feature id => ['keys' => permission keys it switches on, 'needs' => features it cannot work without]].
     * Group and feature texts live in lang/{en,ar}/branch_access.php (branch_access.group_<group>, branch_access.<feature>[_hint]).
     * Only permissions that are safe at ONE branch are listed here; everything else (see ADMIN_ONLY_MODULES) is never offered.
     * BranchFeatureCatalog reads this table; tests/Feature/Identity/BranchFeatureCatalogTest keeps it consistent with MODULES.
     */
    public const BRANCH_FEATURES = [
        'home' => [
            'home' => ['keys' => ['dashboard.view']],
        ],
        'orders' => [
            'orders_view' => ['keys' => ['orders.view']],
            'orders_prepare' => ['keys' => ['orders.edit'], 'needs' => ['orders_view']],
            'orders_create' => ['keys' => ['orders.create'], 'needs' => ['orders_view', 'products_view']],
            'orders_cancel' => ['keys' => ['orders.approve'], 'needs' => ['orders_view']],
            'refunds_request' => ['keys' => ['refunds.view', 'refunds.create'], 'needs' => ['orders_view']],
        ],
        'pickup' => [
            'pickup_view' => ['keys' => ['pickup.view']],
            'pickup_handover' => ['keys' => ['pickup.edit', 'pickup.approve'], 'needs' => ['pickup_view']],
            'deliveries_view' => ['keys' => ['deliveries.view']],
            'deliveries_manage' => ['keys' => ['deliveries.create', 'deliveries.edit'], 'needs' => ['deliveries_view']],
            'deliveries_assign' => ['keys' => ['deliveries.assign'], 'needs' => ['deliveries_view']],
        ],
        'stock' => [
            'stock_view' => ['keys' => ['inventory.view']],
            'stock_adjust' => ['keys' => ['inventory.create', 'inventory.edit'], 'needs' => ['stock_view']],
            'stock_count' => ['keys' => ['stocktaking.view', 'stocktaking.create', 'stocktaking.edit'], 'needs' => ['stock_view']],
            'stock_transfer' => ['keys' => ['transfers.view', 'transfers.create', 'transfers.edit'], 'needs' => ['stock_view']],
            'stock_approve' => ['keys' => ['inventory.approve', 'stocktaking.approve', 'transfers.approve'], 'needs' => ['stock_view']],
        ],
        'products' => [
            'products_view' => ['keys' => ['catalog.view', 'pricing.view']],
            'prices_edit' => ['keys' => ['pricing.edit'], 'needs' => ['products_view']],
        ],
        'customers' => [
            'customers_view' => ['keys' => ['customers.view']],
        ],
        'support' => [
            'support_view' => ['keys' => ['support.view']],
            'support_reply' => ['keys' => ['support.create', 'support.edit'], 'needs' => ['support_view']],
        ],
        'reports' => [
            'reports_view' => ['keys' => ['reports.view']],
            'reports_export' => ['keys' => ['reports.export'], 'needs' => ['reports_view']],
        ],
        'cash' => [
            'cash_view' => ['keys' => ['payments.view']],
            'cash_collect' => ['keys' => ['payments.create']],
            'cash_handover' => ['keys' => ['payments.edit'], 'needs' => ['cash_view']],
            'cash_payout_approve' => ['keys' => ['payouts.approve'], 'needs' => ['cash_view']],
        ],
        'staff' => [
            'staff_view' => ['keys' => ['employees.view']],
        ],
        // A screen on the wall of the branch: the live board, read only, nothing else. It cannot be mixed with any other feature (BranchEmployeeService).
        'wall' => [
            'wall_display' => ['keys' => ['board.view']],
        ],
    ];

    /** One-tap presets: preset id => the features it ticks (the person can still adjust them by hand). Texts: branch_access.preset_<id>. */
    public const BRANCH_PRESETS = [
        'branch_manager' => [
            'home', 'orders_view', 'orders_prepare', 'orders_create', 'orders_cancel', 'refunds_request', 'pickup_view', 'pickup_handover',
            'deliveries_view', 'deliveries_manage', 'deliveries_assign', 'stock_view', 'stock_adjust', 'stock_count', 'stock_transfer',
            'stock_approve', 'products_view', 'prices_edit', 'customers_view', 'support_view', 'reports_view', 'reports_export',
            'cash_view', 'cash_collect', 'cash_handover', 'staff_view',
        ],
        'order_desk' => [
            'home', 'orders_view', 'orders_prepare', 'orders_create', 'refunds_request', 'pickup_view', 'pickup_handover', 'deliveries_view',
            'deliveries_manage', 'deliveries_assign', 'stock_view', 'products_view', 'customers_view', 'support_view',
        ],
        'cashier' => [
            'home', 'orders_view', 'pickup_view', 'pickup_handover', 'cash_view', 'cash_collect', 'customers_view',
        ],
        'stock_keeper' => [
            'home', 'stock_view', 'stock_adjust', 'stock_count', 'stock_transfer', 'products_view',
        ],
        'delivery_coordinator' => [
            'home', 'orders_view', 'deliveries_view', 'deliveries_manage', 'deliveries_assign', 'pickup_view', 'customers_view',
        ],
        'read_only' => [
            'home', 'orders_view', 'pickup_view', 'deliveries_view', 'stock_view', 'products_view', 'customers_view', 'support_view', 'reports_view',
        ],
        'wall_display' => ['wall_display'],
    ];

    /** The one permission a wall display account holds. */
    public const WALL_DISPLAY_KEY = 'board.view';

    /**
     * Modules that configure the whole company (or the public site) and therefore never appear in a branch employee's checklist,
     * plus the employees.* actions that would let a branch employee create or change other accounts.
     */
    public const ADMIN_ONLY_MODULES = ['roles', 'settings', 'notifications', 'cms', 'marketing', 'audit', 'branches', 'zones', 'auctions', 'loyalty', 'reviews'];

    /**
     * catalog.create/edit/delete/approve change the SHARED product catalog (every branch and the website): the dashboard needs them company-wide and the staff
     * API checks them without a branch, so a branch-only grant would still edit global products. They are never offered per branch.
     */
    public const ADMIN_ONLY_KEYS = ['employees.create', 'employees.edit', 'employees.delete', 'employees.assign', 'catalog.create', 'catalog.edit', 'catalog.delete', 'catalog.approve'];

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
