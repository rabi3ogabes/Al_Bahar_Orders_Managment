<?php

/**
 * Live Operations Control: the order clock, the "Needs attention" inbox, new-order and reminder alerts, the proof-of-delivery viewer and
 * the "Operations clock" settings. Plain words for branch staff; the Arabic file (../ar/ops_live.php) carries the same keys.
 */
return [
    'nav_attention' => 'Needs attention',

    // The age of a wait, as shown on a chip.
    'age' => [
        'just_now' => 'Just now',
        'minutes' => ':n min',
        'hours' => ':h h',
        'hours_minutes' => ':h h :m min',
        'days' => ':d d',
        'days_hours' => ':d d :h h',
    ],
    'state' => ['ok' => 'On time', 'amber' => 'Getting late', 'red' => 'Late'],
    'chip' => [
        'title' => ':state · waiting :age',
        'paused' => 'The clock is paused while the branch is closed.',
        'real' => 'Real time since: :age',
        'sort_label' => 'Sort the orders',
    ],
    'sort' => ['newest' => 'Newest first', 'oldest' => 'Oldest first (longest waiting)'],

    'items' => '{1} 1 item|[2,*] :count items',
    'collections' => '{1} 1 collection|[2,*] :count collections',
    'failed_jobs' => '{1} 1 background job failed|[2,*] :count background jobs failed',
    'call_customer' => 'Call customer',
    'call_driver' => 'Call driver',

    // What a line in the inbox is about.
    'cat' => [
        'unaccepted' => ['label' => 'Waiting to be accepted'],
        'stuck' => ['label' => 'Being prepared, taking long'],
        'undispatched' => ['label' => 'Ready, no driver yet'],
        'driver_silent' => ['label' => 'Driver has not accepted'],
        'delivery_failed' => ['label' => 'Delivery failed, needs a new plan'],
        'pickup_waiting' => ['label' => 'Waiting to be collected'],
        'cancellation' => ['label' => 'Cancellation request'],
        'refund' => ['label' => 'Refund to approve'],
        'cash' => ['label' => 'Cash not handed in'],
        'queue' => ['label' => 'Background jobs failed', 'subject' => 'System'],
        'campaign' => ['label' => 'Campaign is not moving'],
    ],
    'action' => [
        'open_order' => 'Open order',
        'assign_driver' => 'Assign driver',
        'reassign' => 'Reassign driver',
        'reschedule' => 'Reschedule',
        'reissue' => 'Re-issue pass',
        'review_request' => 'Review request',
        'review_refund' => 'Review refund',
        'receive_cash' => 'Receive cash',
        'open_dashboard' => 'Open dashboard',
        'open_campaigns' => 'Open campaigns',
    ],
    'detail' => ['driver' => 'Driver :name', 'campaign_stuck' => 'No progress for :time'],
    'note' => [
        'drivers_silent' => 'No driver accepted it. The system tried :n drivers one after the other.',
        'pass_expired' => 'The pickup pass has expired. Issue a new one so the customer can collect.',
        'queue_fix' => 'Ask your developer to look at the failed jobs. Customers may be missing messages.',
    ],
    'reissued' => 'A new pickup pass was issued.',

    // The page.
    'page' => [
        'title' => 'Needs attention',
        'eyebrow' => 'Operations',
        'description' => 'Everything that is waiting for a person, how long it has waited, and one tap to deal with it. This list refreshes by itself.',
        'refresh' => 'Refresh now',
        'live' => 'Live',
        'filters' => 'Filter the list',
        'all' => 'All',
        'mine' => 'Mine',
        'mine_hint' => 'Only what is assigned to you',
        'summary_late' => '{0} none late|{1} 1 late|[2,*] :count late',
        'summary_amber' => '{0} none getting late|{1} 1 getting late|[2,*] :count getting late',
        'summary_ok' => '{0} none on time|{1} 1 on time|[2,*] :count on time',
        'truncated' => 'Only the longest waiting of each kind are shown. Open the matching screen to see the rest.',
        'empty_title' => 'All clear. Nothing needs you right now.',
        'empty_mine' => 'Nothing is assigned to you right now.',
        'empty_branch' => 'All clear at this branch.',
        'empty_hint' => 'New work shows up here by itself, and the number in the menu goes up.',
    ],
    'group' => [
        'red' => ['title' => 'Late now', 'hint' => 'Past the late limit. Please deal with these first.'],
        'amber' => ['title' => 'Getting late', 'hint' => 'Past the first limit. Do these next.'],
        'ok' => ['title' => 'On time', 'hint' => 'Waiting, within the time allowed.'],
    ],

    // The small card for the dashboard home.
    'card' => [
        'title' => 'Needs attention',
        'see_all' => 'See all',
        'more' => '{1} + 1 more|[2,*] + :count more',
        'clear' => 'All clear. Nothing needs you right now.',
    ],
    'badge' => ':count need attention',

    // Live toasts on every dashboard page.
    'toast' => [
        'new_orders' => ':n new orders',
        'new_orders_hint' => 'Tap to open the orders',
        'sound_on' => 'Turn the new-order chime on',
        'sound_off' => 'Turn the new-order chime off',
        'sound_hint' => 'Plays a short chime when a new order arrives. It is off until you turn it on, and only on this device.',
    ],

    // Alerts to the branch team.
    'push' => [
        'new_title' => 'New order :number',
        'new_body' => '{1} 1 item to prepare at your branch. Open the app to accept it.|[2,*] :count items to prepare at your branch. Open the app to accept it.',
        'amber_title' => 'Order :number is waiting',
        'amber_body' => 'Waiting to be accepted for :time. Please accept it or call the customer.',
        'red_title' => 'Order :number is late',
        'red_body' => 'Still not accepted after :time. Please deal with it now.',
    ],

    // Proof of delivery.
    'proof' => [
        'title' => 'Proof of delivery',
        'open' => 'View proof',
        'none' => 'No proof has been saved for this delivery.',
        'photo' => 'Delivery photo :n',
        'open_photo' => 'Open photo :n in a new tab',
        'received_by' => 'Received by',
        'delivered_at' => 'Photo taken',
        'driver' => 'Driver',
        'where' => 'Where the driver was',
        'open_map' => 'Open in map',
        'no_gps' => 'Not recorded',
        'accuracy' => 'about :m m',
        'failed_photo' => 'Failed attempt',
        'cod' => 'Cash on delivery',
        'cod_collected' => ':amount collected (:tender)',
        'cod_due' => ':amount to collect',
        'tender_cash' => 'cash',
        'tender_card_terminal' => 'card',
        'courier_open' => 'Open the courier\'s proof',
        'unavailable' => 'This proof is not available.',
    ],

    // Settings > Operations clock.
    'tab' => 'Operations clock',
    'tab_hint' => 'How long an order, a delivery, a pickup or cash may wait before the dashboard shows it as getting late (amber) and late (red). The branch steps count opening hours only, so a shop that is closed overnight does not start the morning late.',
    'setting_lt' => 'The "getting late" time must be lower than the "late" time.',
    'setting' => [
        'ops_clock__pending_amber_minutes' => 'New order: getting late after',
        'ops_clock__pending_red_minutes' => 'New order: late after',
        'ops_clock__preparing_amber_minutes' => 'Being prepared: getting late after',
        'ops_clock__preparing_red_minutes' => 'Being prepared: late after',
        'ops_clock__ready_amber_minutes' => 'Ready, no driver: getting late after',
        'ops_clock__ready_red_minutes' => 'Ready, no driver: late after',
        'ops_clock__driver_amber_minutes' => 'Driver has not accepted: getting late after',
        'ops_clock__driver_red_minutes' => 'Driver has not accepted: late after',
        'ops_clock__pickup_amber_days' => 'Pickup not collected: getting late after',
        'ops_clock__pickup_red_days' => 'Pickup not collected: late after',
        'ops_clock__cash_amber_hours' => 'Cash held by a driver: getting late after',
        'ops_clock__cash_red_hours' => 'Cash held by a driver: late after',
    ],
    'setting_hint' => [
        'ops_clock__pending_amber_minutes' => 'Minutes a new order may wait to be accepted before it turns amber. Opening hours only.',
        'ops_clock__pending_red_minutes' => 'Minutes before a new order that nobody accepted turns red, and the branch manager is reminded.',
        'ops_clock__preparing_amber_minutes' => 'Minutes from accepting an order until it must be ready. Furniture needs time, so this is longer.',
        'ops_clock__preparing_red_minutes' => 'Minutes before an order still being prepared turns red.',
        'ops_clock__ready_amber_minutes' => 'Minutes a delivery order may stay ready before a driver is named for it.',
        'ops_clock__ready_red_minutes' => 'Minutes before a ready delivery with no driver turns red.',
        'ops_clock__driver_amber_minutes' => 'Minutes a named driver may take to accept the delivery.',
        'ops_clock__driver_red_minutes' => 'Minutes before a driver who has not answered turns red.',
        'ops_clock__pickup_amber_days' => 'Days a ready pickup may wait for the customer. Real days, not opening hours.',
        'ops_clock__pickup_red_days' => 'Days before an uncollected pickup turns red.',
        'ops_clock__cash_amber_hours' => 'Hours a driver or cashier may hold cash before handing it in. Also used for refunds waiting for approval.',
        'ops_clock__cash_red_hours' => 'Hours before cash that was not handed in turns red.',
    ],
];
