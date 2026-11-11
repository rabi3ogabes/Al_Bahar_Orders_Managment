<?php

namespace App\Domain\Inventory\Enums;

enum MovementType: string
{
    case OpeningBalance = 'opening_balance';
    case PurchaseReceipt = 'purchase_receipt';
    case SaleDeduction = 'sale_deduction';
    case Reservation = 'reservation';
    case ReservationRelease = 'reservation_release';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case CustomerReturn = 'customer_return';
    case Damaged = 'damaged';
    case Lost = 'lost';
    case Correction = 'correction';
    case Reversal = 'reversal';
    case StocktakeCorrection = 'stocktake_correction';
}
