<?php

/*
 | Paid assembly and installation in the sales reports (docs/guides/ASSEMBLY_SALES_AND_PETTY_CASH.md).
 */
return [
    'filter' => 'Orders with assembly',
    'filter_hint' => 'Show only the branch parts of orders that include paid assembly.',
    'filter_on' => 'Only orders with paid assembly',
    'note' => 'Assembly is a flat fee per unit, charged on delivery orders only, and no promotion or tax touches it. Gross is the fee of the units still on the order. Refunded is the fee of the units that came back. A job is one visit to a customer that still has assembly left after returns. Everything is counted on the day the order was placed.',
    'col_jobs' => 'Assembly jobs',
    'col_returned_units' => 'Units returned',
    'col_refunded' => 'Refunded',
    'col_assembly_jobs' => 'Assembly jobs',
    'col_assembly_units' => 'Assembly units',
    'col_assembly_gross' => 'Assembly revenue (gross)',
    'col_assembly_refunded' => 'Assembly refunded',
    'col_assembly_net' => 'Assembly revenue (net)',
];
