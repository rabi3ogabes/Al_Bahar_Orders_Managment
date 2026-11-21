<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Reports\Services\DailyCloseService;
use App\Http\Controllers\Controller;
use App\Support\BusinessTime;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The printable daily close: one A4 page per branch the viewer may see (and the branch filter narrows), with the day's figures and room for the manager's
 * signature. A normal page the browser prints; nothing is stored. The figures, the branches and the rules are the page's own ({@see DailyCloseService}), so a
 * printout can never show a branch the page would not.
 */
class DailyClosePrintController extends Controller
{
    public function show(Request $request, DailyCloseService $closes): View
    {
        $date = (string) $request->query('date', BusinessTime::now()->subDay()->toDateString());
        abort_unless(DailyCloseService::isDate($date) && $date <= BusinessTime::now()->toDateString(), 404);
        $branch = (string) $request->query('branch', '');
        $board = $closes->board($request->user(), $date, Str::isUuid($branch) ? $branch : null);
        abort_if($board === [], 404);

        return view('documents.daily-close', ['board' => $board, 'date' => $date, 'printedAt' => BusinessTime::now(), 'printedBy' => $request->user()->name]);
    }
}
