<?php

/** Words the customer reads when the branch changes what is in their order (notification, order page, invoice). */
return [
    'reason' => [
        'out_of_stock' => 'Out of stock',
        'not_found' => 'Not found at the branch',
        'damaged' => 'Damaged',
        'quality' => 'Did not pass our quality check',
        'other' => 'Not available',
    ],
    // One short sentence per changed item, ready to join with "; ".
    'line' => [
        'reduce' => ':name: :from → :to',
        'remove' => ':name removed',
        'replace' => ':name replaced with :new',
        'replace_qty' => ':name replaced with :new (× :qty)',
    ],
    'outcome' => [
        'total' => ' New total: :total.',
        'refund' => ' A refund of :amount for the difference has been requested.',
        'cancelled' => ' Your order has been cancelled and any payment will be refunded.',
    ],
    'kind' => [
        'reduced' => 'Quantity reduced',
        'removed' => 'Removed',
        'replaced' => 'Replaced',
        'replacement' => 'Replacement',
    ],
];
