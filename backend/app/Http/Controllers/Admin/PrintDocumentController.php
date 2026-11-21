<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Orders\Printing\PrintDocuments;
use App\Domain\Orders\Printing\PrintKind;
use App\Domain\Orders\Printing\PrintParts;
use App\Http\Controllers\Controller;
use App\Support\BusinessTime;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * The printed picking list and delivery note of a branch part, or of a batch of parts, as one PDF that opens in the browser (docs/guides/PICKING_LIST_AND_DELIVERY_NOTE.md).
 * Needs what viewing the order needs: `orders.view` at the part's branch (a part at another branch is refused as a whole and nothing is logged).
 * A batch is either named (`parts=id,id`) or chosen by a rule: picking lists cover everything waiting to be prepared (optionally one `branch` and one `type`), delivery notes cover the deliveries of `date` (optionally one `driver` and one `branch`).
 */
class PrintDocumentController extends Controller
{
    public function pickingPart(OrderFulfillment $fulfillment, Request $request, PrintParts $parts, PrintDocuments $documents): Response
    {
        return $this->respond($request, PrintKind::Picking, $parts->named($this->staff($request), PrintKind::Picking, [$fulfillment->id]), $documents);
    }

    public function notePart(OrderFulfillment $fulfillment, Request $request, PrintParts $parts, PrintDocuments $documents): Response
    {
        return $this->respond($request, PrintKind::Note, $parts->named($this->staff($request), PrintKind::Note, [$fulfillment->id]), $documents);
    }

    public function pickingBatch(Request $request, PrintParts $parts, PrintDocuments $documents): Response
    {
        return $this->respond($request, PrintKind::Picking, $this->batch($request, PrintKind::Picking, $parts), $documents);
    }

    public function noteBatch(Request $request, PrintParts $parts, PrintDocuments $documents): Response
    {
        return $this->respond($request, PrintKind::Note, $this->batch($request, PrintKind::Note, $parts), $documents);
    }

    /** @return Collection<int, OrderFulfillment> */
    private function batch(Request $request, PrintKind $kind, PrintParts $parts): Collection
    {
        $q = $request->validate([
            'parts' => ['nullable', 'string', 'max:4000'],
            'type' => ['nullable', 'in:delivery,pickup'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'driver' => ['nullable', 'string', 'max:40'],
            'branch' => ['nullable', 'uuid'],
        ]);
        $user = $this->staff($request);
        if (! empty($q['parts'])) {
            return $parts->named($user, $kind, explode(',', $q['parts']));
        }

        return match ($kind) {
            PrintKind::Note => $parts->deliveriesOn($user, $q['date'] ?? BusinessTime::now()->toDateString(), $q['driver'] ?? null, $q['branch'] ?? null),
            PrintKind::Picking => $parts->waitingToPrepare($user, $q['branch'] ?? null, $q['type'] ?? null),
        };
    }

    /** The signed-in staff member (the routes sit behind the staff middleware; anyone else is turned away here as well). */
    private function staff(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** @param Collection<int, OrderFulfillment> $parts */
    private function respond(Request $request, PrintKind $kind, Collection $parts, PrintDocuments $documents): Response
    {
        abort_if($parts->isEmpty(), 404, __('print_docs.errors.nothing'));
        set_time_limit(120); // a full batch is a few seconds of PDF work, but never let a long list die half way
        $pdf = $documents->make($this->staff($request), $kind, $parts);

        // The PDF carries a customer's name, phone and address: it is never cached, and it opens in the browser (inline) so one click shows it.
        return response($pdf->bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$pdf->fileName.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Printed-Parts' => (string) $pdf->parts,
        ]);
    }
}
