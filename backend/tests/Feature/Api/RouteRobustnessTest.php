<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Systematic robustness sweep: every API route, hit with garbage path parameters and an empty body as a customer,
 * as a super admin and as a guest, must answer with a 4xx — never a 500 (e.g. "invalid input syntax for type uuid").
 */
function apiRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/') && ! in_array('HEAD', [$r->methods()[0]], true))
        ->map(fn ($r) => ['method' => $r->methods()[0], 'uri' => $r->uri()])
        ->values()->all();
}

it('never answers 500 for garbage path parameters', function (string $actor) {
    $this->seed(RolesAndPermissionsSeeder::class);
    match ($actor) {
        'customer' => actingAsCustomer(),
        'staff' => actingAsStaff(staffWithRole('super_admin')),
        default => null,
    };
    $bad = [];
    foreach (apiRoutes() as $r) {
        if ($r['uri'] === 'api/v1/health') {
            continue;
        }
        $uri = preg_replace_callback('/\{[^}]+\}/', fn ($m) => str_contains($m[0], 'action') ? 'x' : 'not-a-uuid', $r['uri']);
        // A savepoint per request: a failed statement aborts a PostgreSQL transaction and would poison every later request.
        DB::beginTransaction();
        $res = $this->json($r['method'], '/'.$uri, []);
        DB::rollBack();
        if ($res->getStatusCode() >= 500) {
            $bad[] = $r['method'].' /'.$uri.' => '.$res->getStatusCode();
        }
    }
    expect($bad)->toBe([]);
})->with(['customer', 'staff', 'guest']);

it('never answers 500 for array-typed or oversized query parameters', function (string $actor) {
    $this->seed(RolesAndPermissionsSeeder::class);
    match ($actor) {
        'customer' => actingAsCustomer(),
        'staff' => actingAsStaff(staffWithRole('super_admin')),
        default => null,
    };
    $params = ['search', 'q', 'status', 'payment_status', 'branch_id', 'per_page', 'page', 'sort', 'category_id', 'brand_id', 'type', 'from', 'to', 'date',
        'lang', 'limit', 'after_sequence', 'lat', 'lng', 'radius_km', 'channel', 'state', 'filter', 'action', 'method', 'name', 'format', 'days', 'month'];
    $bad = [];
    foreach (apiRoutes() as $r) {
        if ($r['method'] !== 'GET' || $r['uri'] === 'api/v1/health') {
            continue;
        }
        $uri = preg_replace_callback('/\{[^}]+\}/', fn ($m) => (string) Str::uuid(), $r['uri']);
        foreach ([array_fill_keys($params, ['a', 'b']), array_fill_keys($params, str_repeat('x', 5000)), array_fill_keys($params, '%_\\')] as $query) {
            DB::beginTransaction();
            $res = $this->json('GET', '/'.$uri.'?'.http_build_query($query));
            DB::rollBack();
            if ($res->getStatusCode() >= 500) {
                $bad[$r['uri']] = $res->getStatusCode();
            }
        }
    }
    expect($bad)->toBe([]);
})->with(['customer', 'staff', 'guest']);
