<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Reports\Jobs\GenerateExportJob;
use App\Domain\Reports\Models\DataExport;
use App\Domain\Reports\Services\ReportService;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

/** @tags Admin · Reports */
class ReportAdminController extends ApiController
{
    public function run(Request $request, string $report, ReportService $reports): JsonResponse
    {
        $data = $this->filters($request);

        return response()->json(['data' => $reports->run($this->user(), $report, $data['from'], $data['to'], $data['branch_id'] ?? null)]);
    }

    /** Queue an export; every export request is audited. */
    public function export(Request $request, ReportService $reports, AuditLogger $audit): JsonResponse
    {
        $data = $this->filters($request) + $request->validate(['report' => ['required', Rule::in(array_keys(ReportService::REPORTS))]]);
        abort_unless(app(AccessControl::class)->allows($this->user(), 'reports.export'), 403);
        $export = DataExport::create(['user_id' => $this->user()->id, 'type' => $data['report'], 'filters' => collect($data)->except('report')->all(), 'status' => 'queued']);
        $audit->log('report.exported', $export, [], ['report' => $data['report'], 'filters' => $export->filters]);
        GenerateExportJob::dispatch($export->id);

        return response()->json(['data' => $export->fresh()], 202);
    }

    public function exports(): JsonResponse
    {
        return response()->json(['data' => DataExport::where('user_id', $this->user()->id)->latest()->limit(50)->get()->map(fn ($e) => $e->toArray() + [
            'download_url' => $e->status === 'completed' && $e->expires_at?->isFuture() ? URL::temporarySignedRoute('api.v1.admin.exports.download', $e->expires_at, ['export' => $e->id]) : null,
        ])]);
    }

    public function download(Request $request, DataExport $export)
    {
        abort_unless($request->hasValidSignature() && $export->user_id === $this->user()->id && $export->status === 'completed', 403);

        return Storage::disk($export->disk)->download($export->path, $export->type.'-'.now()->format('Ymd').'.csv');
    }

    private function filters(Request $request): array
    {
        return $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from', 'branch_id' => 'nullable|uuid']);
    }
}
