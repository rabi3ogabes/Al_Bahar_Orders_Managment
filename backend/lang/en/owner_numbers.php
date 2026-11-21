<?php

/*
 | The owner's numbers: dashboard tiles, the system health card, the reports' notes, campaign sending and the audit log's window.
 | Kept in its own file so these words can change without touching the shared admin strings.
 */
return [
    // ── Dashboard: money and orders ─────────────────────────────────────────────
    'sales_today' => 'Sales today',
    'sales_today_note' => 'Orders placed today, not cancelled',
    'sales_today_hint' => 'Online orders count once payment is confirmed',
    'collected_today' => 'Collected today',
    'collected_today_note' => 'Cash and online payments received today',
    'collected_split' => 'Online :online · Cash and card :cash',
    'orders_today' => 'Orders today',
    'orders_today_note' => 'Placed today, not cancelled',
    'sales_chart_note' => 'Sales per day by the day the order was placed. Cancelled orders and online orders still waiting for payment are left out.',

    // ── Dashboard: stock ────────────────────────────────────────────────────────
    'low_stock_note' => 'Items with :n or fewer left (or at their own minimum)',
    'out_of_stock_hint' => ':count out of stock',

    // ── Dashboard: a person whose role has no numbers ───────────────────────────
    'welcome_title' => 'Welcome to your workspace',
    'welcome_hint' => 'The numbers on this page appear for the parts of the business your role looks after. Open any section from your menu to start.',
    'shortcuts' => 'Your shortcuts',

    // ── System health card ──────────────────────────────────────────────────────
    'health_title' => 'System health',
    'health_hint' => 'Is the work that runs by itself actually running? Only people who manage settings see this card.',
    'health_all_ok' => 'Everything is running',
    'health_attention' => 'Needs attention',
    'health_scheduler' => 'Scheduled tasks',
    'health_queue' => 'Background jobs',
    'health_never' => 'No run recorded yet',
    'health_last_run' => 'Last run :when',
    'health_took' => 'took :ms ms',
    'health_state' => [
        'ok' => 'On time',
        'running' => 'Running now',
        'overdue' => 'Overdue',
        'failed' => 'Last run failed',
        'never' => 'Not seen yet',
    ],
    'health_queue_state' => [
        'ok' => 'Working',
        'backlog' => 'Backlog: more than :max jobs are waiting',
        'failed_jobs' => 'Some jobs failed',
        'inline' => 'Jobs run straight away on this server (no queue)',
        'unavailable' => 'The queue cannot be read right now',
    ],
    'health_waiting' => ':count waiting',
    'health_failed_jobs' => ':count failed',
    'task' => [
        'orders:expire-unpaid' => 'Cancel unpaid online orders and free their stock',
        'inventory:expire-reservations' => 'Release held stock that ran out of time',
        'deals:refresh-feed' => 'Refresh deals on the home feed',
        'payments:reconcile-pending' => 'Confirm pending payments with the payment provider',
        'marketing:send-scheduled' => 'Send campaigns that are due',
        'auctions:tick' => 'Open and close auction lots',
        'journeys:tick' => 'Customer journeys and cart reminders',
        'messaging:dispatch-due' => 'Send scheduled customer messages',
        'alerts:process' => 'Tell customers when a product is back or cheaper',
        'alerts:prune' => 'Remove old product alerts',
        'rooms:ensure-home' => 'Put "Shop by room" on the home page',
        'loyalty:expire-points' => 'Expire old loyalty points',
        'drivers:prune-locations' => 'Tidy driver location history',
        'push:prune-devices' => 'Remove dead phone registrations',
        'banners:purge-deleted' => 'Empty the banner bin',
        'reports:send-owner-pack' => 'Send the weekly owner pack',
        'customers:refresh-value' => 'Work out what each customer is worth',
    ],

    // ── Reports ─────────────────────────────────────────────────────────────────
    'col_booked_sales' => 'Booked sales',
    'col_low_stock_default' => 'Default low-stock level',
    'col_low' => 'Low stock rows',
    'col_out' => 'Out of stock rows',
    'note_sales_summary' => 'Booked sales is the dashboard figure: the total of orders placed in the period that are not cancelled and not waiting for online payment, with delivery fees and tax. Net sales counts items only: item value, less discounts, less items cancelled after ordering, less refunds that the lines do not already explain (a cancellation or an item change is already in the lines, so it is not taken off twice).',
    'note_inventory_levels' => 'An item that a branch never held is not listed. Out of stock means nothing is available and the branch has held it before; low means :n or fewer left unless the item has its own minimum. The counts above cover every row, the list shows the emptiest 5,000.',
    'note_fast_slow_movers' => 'On hand is the total across the branches you can see.',
    'note_customers' => 'Lifetime value is what a customer spent with the branches you can see, each order counted once.',

    // ── Campaigns ───────────────────────────────────────────────────────────────
    'campaign_started' => 'Sending started. You can leave this page: the campaign shows its progress in the list.',
    'campaign_already_sending' => 'This campaign is already being sent by someone else, so it was not started again.',
    'campaign_status_partly_sent' => 'Partly sent',
    'campaign_progress_queued' => 'Waiting for a worker to pick it up. This page updates by itself.',
    'campaign_progress' => 'Sending: :done of :total customers done, :reached reached so far',
    'campaign_progress_unknown_total' => 'Sending: :done customers done, :reached reached so far',
    'campaign_resumed' => 'The sending stopped part-way and was picked up again automatically.',
    'campaign_partly' => 'Reached :reached of :total customers. :failed could not be sent: their messages errored, see the application log.',
    'campaign_note_all_failed' => 'The last attempt failed for every customer, so nothing was sent. See the application log, then send it again.',
    'campaign_note_switched_off' => 'It stopped part-way because campaign messages were switched off in Notification controls.',
    'campaign_partly_short' => 'Reached :reached customers; :failed could not be sent.',

    // ── Audit log ───────────────────────────────────────────────────────────────
    'audit_window_default' => 'Showing the last 30 days. Pick other dates to look further back.',
    'audit_more' => 'More than :count entries match. Narrow the dates or the search to see them all.',
    'audit_count_capped' => ':count+',
    'audit_showing' => 'Entries :from–:to of :total',
    'audit_prev' => 'Newer entries',
    'audit_next' => 'Older entries',
];
