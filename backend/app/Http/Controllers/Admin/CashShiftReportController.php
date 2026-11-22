<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Services\AccessControl;
use App\Domain\Payments\Models\CashShift;
use App\Domain\Payments\Services\CashShiftReport;
use App\Domain\Payments\Services\CashShiftService;
use App\Http\Controllers\Controller;
use App\Support\BusinessTime;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The printable Z-report of a closed cash shift (docs/guides/CASH_SHIFT.md): one A4 page, in the dashboard language or the one chosen with ?lang=en|ar.
 * Whoever may see the branch's payments may open it; a shift of another branch answers 404 as if it did not exist. The first time it is opened it is the
 * ORIGINAL, every later time it is marked COPY, and every opening is written to the audit log.
 * {@see self::pdf()} is the same report as a PDF file to download: it uses the same counter, so the first of print and PDF together is the ORIGINAL.
 */
class CashShiftReportController extends Controller
{
    public function show(Request $request, CashShift $shift, CashShiftService $shifts, CashShiftReport $report, AccessControl $access): Response
    {
        $user = $request->user();
        abort_unless($access->allows($user, 'payments.view', $shift->branch_id) && ! $shift->isOpen(), 404);
        $lang = (string) $request->query('lang', '');
        if (in_array($lang, config('platform.supported_locales'), true)) {
            app()->setLocale($lang);
        }
        $prints = $shifts->recordPrint($shift, $user);

        // Never kept by the browser or a proxy: every opening is a print that is counted (and shows private amounts).
        return response()->view('documents.z-report', ['report' => $report->for($shift->refresh()), 'copy' => $prints > 1, 'copyNumber' => $prints - 1,
            'printedAt' => BusinessTime::now(), 'printedBy' => $user->name])->header('Cache-Control', 'no-store, private');
    }

    /**
     * The Z-report as a PDF file (docs/guides/ASSEMBLY_SALES_AND_PETTY_CASH.md). Made the way the printed picking list and delivery note are made (dompdf, labels in both
     * languages, Arabic joined by PrintText), and it counts as a print of the report: the first one is the ORIGINAL, every later one is a COPY, each written to the audit log.
     * The PDF is rendered first and counted after, so a PDF that fails to render uses up no original; if another print slipped in meanwhile it is rendered once more with its own number.
     */
    public function pdf(Request $request, CashShift $shift, CashShiftService $shifts, CashShiftReport $report, AccessControl $access): Response
    {
        $user = $request->user();
        abort_unless($access->allows($user, 'payments.view', $shift->branch_id) && ! $shift->isOpen(), 404);
        $lead = (string) $request->query('lang', app()->getLocale());
        $lead = in_array($lead, config('platform.supported_locales'), true) ? $lead : 'en';
        $data = $report->for($shift);
        $render = fn (int $prints): string => Pdf::loadView('documents.z-report-pdf', ['report' => $data, 'lead' => $lead, 'copy' => $prints > 1, 'copyNumber' => $prints - 1,
            'printedAt' => BusinessTime::now(), 'printedBy' => $user->name])->setPaper('a4')->setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false])->output();

        $expected = (int) $shift->print_count + 1;
        $bytes = $render($expected);
        $prints = $shifts->recordPrint($shift, $user);
        if ($prints !== $expected) {
            $bytes = $render($prints);
        }
        $name = __('petty_cash.pdf.file', ['z' => (string) $shift->zLabel(), 'branch' => (string) $shift->branch?->code], 'en');

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
