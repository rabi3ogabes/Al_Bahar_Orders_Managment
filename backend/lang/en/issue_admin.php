<?php

return [
    'title' => 'Issue log', 'hint' => 'Record every payment problem or bug you find in the platform or in any app, and follow it until it is solved.',
    'report' => 'Report an issue', 'report_hint' => 'Say what happened, where, and how serious it is. Add the order number if there is one.',
    'search' => 'Search by title, number or order', 'open_count' => 'Open issues', 'urgent_count' => 'High or critical, still open',
    'tab_active' => 'Open', 'tab_closed' => 'Closed', 'tab_all' => 'All', 'kind' => 'Type', 'app' => 'Where', 'severity' => 'How serious',
    'all_kinds' => 'All types', 'all_apps' => 'All apps', 'all_severities' => 'All levels',
    'kinds' => ['payment' => 'Payment problem', 'bug' => 'Bug', 'delivery' => 'Delivery problem', 'other' => 'Other'],
    'apps' => ['customer' => 'Customer app', 'driver' => 'Driver app', 'staff' => 'Branch staff app', 'dashboard' => 'Dashboard', 'storefront' => 'Online store', 'server' => 'Server / API'],
    'severities' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'],
    'ref' => 'No.', 'title_field' => 'Title', 'title_ph' => 'e.g. SADAD paid but the order stayed unpaid', 'details' => 'What happened', 'details_hint' => 'Steps, what you expected, what you saw, phone model and app version for app issues.',
    'order_number' => 'Order number (optional)', 'payment_ref' => 'SADAD transaction (optional)', 'save' => 'Save issue', 'saved' => 'Issue :ref saved.', 'updated' => 'Issue updated.',
    'reported' => 'Reported', 'linked' => 'Linked to', 'resolution' => 'How it was solved', 'resolution_ph' => 'What was done, or why it will not be fixed.', 'closed_by' => 'Closed by :name · :at',
    'do_investigating' => 'Start investigating', 'do_resolved' => 'Mark solved', 'do_wont_fix' => 'Won’t fix', 'do_open' => 'Reopen',
    'empty' => 'No issues here.', 'empty_hint' => 'When something goes wrong, report it so it is not forgotten.',
];
