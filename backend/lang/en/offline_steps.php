<?php

/**
 * The driver's steps that his app sends later, from its outbox (docs/guides/OFFLINE_DRIVER_AND_STAFF_APPS.md). Plain words: the driver reads them on the
 * job card and in the list of updates waiting to send. The Arabic file (../ar/offline_steps.php) carries the same keys.
 */
return [
    'errors' => [
        'acted_at_invalid' => 'The time of this update could not be read. Update the app, then send it again.',
        'acted_at_in_future' => 'The phone\'s clock is ahead of the real time. Set the date and time to automatic.',
        'acted_at_too_old' => 'This update is more than :hours hours old, so it was not recorded. Call the branch so they can record it.',
        'idempotency_conflict' => 'This update was already sent with different details. Nothing was changed.',
    ],
];
