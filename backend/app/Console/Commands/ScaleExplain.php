<?php

namespace App\Console\Commands;

use App\Support\Scale\PlanRunner;
use App\Support\Scale\ScaleScenarios;
use App\Support\Scale\ScaleSeeder;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * The plan test (docs/guides/SCALE_PROOF.md): replays the busiest requests of the customer API, the staff and driver API and the dashboard against a
 * *_scale database, proves every SELECT they send with EXPLAIN (ANALYZE, BUFFERS) and fails on a sequential scan of a big table, an unbounded list, a deep
 * OFFSET, an N+1 or a slow statement. Everything runs inside a rolled-back transaction.
 */
class ScaleExplain extends Command
{
    protected $signature = 'scale:explain {--only= : Run the scenarios whose name contains this text} {--verbose-plans : Print the plan summary of every statement} {--failing : Print the SQL and plan of every statement that failed} {--markdown= : Write the result table to this file}';

    protected $description = 'Prove every request-path query with EXPLAIN ANALYZE on a *_scale database (fails on a seq scan, a missing LIMIT, a deep OFFSET, an N+1 or a slow query)';

    public function handle(): int
    {
        try {
            ScaleSeeder::assertSafe();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        config(['session.driver' => 'array', 'cache.default' => 'array', 'queue.default' => 'sync', 'broadcasting.default' => 'null']);
        $runner = new PlanRunner;
        $rows = [];
        $failed = 0;
        foreach (ScaleScenarios::all() as $s) {
            if ($this->option('only') && ! str_contains($s['name'], (string) $this->option('only'))) {
                continue;
            }
            $r = $runner->run($s);
            $ok = $r['issues'] === [];
            $failed += $ok ? 0 : 1;
            $rows[] = $r + ['ok' => $ok];
            $this->line(sprintf('%s %-46s http %d  %3d queries  db %7.1f ms  worst %6.1f ms%s', $ok ? 'PASS' : 'FAIL', $s['name'], $r['status'], $r['queries'], $r['db_ms'], $r['worst_ms'], $ok ? '' : '  <- '.implode('; ', $r['issues'])));
            if ($this->option('failing')) {
                foreach ($r['statements'] as $st) {
                    if ($st['issues'] !== []) {
                        $this->line(sprintf('      %6.2f ms  %s', $st['exec_ms'], preg_replace('/\s+/', ' ', substr((string) $st['sql'], 0, 700))));
                        $this->line('               plan: '.$st['plan'].'  => '.implode('; ', $st['issues']));
                    }
                }
            }
            if ($this->option('verbose-plans')) {
                foreach ($r['statements'] as $st) {
                    $this->line(sprintf('      %6.2f ms  %s', $st['exec_ms'], $st['plan']));
                }
            }
        }
        if ($this->option('markdown')) {
            file_put_contents((string) $this->option('markdown'), $this->markdown($rows));
        }
        $this->newLine();
        $this->line(sprintf('%d scenarios, %d failed', count($rows), $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function markdown(array $rows): string
    {
        $out = "| Request | HTTP | Queries | DB ms | Worst ms | Result |\n|---|---|---|---|---|---|\n";
        foreach ($rows as $r) {
            $out .= sprintf("| %s | %d | %d | %.1f | %.1f | %s |\n", $r['name'], $r['status'], $r['queries'], $r['db_ms'], $r['worst_ms'], $r['ok'] ? 'pass' : 'FAIL: '.str_replace('|', '/', implode('; ', $r['issues'])));
        }

        return $out;
    }
}
