<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Services\AccessControl;
use App\Domain\Payments\Models\CashPayout;
use App\Domain\Payments\Services\CashPayoutService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The private receipt photo of a petty-cash payout (docs/guides/ASSEMBLY_SALES_AND_PETTY_CASH.md). Whoever may see the branch's payments may open it; a payout of
 * another branch, a payout without a photo, and a photo that is gone all answer 404, so nothing says which payouts exist.
 */
class CashPayoutReceiptController extends Controller
{
    public function show(Request $request, CashPayout $payout, AccessControl $access): StreamedResponse
    {
        abort_unless($access->allows($request->user(), 'payments.view', $payout->branch_id) && $payout->receipt_path !== null, 404);
        $disk = Storage::disk(CashPayoutService::disk());
        abort_unless($disk->exists($payout->receipt_path), 404);

        return $disk->response($payout->receipt_path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline']);
    }
}
