<?php

/**
 * Delivery follow-ups (docs/guides/DELIVERY_FOLLOW_UPS.md): new times the customer can choose after a failed delivery, the proof of delivery the customer
 * sees, and the driver's "assembly done" answer. Plain words; the Arabic file (../ar/delivery_followup.php) carries the same keys.
 */
return [
    // Refusals the customer, the driver or the branch reads (the API sends them with a stable `code`).
    'errors' => [
        'offer_not_found' => 'We could not find that offer.',
        'offer_expired' => 'This offer has run out. We will call you to arrange a new time.',
        'offer_already_chosen' => 'A new time has already been chosen for this order.',
        'offer_closed' => 'This offer is no longer open. The branch has already arranged your delivery.',
        'offer_time_not_offered' => 'Please choose one of the times offered.',
        'offer_choose_times' => 'Choose between 1 and :max times.',
        'offer_needs_failed_delivery' => 'New times can only be offered for a delivery that could not be completed.',
        'offer_no_account' => 'This order has no customer account, so the customer cannot choose in the app. Please call them.',
        'offer_time_taken' => 'One of those times is no longer free. Please choose again.',
        'assembly_answer_required' => 'Please say whether the assembly was done before you confirm the delivery.',
        'assembly_answer_invalid' => 'Choose "done" or "not done".',
        'assembly_reason_required' => 'Choose why the assembly was not done (and write a short note for "Other").',
        'assembly_not_ordered' => 'The customer did not buy assembly for this order.',
        'assembly_nothing_to_handle' => 'There is no assembly problem to close on this order.',
    ],

    // Alerts to the branch team and the driver (order number and time only, never customer data).
    'alert' => [
        'chosen_title' => 'Order :number: new delivery time',
        'chosen_body' => 'The customer chose :time for order :number. The delivery is back in the queue.',
        'assembly_title' => 'Order :number: assembly not done',
        'assembly_body' => 'Order :number: :reason. Refund the assembly fee or book it again.',
    ],

    // Why a driver could not do the assembly (the driver app shows the same words).
    'assembly_reason' => [
        'customer_not_ready' => 'The customer was not ready',
        'missing_parts' => 'Parts or tools were missing',
        'not_possible' => 'It could not be done here',
        'no_time' => 'There was no time',
        'other' => 'Another reason',
    ],

    // One plain line on the order, the live board, the deliveries list and "Needs attention".
    'staff' => [
        'waiting' => 'Waiting for the customer to choose a new time (open until :until)',
        'waiting_to_tell' => 'New times ready: the customer is told at :at (open until :until)',
        'chosen' => 'Customer chose :time',
        'expired' => 'The customer did not choose in time. Call them or offer new times.',
        'none' => 'No free times to offer. Call the customer.',
    ],
    'attention' => [
        'label' => 'Assembly not done',
        'note' => 'The customer paid for assembly. Refund the fee or book it again, then mark it handled on the order.',
    ],

    'ui' => [
        'times_title' => 'New delivery time',
        'no_offer_yet' => 'The customer has not been offered new times. Use "Offer new times" or call them.',
        'offers_off' => 'Offers of new times are switched off (Settings > Checkout & fees). Call the customer or offer new times.',
        'offer_button' => 'Offer new times',
        'offer_title' => 'Offer new times',
        'offer_hint' => 'Pick up to five free delivery times. The customer is told and chooses in the app or on the web page; the offer stays open for :hours hours.',
        'places_left' => ':n left',
        'no_free_times' => 'There are no free delivery times in the next days. Call the customer.',
        'picked_n' => '{0} Pick at least one time|{1} 1 time picked|[2,*] :count of :max times picked',
        'offer_send' => 'Send to the customer',
        'offer_sent' => 'The customer has been offered the new times.',
        'assembly_title' => 'Assembly and installation',
        'assembly_pending' => 'Paid by the customer. The driver has not answered yet.',
        'assembly_done' => 'Done on :at',
        'assembly_not_done' => 'Not done: :reason',
        'assembly_refund_hint' => 'Refund the fee (Finance > Refunds) or book it again, then mark it handled.',
        'assembly_handled_at' => 'handled on :at',
        'assembly_handle' => 'Mark as handled',
        'assembly_handled' => 'Marked as handled.',
        'assembly_photo' => 'Assembly',
    ],

    // Settings > Checkout & fees.
    'setting' => [
        'slot_offers_enabled' => 'Offer new times after a failed delivery',
        'slot_offer_count' => 'How many times to offer',
        'slot_offer_hours' => 'How long an offer stays open',
        'proof_for_customer' => 'Show the proof of delivery to customers',
        'assembly_answer_required' => 'Driver must answer for paid assembly',
    ],
    'setting_hint' => [
        'slot_offers_enabled' => 'When on, a customer whose delivery failed because they were not there, could not be reached, gave a wrong address or the driver had no access is offered a few free delivery times to choose in the app or on the web page, instead of waiting for a phone call. When off, or when the branch has no free time, nothing changes: the branch calls the customer. The branch can always offer times by hand from the order.',
        'slot_offer_count' => 'The number of free delivery times offered automatically, from 1 to 5. The earliest free time of each day comes first.',
        'slot_offer_hours' => 'After this time the offer closes and the order goes back to "call the customer". The time starts when the customer is told (at 08:00 when the offer is made at night). From 1 to 336 hours.',
        'proof_for_customer' => 'When on, the customer sees on their delivered order when it was delivered, who received it and the photo the driver took. The photo is private and its link works for ten minutes. When off, they still see when it was delivered and who received it, but not the photo.',
        'assembly_answer_required' => 'When on, a driver cannot confirm a delivery that includes paid assembly before he says whether the assembly was done (or why not). Switch it off only while some drivers still use an older app.',
    ],
];
