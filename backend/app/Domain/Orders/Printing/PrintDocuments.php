<?php

namespace App\Domain\Orders\Printing;

use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Support\BusinessTime;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

/**
 * Makes the PDF of a picking list or a delivery note for a collection of parts and writes the print to the audit log (see {@see PrintHistory}).
 * The document is rendered first: a print that fails to render is not logged.
 */
final class PrintDocuments
{
    public function __construct(
        private readonly PrintSheets $sheets,
        private readonly PrintHistory $history,
        private readonly PrintSettings $settings,
    ) {}

    /** @param Collection<int, OrderFulfillment> $parts */
    public function make(User $user, PrintKind $kind, Collection $parts): PrintedDocument
    {
        $paper = $this->settings->paper($kind);
        $copies = $this->history->nextCopies($kind, $parts->pluck('id')->all());
        $sheets = $this->sheets->build($kind, $parts, $copies, $user);

        $bytes = Pdf::loadView($kind->view(), ['sheets' => $sheets, 'paper' => $paper])
            ->setPaper($paper)->setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false])->output();
        $this->history->record($user, $kind, $parts, $paper, $copies);

        $stem = $kind->fileStem();
        $name = $parts->count() === 1
            ? $stem.'-'.$parts->first()->number.'.pdf'
            : $stem.'s-'.BusinessTime::now()->format('Ymd-Hi').'-'.$parts->count().'-parts.pdf';

        return new PrintedDocument($bytes, $name, $parts->count());
    }
}
