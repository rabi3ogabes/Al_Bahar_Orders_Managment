<?php

/** Orders, deliveries and money: the rules that keep goods, cash and statuses in step (docs/operations/DISPATCH.md). */
return [
    'errors' => [
        'substitution_pending' => 'This part is waiting for the customer to answer about a replacement. You can keep preparing the rest; it can be marked ready once they answer or the time is up.',
        'delivery_cancelled' => 'This delivery was cancelled. Do not pick it up or deliver it.',
        'order_partly_handed_over' => 'Part of this order has already left the branch, so it cannot be cancelled as a whole. Cancel the parts still at the branch one by one, or record a return for what was handed over.',
        'cancellation_pending' => 'The customer asked to cancel this order and a manager has not decided yet. Hand-over is on hold until the request is approved or declined.',
        'approval_required' => 'Cancelling an order or a part of it needs a manager. Ask a manager to approve it.',
        'pickup_details_mismatch' => 'These details do not match an order waiting at this branch. Check the order number and the last 4 digits of the phone number.',
        'pickup_manual_locked' => 'Too many wrong tries for this order. Try again in :minutes minutes.',
        'pickup_reason_required' => 'Write why the pass is not used (at least 3 characters).',
        'delivery_not_yours' => 'This delivery belongs to another driver.',
    ],

    'ui' => [
        'cancel_part_hint' => 'Cancels only this branch\'s part of the order and releases its reserved stock. What this part is worth comes off what the customer still owes, or a refund request for exactly that amount goes to finance.',
        'partly_handed_over' => 'Part of this order has already left the branch. To cancel the rest, cancel the parts still at the branch one by one, or record a return for what was handed over.',
        'reissue_pass' => 'Re-issue pass',
        'reissue_confirm' => 'Issue a new pass? The old one stops working at once and the customer is told.',
        'pass_reissued' => 'New pass issued. The customer has been told.',
        'pass_expired' => 'The pass has expired',
        'manual_handover' => 'Hand over without the pass',
        'manual_hint' => 'For a manager. Ask the customer for the last 4 digits of the phone number on the order. Everything is recorded with your reason.',
        'manual_phone' => 'Last 4 digits of the phone number',
        'manual_reason' => 'Why is the pass not used?',
        'manual_reason_ph' => 'For example: the pass expired and the customer is at the counter.',
        'manual_collected_by' => 'Collected by',
        'manual_done' => 'Handed over. Your reason was recorded.',
    ],

    'push' => [
        'job_cancelled' => 'Delivery cancelled: order :n. Do not pick it up.',
        'job_removed' => 'Order :n was given to another driver. It is no longer yours.',
    ],
];
