<?php

/**
 * The driver's side of Live Operations Control: what the driver app is told by the server (errors, the payment line of a job) and the alerts to the
 * branch managers when a driver hands a job back, nobody takes it, or a driver goes quiet. Screen text of the app itself is in the app
 * (mobile/lib/ops/ops_strings.dart). The Arabic file (../ar/driver_day.php) carries the same keys.
 */
return [
    'errors' => [
        'jobs_in_progress' => '{1} You still have 1 job. Finish it or ask the branch to hand it back.|[2,*] You still have :count jobs. Finish them or ask the branch to hand them back.',
        'job_not_found' => 'This delivery is not in your list any more.',
    ],

    // The one-line payment note of a job.
    'payment' => [
        'cash' => 'Collect :amount in cash at the door.',
        'nothing' => 'Nothing to collect at the door.',
        'paid' => 'Already paid online. Nothing to collect.',
    ],

    // Why a driver handed a job back, as the branch reads it ("Omar cannot take it (:reason)").
    'reason' => [
        'too_far' => 'too far',
        'vehicle' => 'the vehicle does not suit it',
        'shift_ending' => 'his shift is ending',
        'other' => 'another reason',
        'timeout' => 'no answer in time',
        'off_duty' => 'went offline',
    ],

    // Alerts to the branch managers and dispatchers (push and inbox; the notification control is "Order still waiting").
    'push' => [
        'declined_title' => 'Order :number was handed back',
        'declined_body' => ':driver cannot take it (:reason). :next',
        'next_given' => 'It was given to :driver.',
        'next_waiting' => 'No other driver is on duty. It waits for the next driver who goes on duty.',
        'next_manual' => 'Please assign another driver.',
        'silent_title' => 'No driver took order :number',
        'silent_body' => '{1} The driver we tried did not answer. Please assign one.|[2,*] The :n drivers we tried did not answer. Please assign one.',
        'quiet_title' => ':driver went offline',
        'quiet_body' => '{0} No signal for :minutes minutes. He was set off duty.|{1} No signal for :minutes minutes. He still has 1 job with him.|[2,*] No signal for :minutes minutes. He still has :count jobs with him.',
    ],
];
