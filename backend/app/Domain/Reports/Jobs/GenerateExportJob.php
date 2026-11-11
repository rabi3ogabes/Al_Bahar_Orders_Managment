<?php

namespace App\Domain\Reports\Jobs;

use App\Domain\Identity\Models\User;
use App\Domain\Reports\Models\DataExport;
use App\Domain\Reports\Services\ReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Builds a report export as UTF-8 CSV (with BOM for Excel Arabic support) on the private disk. Links expire after 24h. */
class GenerateExportJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 2;

    public function __construct(public readonly string $exportId)
    {
        $this->onQueue('exports');
    }

    public function handle(ReportService $reports): void
    {
        $export = DataExport::findOrFail($this->exportId);
        $export->update(['status' => 'processing']);
        try {
            $f = $export->filters;
            $result = $reports->run(User::findOrFail($export->user_id), $export->type, $f['from'], $f['to'], $f['branch_id'] ?? null);
            $path = 'exports/'.$export->id.'.csv';
            $h = fopen('php://temp', 'w+');
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, $result['columns']);
            foreach ($result['rows'] as $row) {
                // Neutralise spreadsheet formula injection.
                fputcsv($h, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : (is_array($v) ? json_encode($v) : $v), array_values($row)));
            }
            rewind($h);
            Storage::disk('local')->put($path, stream_get_contents($h));
            fclose($h);
            $export->update(['status' => 'completed', 'disk' => 'local', 'path' => $path, 'row_count' => count($result['rows']), 'completed_at' => now(), 'expires_at' => now()->addDay()]);
        } catch (Throwable $e) {
            $export->update(['status' => 'failed', 'error' => substr($e->getMessage(), 0, 490)]);
            throw $e;
        }
    }
}
