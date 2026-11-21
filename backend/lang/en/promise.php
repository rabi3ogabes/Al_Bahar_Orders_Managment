<?php

// The delivery and pickup date the product page, the cart and the checkout promise (docs/guides/DELIVERY_PROMISE_ASSEMBLY_AND_SHARING.md).
// `:day` is "today", "tomorrow" or a weekday and date ("Sat 22 Nov"); `:time` is "9 pm" or "4:15 pm".
return [
    'on_date' => ':date',
    'today' => 'today',
    'tomorrow' => 'tomorrow',
    'delivery_slot' => 'Delivery :day, :from – :to',
    'delivery_day' => 'Delivery :day',
    'delivery_today_before' => 'Delivery today before :time',
    'delivery_today' => 'Delivery today',
    'pickup_slot' => 'Pick up :day, :from – :to',
    'pickup_day' => 'Pick up :day from :time',
    'pickup_today' => 'Pick up today from :time',
    'order_by' => 'if you order by :time',
    'all_delivery_by' => 'All delivered by :day',
    'all_pickup_by' => 'All ready to pick up by :day',
    'partial_delivery' => 'Part of your order arrives by :day. We will confirm the rest.',
    'partial_pickup' => 'Part of your order is ready by :day. We will confirm the rest.',
    'unconfirmed_delivery' => 'We will confirm your delivery date',
    'unconfirmed_pickup' => 'We will confirm your pick-up time',
    'address_hint' => 'The exact date depends on your address.',
    'reason' => [
        'out_of_stock' => 'Not in stock right now.',
        'item_unavailable' => 'An item is not available right now.',
        'branch_closed' => ':branch is closed for now.',
        'not_offered' => ':branch does not offer this.',
        'no_date' => 'No date is open yet.',
    ],
];
