<?php

namespace App\Support\Scale;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Throwable;

/**
 * Runs a request through the real application, catches every SQL statement it sends and proves each SELECT with EXPLAIN (ANALYZE, BUFFERS)
 * (docs/guides/SCALE_PROOF.md). A statement FAILS when it
 *   - reads a fifth or more of a big table (100,000+ rows) by a sequential scan (an index is missing or unusable);
 *   - hands back more than LIST_ROWS rows with no LIMIT (an unbounded list);
 *   - skips more than OFFSET_ROWS rows to reach its page (a deep OFFSET);
 *   - runs longer than its budget on a warm cache (50 ms; 100 ms for search and month-range reports).
 * A request also fails when one statement shape repeats more than REPEAT_MAX times (an N+1).
 * Every scenario runs inside a transaction that is rolled back, so the proof never changes the data.
 */
class PlanRunner
{
    public const SEQ_ROWS = 5000;

    public const LIST_ROWS = 200;

    public const OFFSET_ROWS = 1000;

    public const REPEAT_MAX = 12;

    /** @var array<string, int> table name => estimated rows */
    private array $big = [];

    /** @var list<array{sql: string, bindings: array<int, mixed>, ms: float}> */
    private array $log = [];

    public function __construct()
    {
        DB::listen(function (QueryExecuted $q): void {
            $this->log[] = ['sql' => $q->sql, 'bindings' => $q->bindings, 'ms' => $q->time];
        });
        foreach (DB::select("select relname, reltuples::bigint as n from pg_class where relkind in ('r','p') and relnamespace = 'public'::regnamespace and reltuples > 100000") as $r) {
            $this->big[$r->relname] = (int) $r->n;
        }
    }

    /**
     * @param  array{name: string, as: string, method: string, uri: string, params?: array<string, mixed>, budget?: int, user: ?object}  $s
     * @return array{name: string, status: int, queries: int, db_ms: float, worst_ms: float, worst_sql: string, repeat: int, issues: list<string>, statements: list<array<string, mixed>>}
     */
    public function run(array $s): array
    {
        $kernel = app(Kernel::class);
        $budget = $s['budget'] ?? 50;
        $captured = [];
        $status = 0;

        DB::beginTransaction();
        try {
            // Round one warms the caches; round two is the one that is measured.
            for ($round = 0; $round < 2; $round++) {
                $this->log = [];
                Cache::store('array')->flush();
                $status = $this->dispatch($kernel, $s);
                $captured = $this->log;
            }
            $statements = $this->prove($captured, $budget);
        } finally {
            DB::rollBack();
        }

        $shapes = [];
        foreach ($captured as $c) {
            $key = preg_replace('/\s+/', ' ', $c['sql']);
            $shapes[$key] = ($shapes[$key] ?? 0) + 1;
        }
        $repeat = $shapes === [] ? 0 : max($shapes);
        $issues = [];
        foreach ($statements as $st) {
            foreach ($st['issues'] as $i) {
                $issues[] = $i;
            }
        }
        if ($repeat > self::REPEAT_MAX) {
            $top = array_search($repeat, $shapes, true);
            $issues[] = "N+1: one statement ran {$repeat} times (".substr((string) $top, 0, 110).')';
        }
        if ($status >= 500 || $status === 0) {
            $issues[] = "request answered {$status}";
        }

        return [
            'name' => $s['name'], 'status' => $status, 'queries' => count($captured), 'db_ms' => round(array_sum(array_column($captured, 'ms')), 1),
            'worst_ms' => $statements === [] ? 0.0 : max(array_column($statements, 'exec_ms')), 'worst_sql' => $this->worst($statements),
            'repeat' => $repeat, 'issues' => array_values(array_unique($issues)), 'statements' => $statements,
        ];
    }

    /** @param  array<string, mixed>  $s */
    private function dispatch(Kernel $kernel, array $s): int
    {
        $user = $s['user'] ?? null;
        if ($user !== null) {
            if ($s['as'] === 'web') {
                Auth::shouldUse('web');
                Auth::guard('web')->setUser($user);
            } else {
                Sanctum::actingAs($user, $s['as'] === 'staff' ? ['staff', '*'] : ['customer', '*']);
            }
        }
        $isWeb = $s['as'] === 'web';
        $server = ['REMOTE_ADDR' => '10.9.8.7', 'HTTP_ACCEPT' => $isWeb ? 'text/html' : 'application/json', 'HTTP_ACCEPT_LANGUAGE' => 'en'];
        $params = $s['params'] ?? [];
        $request = Request::create($s['uri'], $s['method'], $s['method'] === 'GET' ? $params : [], [], [], $server, $s['method'] === 'GET' ? null : json_encode($params));
        if ($s['method'] !== 'GET') {
            $request->headers->set('Content-Type', 'application/json');
        }
        try {
            $response = $kernel->handle($request);
            $code = $response->getStatusCode();
            $kernel->terminate($request, $response);
        } catch (Throwable $e) {
            return 599;
        }

        return $code;
    }

    /**
     * @param  list<array{sql: string, bindings: array<int, mixed>, ms: float}>  $captured
     * @return list<array<string, mixed>>
     */
    private function prove(array $captured, int $budget): array
    {
        $out = [];
        $seen = [];
        foreach ($captured as $c) {
            $sql = $c['sql'];
            if (! preg_match('/^\s*(select|with)\b/i', $sql)) {
                continue;
            }
            $key = md5($sql.json_encode($c['bindings']));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $row = ['sql' => $sql, 'ms' => $c['ms'], 'exec_ms' => 0.0, 'issues' => [], 'plan' => ''];
            try {
                DB::statement('SAVEPOINT scale_plan');
                $plan = null;
                for ($i = 0; $i < 2; $i++) {
                    $plan = json_decode((string) DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql, $c['bindings'])->{'QUERY PLAN'}, true)[0];
                }
                DB::statement('RELEASE SAVEPOINT scale_plan');
            } catch (Throwable $e) {
                DB::statement('ROLLBACK TO SAVEPOINT scale_plan');
                $row['issues'][] = 'could not explain: '.substr($e->getMessage(), 0, 100);
                $out[] = $row;

                continue;
            }
            $row['exec_ms'] = round((float) $plan['Execution Time'], 2);
            $row['issues'] = $this->judge($plan['Plan'], $row['exec_ms'], $budget, $sql);
            $row['plan'] = $this->summary($plan['Plan']);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $root
     * @return list<string>
     */
    private function judge(array $root, float $ms, int $budget, string $sql): array
    {
        $issues = [];
        $this->walk($root, function (array $n) use (&$issues): void {
            $loops = max(1, (int) ($n['Actual Loops'] ?? 1));
            if ($n['Node Type'] === 'Seq Scan' && isset($this->big[$n['Relation Name']])) {
                $read = (((int) ($n['Actual Rows'] ?? 0)) + ((int) ($n['Rows Removed by Filter'] ?? 0))) * $loops;
                if ($read > self::SEQ_ROWS && $read >= 0.2 * $this->big[$n['Relation Name']]) {
                    $issues[] = "sequential scan of {$n['Relation Name']} ({$read} rows read)";
                }
            }
            if ($n['Node Type'] === 'Limit' && isset($n['Plans'][0])) {
                $skipped = (int) ($n['Plans'][0]['Actual Rows'] ?? 0) * max(1, (int) ($n['Plans'][0]['Actual Loops'] ?? 1)) - (int) ($n['Actual Rows'] ?? 0);
                if ($skipped > self::OFFSET_ROWS) {
                    $issues[] = "deep OFFSET (skips {$skipped} rows)";
                }
            }
        });
        $rows = (int) ($root['Actual Rows'] ?? 0);
        // A lookup by a list of 20+ keys (`id in (?, ?, ...)`) returns at most as many rows as the caller's list: it is bounded by that list.
        $bounded = in_array($root['Node Type'], ['Limit', 'Aggregate', 'Result', 'ProjectSet'], true) || stripos($sql, ' limit ') !== false || preg_match('/ in \((\?, ){19,}/i', $sql) === 1;
        if (! $bounded && $rows > self::LIST_ROWS) {
            $issues[] = "no LIMIT ({$rows} rows returned)";
        }
        if ($ms > $budget) {
            $issues[] = "slow: {$ms} ms (budget {$budget} ms)";
        }

        return array_values(array_unique($issues));
    }

    /** @param  array<string, mixed>  $node */
    private function walk(array $node, callable $fn): void
    {
        $fn($node);
        foreach ($node['Plans'] ?? [] as $child) {
            $this->walk($child, $fn);
        }
    }

    /** @param  array<string, mixed>  $root */
    private function summary(array $root): string
    {
        $parts = [];
        $this->walk($root, function (array $n) use (&$parts): void {
            $t = $n['Node Type'];
            if (isset($n['Relation Name'])) {
                $t .= ' '.$n['Relation Name'].(isset($n['Index Name']) ? ' ('.$n['Index Name'].')' : '');
            } elseif (isset($n['Index Name'])) {
                $t .= ' ('.$n['Index Name'].')';
            }
            if (in_array($n['Node Type'], ['Seq Scan', 'Index Scan', 'Index Only Scan', 'Bitmap Heap Scan', 'Limit', 'Sort', 'Aggregate'], true)) {
                $parts[] = $t;
            }
        });

        return implode(' > ', array_slice($parts, 0, 6));
    }

    /** @param  list<array<string, mixed>>  $statements */
    private function worst(array $statements): string
    {
        if ($statements === []) {
            return '';
        }
        usort($statements, fn ($a, $b) => $b['exec_ms'] <=> $a['exec_ms']);

        return preg_replace('/\s+/', ' ', substr((string) $statements[0]['sql'], 0, 160)) ?? '';
    }
}
