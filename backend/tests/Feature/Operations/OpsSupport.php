<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Models\BranchEmployee;
use App\Domain\Branches\Services\BranchService;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRole;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Shared builders for the Live Operations tests. Rows go straight into the tables (fast, and no observers fire), like a branch screen would find them. */

/** A branch with the default opening hours (10:00–22:00, Friday 14:00–22:00), custom hours, or none at all ([]). @param array<int, array<string, mixed>>|null $hours */
function opsBranch(?array $hours = null, array $attributes = []): Branch
{
    $branch = Branch::factory()->create($attributes);
    $service = app(BranchService::class);
    if ($hours === null) {
        $service->setWeeklyHours($branch, $service->defaultHours(), audit: false);
    } elseif ($hours !== []) {
        $service->setWeeklyHours($branch, $hours, audit: false);
    }

    return $branch;
}

/** A moment in the shop's time zone, as the immutable date the clock works with. */
function qatar(string $when): CarbonImmutable
{
    return CarbonImmutable::parse($when, 'Asia/Qatar');
}

/** Staff with a role at a branch (or company-wide when null). */
function opsStaff(string $role, ?Branch $branch = null, array $attributes = []): User
{
    if (! Role::where('key', $role)->exists()) {
        test()->seed(RolesAndPermissionsSeeder::class);
    }

    return staffWithRole($role, $branch, $attributes);
}

/**
 * One order with one branch part. $order and $part override columns; $at is when it was placed (and created).
 *
 * @param  array<string, mixed>  $order
 * @param  array<string, mixed>  $part
 * @return array{order: string, part: string, number: string}
 */
function opsOrder(Branch $branch, array $order = [], array $part = [], ?CarbonInterface $at = null, int $units = 1): array
{
    static $seq = 0;
    $seq++;
    $at ??= CarbonImmutable::now();
    $stamp = $at->utc()->format('Y-m-d H:i:s');
    $orderId = (string) Str::uuid();
    $partId = (string) Str::uuid();
    $number = 'AB'.str_pad((string) (900000 + $seq), 6, '0', STR_PAD_LEFT);
    DB::table('orders')->insert(array_merge([
        'id' => $orderId, 'number' => $number, 'status' => 'confirmed', 'payment_status' => 'pending', 'payment_method' => 'cod', 'fulfillment_type' => 'delivery',
        'subtotal' => '100.00', 'grand_total' => '100.00', 'customer_name' => 'Noor Al-Thani', 'customer_phone' => '+97455123456',
        'placed_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp,
    ], $order));
    DB::table('order_fulfillments')->insert(array_merge([
        'id' => $partId, 'order_id' => $orderId, 'branch_id' => $branch->id, 'number' => $number.'-1', 'type' => 'delivery', 'status' => 'pending',
        'created_at' => $stamp, 'updated_at' => $stamp,
    ], $part));
    DB::table('order_items')->insert(['id' => (string) Str::uuid(), 'order_id' => $orderId, 'order_fulfillment_id' => $partId, 'branch_id' => $branch->id, 'sku' => 'SKU-'.$seq,
        'name_en' => 'Sofa', 'name_ar' => 'أريكة', 'unit_price' => '100.00', 'unit_final_price' => '100.00', 'quantity' => $units, 'line_subtotal' => '100.00', 'line_total' => '100.00',
        'created_at' => $stamp, 'updated_at' => $stamp]);

    return ['order' => $orderId, 'part' => $partId, 'number' => $number];
}

/** A delivery of a part. @param array<string, mixed> $attributes */
function opsDelivery(string $partId, Branch $branch, string $status = 'pending', array $attributes = []): string
{
    static $seq = 0;
    $seq++;
    $id = (string) Str::uuid();
    $stamp = $attributes['created_at'] ?? now()->utc()->format('Y-m-d H:i:s');
    DB::table('deliveries')->insert(array_merge([
        'id' => $id, 'order_fulfillment_id' => $partId, 'branch_id' => $branch->id, 'mode' => 'internal', 'status' => $status, 'tracking_number' => 'TRK'.str_pad((string) $seq, 8, '0', STR_PAD_LEFT).Str::upper(Str::random(4)),
        'created_at' => $stamp, 'updated_at' => $stamp,
    ], $attributes));

    return $id;
}

/** A moment this many minutes before "now" (the test clock is frozen with travelTo). */
function opsAgo(int $minutes): CarbonImmutable
{
    return CarbonImmutable::now()->subMinutes($minutes);
}

function opsUtc(CarbonInterface $at): string
{
    return $at->utc()->format('Y-m-d H:i:s');
}

/** Staff at a branch holding exactly these permission keys (a role made for the test). @param list<string> $permissions */
function opsStaffWith(Branch $branch, array $permissions): User
{
    if (! DB::table('permissions')->exists()) {
        test()->seed(RolesAndPermissionsSeeder::class);
    }
    $role = Role::create(['key' => 'ops_t_'.Str::lower(Str::random(10)), 'name_en' => 'Test role', 'name_ar' => 'دور اختبار', 'scope' => 'branch']);
    $role->permissions()->attach(Permission::whereIn('key', $permissions)->pluck('id'));
    $user = User::factory()->staff()->create();
    BranchEmployee::create(['branch_id' => $branch->id, 'user_id' => $user->id, 'is_active' => true]);
    UserRole::create(['user_id' => $user->id, 'role_id' => $role->id, 'branch_id' => $branch->id]);

    return $user;
}

/** A payment row for an order (needed by refunds and cash collections). */
function opsPayment(string $orderId, string $method = 'cod'): string
{
    $id = (string) Str::uuid();
    DB::table('payments')->insert(['id' => $id, 'payable_type' => 'order', 'payable_id' => $orderId, 'order_id' => $orderId, 'method' => $method, 'provider' => 'offline',
        'status' => 'paid', 'amount' => '100.00', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** A refund waiting for approval. */
function opsRefund(string $orderId, CarbonInterface $at, string $amount = '150.00'): string
{
    static $seq = 0;
    $seq++;
    $id = (string) Str::uuid();
    DB::table('refunds')->insert(['id' => $id, 'number' => 'RF'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT).Str::upper(Str::random(3)), 'payment_id' => opsPayment($orderId), 'order_id' => $orderId, 'amount' => $amount,
        'reason_code' => 'customer_request', 'method' => 'manual_cash', 'status' => 'requested', 'created_at' => opsUtc($at), 'updated_at' => opsUtc($at)]);

    return $id;
}

/** Cash a person collected on a delivery and still holds. */
function opsCash(Branch $branch, User $collector, string $orderId, CarbonInterface $collectedAt, string $amount = '250.00'): string
{
    $id = (string) Str::uuid();
    DB::table('cash_collections')->insert(['id' => $id, 'payment_id' => opsPayment($orderId), 'branch_id' => $branch->id, 'collected_by' => $collector->id, 'context' => 'cod', 'tender' => 'cash',
        'amount_due' => $amount, 'amount_collected' => $amount, 'status' => 'collected', 'collected_at' => opsUtc($collectedAt), 'created_at' => opsUtc($collectedAt), 'updated_at' => opsUtc($collectedAt)]);

    return $id;
}

/** A customer's request to cancel, waiting for a manager. */
function opsCancellation(string $orderId, CarbonInterface $at, ?string $requestedBy = null): string
{
    $id = (string) Str::uuid();
    DB::table('order_cancellations')->insert(['id' => $id, 'order_id' => $orderId, 'reason_code' => 'changed_mind', 'reason' => 'Changed my mind', 'requested_by_type' => 'customer', 'requested_by' => $requestedBy,
        'status' => 'requested', 'created_at' => opsUtc($at), 'updated_at' => opsUtc($at)]);
    DB::table('orders')->where('id', $orderId)->update(['status' => 'cancellation_requested']);

    return $id;
}

/** A pickup pass for a part. */
function opsPickup(string $partId, Branch $branch, string $status = 'ready', ?CarbonInterface $expires = null): string
{
    $id = (string) Str::uuid();
    DB::table('pickup_reservations')->insert(['id' => $id, 'order_fulfillment_id' => $partId, 'branch_id' => $branch->id, 'status' => $status, 'token_expires_at' => $expires ? opsUtc($expires) : null,
        'ready_at' => opsUtc(now()), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}
