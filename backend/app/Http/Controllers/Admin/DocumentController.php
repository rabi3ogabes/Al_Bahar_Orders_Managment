<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\InvoiceService;
use App\Domain\Reports\Models\DataExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** File downloads for the admin console (invoice PDFs, report exports). */
class DocumentController extends Controller
{
    public function invoice(Order $order, InvoiceService $invoices, AuditLogger $audit)
    {
        $acl = app(AccessControl::class);
        abort_unless(collect($order->branchIds())->contains(fn ($b) => $acl->allows(request()->user(), 'orders.view', $b)), 403);
        $audit->log('order.invoice_downloaded', $order, [], [], ['number' => $order->number]);

        return $invoices->download($order);
    }

    /** Signed, owner-only, unexpired export download. */
    public function export(Request $request, DataExport $export, AuditLogger $audit)
    {
        abort_unless($request->hasValidSignature() && $export->user_id === $request->user()->id && $export->status === 'completed' && $export->expires_at?->isFuture(), 403);
        $audit->log('report.export_downloaded', $export, [], [], ['report' => $export->type]);

        return Storage::disk($export->disk)->download($export->path, $export->type.'-'.now()->format('Ymd').'.csv');
    }
}
