<?php

/** Orders, deliveries and money: the rules that keep goods, cash and statuses in step (docs/operations/DISPATCH.md). */
return [
    'errors' => [
        'delivery_cancelled' => 'This delivery was cancelled. Do not pick it up or deliver it.',
        'order_partly_handed_over' => 'Part of this order has already left the branch, so it cannot be cancelled as a whole. Cancel the parts still at the branch one by one, or record a return for what was handed over.',
        'cancellation_pending' => 'The customer asked to cancel this order and a manager has not decided yet. Hand-over is on hold until the request is approved or declined.',
        'approval_required' => 'Cancelling an order or a part of it needs a manager. Ask a manager to approve it.',
        'delivery_not_yours' => 'This delivery belongs to another driver.',
    ],

    'ui' => [
        'partly_handed_over' => 'Part of this order has already left the branch. To cancel the rest, cancel the parts still at the branch one by one, or record a return for what was handed over.',
    ],

    'push' => [
        'job_cancelled' => 'Delivery cancelled: order :n. Do not pick it up.',
        'job_removed' => 'Order :n was given to another driver. It is no longer yours.',
    ],
];
