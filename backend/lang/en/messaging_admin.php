<?php

/** Shared wording of the customer messaging feature (composer, history, customer timeline) and its error messages. */
return [
    'custom_template' => 'Custom message',

    'kind' => ['reminder' => 'Order reminder', 'offer' => 'Offer', 'custom' => 'Custom message'],
    'channel' => ['notification' => 'Notification', 'whatsapp' => 'WhatsApp'],
    'source' => ['manual' => 'Sent by the team', 'journey' => 'Automatic journey'],
    'status' => ['queued' => 'Waiting', 'sent' => 'Sent', 'delivered' => 'Delivered', 'failed' => 'Failed', 'skipped' => 'Not sent', 'cancelled' => 'Cancelled'],
    'batch_status' => ['scheduled' => 'Scheduled', 'sending' => 'Sending', 'done' => 'Done', 'cancelled' => 'Cancelled'],

    'reason' => [
        'inactive_user' => 'Account is not active',
        'no_marketing_consent' => 'Has not agreed to marketing messages',
        'opted_out' => 'Turned this channel off',
        'no_whatsapp_optin' => 'Has not opted in to WhatsApp',
        'no_phone' => 'No phone number',
        'recently_contacted' => 'Already messaged recently',
        'no_open_cart' => 'No order left in the cart',
        'no_devices' => 'No phone to notify',
        'push_not_configured' => 'Push notifications are not set up',
        'push_rejected' => 'The phone did not accept it',
        'whatsapp_not_configured' => 'WhatsApp is not connected',
        'cancelled_by_staff' => 'Cancelled by the team',
        'coupon_failed' => 'The coupon could not be created',
        'send_error' => 'Something went wrong while sending',
        'job_failed' => 'Could not be sent after several tries',
        'provider_failed' => 'WhatsApp could not deliver it',
        'n8n_http' => 'The WhatsApp automation did not accept it (code :code)',
    ],

    'placeholder' => [
        'name' => 'First name',
        'coupon' => 'Coupon code',
        'expires' => 'Coupon expiry',
        'items' => 'Items in cart',
        'first_item' => 'First item in cart',
        'app_link' => 'Store link',
    ],

    'field' => [
        'title_en' => 'Title (English)', 'title_ar' => 'Title (Arabic)',
        'body_en' => 'Message (English)', 'body_ar' => 'Message (Arabic)',
        'whatsapp_en' => 'WhatsApp text (English)', 'whatsapp_ar' => 'WhatsApp text (Arabic)',
        'deep_link' => 'Opens in the app', 'promotion' => 'Offer', 'coupon_prefix' => 'Coupon prefix', 'coupon_hours' => 'Coupon valid for (hours)',
    ],

    'err' => [
        'unknown_placeholder' => 'Unknown placeholder: :list. Use the buttons to insert one.',
        'coupon_unused' => 'The text uses {{coupon}} but no coupon is attached. Turn on "Add a personal coupon" or remove it from the text.',
        'coupon_not_shown' => 'A coupon is attached but the text never shows it. Add {{coupon}} so customers can see the code.',
        'no_channel' => 'Choose at least one way to send: notification or WhatsApp.',
        'bad_kind' => 'Choose what kind of message this is.',
        'no_recipients' => 'No customers are selected, or you cannot message them.',
        'too_many' => 'One send can reach at most :max customers and you selected :count. Narrow the filters or send in parts.',
        'whatsapp_off' => 'WhatsApp is not connected yet. Turn on the n8n automation in Settings → Integrations, or send as a notification only.',
        'coupon_forbidden' => 'You need marketing access to attach a coupon.',
        'promotion_inactive' => 'That offer is switched off or has ended. Pick an active one.',
        'schedule_past' => 'Pick a time in the future.',
        'schedule_far' => 'You can schedule at most 60 days ahead.',
        'finished' => 'This send has already finished.',
        'not_retryable' => 'Only a finished or running send can be retried.',
        'rate_limited' => 'You are sending too fast. Try again in :seconds seconds.',
        'override_cap' => '"Send anyway" has already been used :max times for this customer in the last hour. Try again in about :minutes minutes.',
        'busy' => 'This message is already being sent. Check the history in a moment.',
    ],
];
