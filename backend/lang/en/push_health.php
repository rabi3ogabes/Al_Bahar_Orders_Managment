<?php

return [
    'health' => [
        'title' => 'Push health',
        'hint' => 'One card per phone app: what is saved here, what the phones themselves report, and what to fix first.',
        'level' => ['ok' => 'Ready', 'warn' => 'Check', 'fail' => 'Needs a fix', 'off' => 'Not set up'],
        'never_tested' => 'The server key has not been tested yet: press Test connection in the Firebase settings below.',
        'tested_at' => 'Server key tested :when',
        'package' => 'Package',
        'app_id' => 'App ID',
        'not_saved' => 'Not saved',
        'devices' => ':n device(s) registered',
        'phones' => ':n phone(s) reported success',
        'last_registered' => 'Last registration :when',
        'no_phone_yet' => 'No phone has reported yet',
        'open_to_report' => 'Open the app once on a phone that has the newest build: it tells this page what it could and could not do.',
        'shared_key' => 'All three Android apps use the one Android API key. If you limited that key in Google Cloud ("Application restrictions"), every package must be listed in it, each with the SHA-1 of the key that signs the app.',
        'duplicate' => [
            'title' => 'Two apps share one App ID',
            'body' => 'Every phone app needs its own App ID: register each package as a separate Android app in Firebase, then copy that app\'s own App ID here. With a shared ID only one of the apps can register, and Google can refuse the others. Fix the fields marked below, or use "Fill from google-services.json".',
        ],
        'duplicate_field' => 'This App ID is also used by: :others. Each app needs its own.',
        'web' => ['title' => 'Web push', 'ok' => 'Browsers can register', 'fail' => 'Fill in the web settings and the Web Push key'],
    ],

    'check' => [
        'server' => ['ok' => 'The server key is saved and push is switched on.', 'fail' => 'Push is off or the server key is missing: nothing can be sent. Add the key and switch push on first.'],
        'app_id' => [
            'ok' => 'The App ID looks right.',
            'fail' => [
                'missing' => 'No App ID saved for this app: the app cannot start Firebase. Add it below.',
                'format' => 'The App ID does not look like Firebase\'s: it must read 1:<number>:<android or ios>:<letters and digits>.',
            ],
        ],
        'api_key' => [
            'ok' => 'The API key looks right.',
            'fail' => [
                'missing' => 'No API key saved for this platform: the app cannot start Firebase. Add it below.',
                'format' => 'The API key must start with AIza and be 39 characters long.',
            ],
        ],
        'project_number' => [
            'ok' => 'The project number matches.',
            'fail' => 'This App ID belongs to project number :number, but the other settings use :sender. All apps must come from the same Firebase project.',
        ],
        'unique' => [
            'ok' => 'This app has its own App ID.',
            'fail' => 'The same App ID is saved for :others. Each app needs its own App ID, so register each package as its own app in Firebase.',
        ],
        'phones' => [
            'ok' => ':phones phone(s) registered with this app.',
            'warn' => 'No phone has reported from this app yet. Open it on a phone with the newest build and look here again.',
            'fail' => 'Phones report a problem (see "What the phones report" below) and none has registered yet.',
            'warn_with_phones' => 'Some phones registered, but others report a problem (see below).',
        ],
    ],

    'reports' => [
        'title' => 'What the phones report',
        'hint' => 'Each phone app tells the server when it cannot start push or register, and when it succeeds. Nothing personal is sent: no name, number, key or token. The last 7 days, newest first.',
        'empty' => 'No problem reported in the last 7 days.',
        'none_at_all' => 'No phone has reported anything yet. Open the app on a phone with the newest build: after a few seconds it reports here.',
        'phones' => ':n phone(s)',
        'times' => ':n report(s)',
        'last_seen' => 'Last seen :when',
        'what' => 'What it means',
        'fix' => 'What to do',
        'said' => 'The phone said',
        'registered_title' => 'Phones that registered',
        'registered_line' => ':phones phone(s), last at :when',
        'registered_none' => 'No phone yet',
        'stage' => [
            'options' => 'Reading the settings from the server',
            'init' => 'Starting Firebase',
            'permission' => 'Notification permission',
            'token' => 'Getting the push token from Firebase',
            'register' => 'Sending the token to the server',
            'registered' => 'Registered',
        ],
        'app' => ['customer' => 'Customer app', 'driver' => 'Driver app', 'staff' => 'Staff app'],
        'platform' => ['android' => 'Android', 'ios' => 'iPhone'],
        'resolved_note' => 'A phone that reported a problem and then registered fine is not counted.',
    ],

    'codes' => [
        'unknown' => [
            'what' => 'A problem this page does not know yet.',
            'fix' => 'Read the phone\'s own message below. If it names an API, a key or an app ID, check that item in the Firebase console and in Google Cloud (APIs & Services).',
        ],
        'server_unreachable' => [
            'what' => 'The phone could not reach this server to read the Firebase settings (no internet, or the answer took too long).',
            'fix' => 'Nothing to change here if it is only a few phones: the app tries again by itself. If every phone reports it, check that /api/v1/push-config answers (the site and its proxy are up).',
        ],
        'push_off' => [
            'what' => 'The server told the phone that push is switched off, so it did not start Firebase.',
            'fix' => 'Save the server key (Firebase form below) and turn the "Push notifications" switch at the top on.',
        ],
        'app_not_set' => [
            'what' => 'This app has no App ID or API key saved here, so it has nothing to start Firebase with.',
            'fix' => 'Fill "Android app ID" for this app and the "Android API key" (iPhone: the iPhone ones) in "Phone apps" below, then press Save changes.',
        ],
        'options_invalid' => [
            'what' => 'The server sent the app Firebase settings that are incomplete.',
            'fix' => 'Check the project ID, the API key and the App ID of this app in "Phone apps" below and save again.',
        ],
        'init_failed' => [
            'what' => 'Firebase would not start with the saved settings.',
            'fix' => 'Check that the API key and this app\'s App ID were copied whole, from the same Firebase project, and read the phone\'s message below.',
        ],
        'invalid_app_id' => [
            'what' => 'Firebase does not accept the App ID saved for this app.',
            'fix' => 'Firebase console, Project settings, General, Your apps: pick the app whose package name is the one shown on its card here, copy its own App ID into the matching field, and save. Do not reuse another app\'s App ID.',
        ],
        'api_key_invalid' => [
            'what' => 'Google says the Android API key is not valid for this project.',
            'fix' => 'Copy current_key again from google-services.json (Firebase, Project settings, Your apps) into "Android API key" and save. Make sure it is not the Web or iPhone key.',
        ],
        'api_key_blocked' => [
            'what' => 'Google refused the phone\'s request because the API key does not allow this app.',
            'fix' => 'Google Cloud console, APIs & Services, Credentials: open the Android API key. Under "Application restrictions" choose None, or choose Android apps and add all three packages (qa.albahar.customer, qa.albahar.driver, qa.albahar.staff), each with the SHA-1 of the key that signs the build. Save and wait a few minutes. Also make sure each package is registered as its own Android app in Firebase.',
        ],
        'api_disabled' => [
            'what' => 'A Google service the phone needs is switched off in this project (Firebase Installations API or Firebase Cloud Messaging Registration API), or the API key is not allowed to use it.',
            'fix' => 'Google Cloud console, APIs & Services, Library: search "Firebase Installations API" and "FCM Registration API" and press Enable on each. Then Credentials, the Android API key, "API restrictions": choose "Don\'t restrict key", or include both.',
        ],
        'fis_auth_error' => [
            'what' => 'Firebase refused to create this install (authentication failed). The API key or the App ID does not belong to this app.',
            'fix' => 'Check the App ID of this app against Firebase console, Project settings, Your apps, and check the API key restrictions in Google Cloud (see "API key blocked").',
        ],
        'invalid_argument' => [
            'what' => 'Firebase rejected the request: one of the saved values (API key, App ID, project) is malformed or from another project.',
            'fix' => 'Copy the API key and this app\'s App ID again from google-services.json (or use "Fill from google-services.json") and save.',
        ],
        'service_not_available' => [
            'what' => 'The phone could not reach Firebase (no internet, Google Play services missing or old, or battery saver).',
            'fix' => 'Nothing to change here if it is one phone: try Wi-Fi and update Google Play services. If every phone reports it, check that "Firebase Installations API" and "FCM Registration API" are enabled and the API key is not restricted (see "A Google service is switched off").',
        ],
        'timeout' => [
            'what' => 'The phone asked Firebase for its push token and got no answer within 20 seconds.',
            'fix' => 'Often a slow network: the app tries again. If every phone times out, the key or the Google APIs are blocking it: see "API key blocked" and "A Google service is switched off".',
        ],
        'no_token' => [
            'what' => 'Firebase started but gave no push token.',
            'fix' => 'Same causes as a time-out: the network, the API key restriction, or a Google API that is off. Check those in Google Cloud.',
        ],
        'token_failed' => [
            'what' => 'Firebase could not give the phone a push token.',
            'fix' => 'Read the phone\'s message below. The usual causes are the API key restriction and a Google API that is switched off in Google Cloud.',
        ],
        'too_many_registrations' => [
            'what' => 'This phone registered with Firebase too many times in a short while.',
            'fix' => 'Wait a while, or clear the app\'s data or reinstall it. Not a server setting.',
        ],
        'apns_missing' => [
            'what' => 'The iPhone has no Apple push token yet, so Firebase cannot give it one.',
            'fix' => 'Upload the APNs key in Firebase (Project settings, Cloud Messaging, Apple app configuration), and make sure the build has the Push Notifications capability. Test on a real iPhone, not the simulator.',
        ],
        'permission_denied' => [
            'what' => 'Notifications are switched off for this app on the phone.',
            'fix' => 'On the phone: Settings, Apps, this app, Notifications, Allow. The customer app also asks politely after the first order.',
        ],
        'permission_not_asked' => [
            'what' => 'The customer has not been asked to allow notifications yet, so a push can arrive and stay silent.',
            'fix' => 'Nothing to change: the customer app asks right after the first order (Android 13 and newer). To test earlier, allow notifications for the app in the phone settings.',
        ],
        'no_network' => [
            'what' => 'The phone had no internet at that moment.',
            'fix' => 'Nothing to do: the app tries again when it is online.',
        ],
        'server_error' => [
            'what' => 'The phone got its push token but this server failed while saving it.',
            'fix' => 'Look at the server error log around the time shown (POST /api/v1/me/devices or /api/v1/admin/devices).',
        ],
        'rejected' => [
            'what' => 'The phone got its push token but the server refused to save it (the request was not accepted).',
            'fix' => 'The app and the server may be on different versions: update the app, and check the server log for the validation error.',
        ],
        'unauthorized' => [
            'what' => 'The phone got its push token but its sign-in had just expired, so the server did not save it.',
            'fix' => 'Nothing to do: it registers again after the next sign-in.',
        ],
        'register_failed' => [
            'what' => 'The phone got its push token but could not send it to the server.',
            'fix' => 'Read the phone\'s message below; check the server is reachable from the phone.',
        ],
        'ok' => [
            'what' => 'The phone registered its push token with the server.',
            'fix' => 'Nothing to do. Use "Send a test notification" below to see it arrive.',
        ],
    ],

    'fcm' => [
        'ok' => 'Firebase accepted the message (it is on its way to the phone). If the phone shows nothing: make sure notifications are allowed for the app in the phone\'s settings, and that the phone is online.',
        'not_ready' => 'Push is not ready: add the server key and switch push on first.',
        'unregistered' => 'Firebase says this phone\'s token is no longer valid (the app was removed or its data cleared). The old registration was switched off. Open the app on the phone once so it registers again, then test again.',
        'sender_mismatch' => 'The token on this phone belongs to another Firebase project. The App ID saved for this app comes from a different project than the server key. Use apps and a key from the same project.',
        'apns_key' => 'Firebase has no valid Apple push key for this iPhone. Upload the APNs authentication key in Firebase (Project settings, Cloud Messaging, Apple app configuration).',
        'rate_limited' => 'Firebase says there were too many messages. Wait a minute and try again.',
        'unauthenticated' => 'Firebase did not accept the server key. Use Test connection above; download a fresh key if needed.',
        'permission_denied' => 'The server key is not allowed to send messages in this project, or the Firebase Cloud Messaging API (V1) is switched off. Use Test connection above to see which.',
        'not_found' => 'Firebase does not know this project or this token. Check the Project ID, and that the phone\'s App ID comes from the same project.',
        'invalid_argument' => 'Firebase refused the message or the token as malformed. If this phone registered with an old build, open the app so it registers again.',
        'unreachable' => 'The server could not reach Firebase. Check the server\'s internet access and try again.',
        'fcm_down' => 'Firebase is having trouble right now. Try again in a few minutes.',
        'other' => 'Firebase refused the message. The technical answer is shown beside this line.',
    ],

    'test' => [
        'title' => 'Test notification',
        'body' => 'Push works on this phone. :time',
        'card_step' => 'Preparing',
        'card_title' => 'Test order card',
        'card_body' => 'This is a sample of the live order notification. No real order is behind it.',
        'section' => 'Send a test to a phone',
        'hint' => 'Pick a phone that has registered and send it a real push through Firebase. You see Firebase\'s answer in plain words.',
        'target' => 'Send to',
        'mine' => 'My own devices (:n)',
        'mine_none' => 'My own devices (none registered)',
        'others' => 'Latest phones',
        'also_card' => 'Also send the sample live order card',
        'send' => 'Send test',
        'sending' => 'Sending…',
        'no_devices' => 'No phone has registered yet, so there is nobody to send to. Open the app on a phone and look at "What the phones report" above: it says what stops the registration.',
        'pick_one' => 'Pick a phone first.',
        'results' => 'Result',
        'card_ok' => 'The sample order card was sent too.',
        'card_failed' => 'The sample order card could not be sent (HTTP :status :code).',
        'technical' => 'Firebase: HTTP :status :code',
        'sent_n' => 'Test sent to :ok of :total device(s).',
        'outcome' => ['sent' => 'Delivered to Firebase', 'removed' => 'Old registration removed', 'failed' => 'Failed'],
    ],

    'target' => [
        'android' => 'Android',
        'ios' => 'iPhone',
        'web' => 'Browser',
        'app' => ['customer' => 'Customer app', 'driver' => 'Driver app', 'staff' => 'Staff app', 'store' => 'Web store', 'mobile-web' => 'Web app', 'admin' => 'Dashboard'],
    ],

    'import' => [
        'title' => 'Fill from google-services.json',
        'hint' => 'Firebase gives one google-services.json for the whole project once the three Android apps are registered. Upload it and the three App IDs and the Android API key are filled in by package name. Check them, then press Save changes.',
        'button' => 'Upload google-services.json',
        'filled' => 'Filled: :apps.',
        'missing' => 'Not in the file: :packages. Register those packages as Android apps in Firebase, download the file again and upload it.',
        'key' => 'the Android API key',
        'bad_file' => 'This is not a google-services.json file. Download it from Firebase console, Project settings, Your apps, the Android app.',
        'other_project' => 'The file belongs to another project (number :number) than the settings saved here (:expected). Use the file from the same Firebase project.',
        'none' => 'None of the three packages (qa.albahar.customer, qa.albahar.driver, qa.albahar.staff) is in this file.',
    ],
];
