<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Services\CatalogRewriter;
use Illuminate\Console\Command;

class RewriteCatalog extends Command
{
    protected $signature = 'catalog:rewrite {action : generate | apply | restore} {--limit= : only this many products} {--category= : only this category (English name)} {--run=main : proposals name} {--backup= : backup file (restore)}';

    protected $description = 'Rewrite product names and descriptions professionally (generate proposals, apply with a backup, or restore).';

    public function handle(CatalogRewriter $rewriter): int
    {
        $dir = CatalogRewriter::DIR;
        $proposals = "$dir/proposals-{$this->option('run')}.jsonl";
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        return match ($this->argument('action')) {
            'generate' => $this->generate($rewriter, $proposals, $limit),
            'apply' => $this->apply($rewriter, $proposals, $dir, $limit),
            'restore' => $this->restoreFile($rewriter, $dir),
            default => self::INVALID,
        };
    }

    private function generate(CatalogRewriter $r, string $file, ?int $limit): int
    {
        [$ok, $bad] = $r->generate($file, $limit, $this->option('category') ?: null, progress: fn ($ok, $bad, $total) => $this->line("proposed {$ok}, rejected {$bad} of {$total}"));
        $this->info("Done: {$ok} proposals saved to storage/app/private/{$file}, {$bad} rejected (they keep their current wording).");

        return self::SUCCESS;
    }

    private function apply(CatalogRewriter $r, string $file, string $dir, ?int $limit): int
    {
        $backup = "$dir/backup-".now()->format('Ymd-His').'.json';
        $n = $r->apply($file, $backup, $limit);
        $this->info("Applied to {$n} products. Backup: storage/app/private/{$backup}  (restore: php artisan catalog:rewrite restore --backup={$backup})");

        return self::SUCCESS;
    }

    private function restoreFile(CatalogRewriter $r, string $dir): int
    {
        $file = (string) $this->option('backup');
        if ($file === '') {
            $this->error('Pass --backup=catalog-rewrite/backup-….json');

            return self::INVALID;
        }
        $this->info('Restored '.$r->restore($file).' translations.');

        return self::SUCCESS;
    }
}
