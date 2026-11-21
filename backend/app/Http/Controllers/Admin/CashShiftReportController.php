<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Services\AccessControl;
use App\Domain\Payments\Models\CashShift;
use App\Domain\Payments\Services\CashShiftReport;
use App\Domain\Payments\Services\CashShiftService;
use App\Http\Controllers\Controller;
use App\Support\BusinessTime;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The printable Z-report of a closed cash shift (docs/guides/CASH_SHIFT.md): one A4 page, in the dashboard language or the one chosen with ?lang=en|ar.
 * Whoever may see the branch's payments may open it; a shift of another branch answers 404 as if it did not exist. The first time it is opened it is the
 * ORIGINAL, every later time it is marked COPY, and every opening is written to the audit log.
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
}
