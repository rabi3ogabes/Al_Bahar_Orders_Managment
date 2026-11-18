<?php

/**
 * "Can a push be seen?" on the customer messaging screens. Google accepting a push only means it reached the phone: Android 13+ hides it until
 * the person allows notifications, and the phone tells us which it is. Used by the message window, the customer's message list and the history.
 */
return [
    // The "Phones" line of one customer (the count is the number of phones; "unknown" = an older app that never reports its setting)
    'label' => 'Phones',
    'line' => [
        'none' => 'No phone registered',
        'on' => '{1} 1 phone · notifications on|[2,*] :count phones · notifications on',
        'on_some' => '[2,*] :count phones · a push shows on :on of them',
        'unknown' => '{1} 1 phone · notification setting not reported yet|[2,*] :count phones · notification setting not reported yet',
        'off' => '{1} 1 phone · notifications off: a push will not show, the inbox message still arrives|[2,*] :count phones · notifications off: a push will not show, the inbox message still arrives',
        'not_asked' => '{1} 1 phone · not asked yet: a push will not show until the customer allows notifications, the inbox message still arrives|[2,*] :count phones · not asked yet: a push will not show until the customer allows notifications, the inbox message still arrives',
    ],

    // The warning on the notification option when no phone can show a push (the message still reaches the app inbox)
    'warn' => [
        'none_title' => 'No phone to push to',
        'none' => ':name has no phone registered, so there is nothing to push to. The message will still arrive in the app inbox.',
        'off_title' => 'A push will not show',
        'off' => ':name has notifications turned off, so the phone keeps a push hidden. The message will still arrive in the app inbox. To fix it, ask the customer to allow notifications in the app: Settings → Notifications.',
        'not_asked_title' => 'A push will not show yet',
        'not_asked' => ':name has not been asked to allow notifications yet, so the phone keeps a push hidden. The message will still arrive in the app inbox. To fix it, ask the customer to allow notifications in the app: Settings → Notifications.',
    ],

    // Bulk send: what the phones of the customers who will get the notification can do
    'bulk' => [
        'title' => 'Push on phones',
        'can_show' => '{1} 1 customer can see a push|[2,*] :count customers can see a push',
        'silent' => '{1} 1 has notifications off or not asked yet|[2,*] :count have notifications off or not asked yet',
        'none' => '{1} 1 has no phone registered|[2,*] :count have no phone registered',
        'unknown' => '{1} 1 has not reported the notification setting yet|[2,*] :count have not reported the notification setting yet',
        'hint' => 'Everyone who gets the notification also finds it in the app inbox. A push only shows on phones that allow notifications.',
    ],

    // A message that went out (history rows and the customer's message list)
    'note' => [
        'silent' => '{1} Reached 1 phone, notifications off on :silent|[2,*] Reached :count phones, notifications off on :silent',
        'no_phone' => 'No phone registered',
        'hint' => 'Google accepted the push, but a phone keeps it hidden until notifications are allowed. The inbox message still arrives.',
        'no_phone_hint' => 'There was no phone to push to. The message is in the customer\'s app inbox.',
        'rejected' => 'Google refused the push',
        'rejected_hint' => 'Google did not accept the push for any of the customer\'s phones (:error). The message is in the app inbox. The phone registers again when the customer opens the app.',
        'customer_off' => 'Customer switched push off',
        'customer_off_hint' => 'The customer turned off push for offers and news in the app, so no push was sent. The message is in the app inbox.',
        'not_set_up' => 'Push is not set up',
        'not_set_up_hint' => 'Push notifications were not switched on when this went out, so only the app inbox message was sent.',
    ],
];
