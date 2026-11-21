<?php

namespace App\Domain\Orders\Printing;

use App\Domain\Settings\Services\Settings;

/**
 * The few choices of the printed documents (Settings > Printing). Each is a setting with a plain default, so the owner changes it without a release.
 *
 *  - printing.picking_paper  the paper of the picking list: a4 (default) or a5
 *  - printing.note_paper     the paper of the delivery note: a4 (default) or a5
 *  - printing.note_prices    show the item prices on the delivery note of an order that is NOT cash on delivery (default off). A cash on delivery
 *                            note always shows them: the driver collects the money and the customer checks what the amount is made of.
 */
final class PrintSettings
{
    public const PAPERS = ['a4', 'a5'];

    public const PICKING_PAPER = 'printing.picking_paper';

    public const NOTE_PAPER = 'printing.note_paper';

    public const NOTE_PRICES = 'printing.note_prices';

    public function __construct(private readonly Settings $settings) {}

    /** @return 'a4'|'a5' */
    public function paper(PrintKind $kind): string
    {
        $raw = $this->settings->get($kind === PrintKind::Picking ? self::PICKING_PAPER : self::NOTE_PAPER, 'a4');

        return in_array($raw, self::PAPERS, true) ? $raw : 'a4';
    }

    public function notePricesAlways(): bool
    {
        return (bool) $this->settings->get(self::NOTE_PRICES, false);
    }

    /** The customer care phone of Settings > General: what the delivery note shows when the branch has no phone of its own. */
    public function supportPhone(): ?string
    {
        $phone = trim((string) $this->settings->get('contact.support_phone', ''));

        return $phone === '' ? null : $phone;
    }
}
