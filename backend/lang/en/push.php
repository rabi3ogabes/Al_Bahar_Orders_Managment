<?php

return [
    'staff_title' => 'Al Bahar',
    // Short name of the stage the order is in (subtext of the customer's live order card).
    'step_0' => 'Placed', 'step_1' => 'Preparing', 'step_2' => 'On the way', 'step_3' => 'Delivered',
    'driver_new_job' => 'New delivery: order :n. Open the app to accept it.',
    'staff_ready' => 'Order :n is ready and waiting for a driver.',
    'staff_failed' => 'Delivery of order :n failed. Please follow up.',

    // The customer's live order notification (App\Domain\Orders\Services\OrderProgress).
    'progress' => [
        // Pickup orders name the third and fourth stage differently.
        'pickup_step_2' => 'Ready for pickup', 'pickup_step_3' => 'Collected',

        // Headline with an arrival window: :from and :to are times, :day is "tomorrow" or a date such as "Sat 14 Nov".
        'arrives' => 'Arrives at :from-:to',
        'arrives_on' => 'Arrives :day, :from-:to',
        'pickup_at' => 'Ready for pickup at :from-:to',
        'pickup_on' => 'Ready for pickup :day, :from-:to',
        'window' => ':from-:to',
        'tomorrow' => 'tomorrow',

        // Headline when there is no window to show.
        'title' => [
            'placed' => 'Order placed',
            'preparing' => 'Preparing your order',
            'on_the_way' => 'On the way',
            'ready' => 'Ready for pickup',
            'delivered' => 'Delivered',
            'collected' => 'Collected',
            'cancelled' => 'Order cancelled',
            'failed' => 'Delivery unsuccessful',
        ],

        // The status line under the headline.
        'body' => [
            'pending_payment' => 'Waiting for your payment.',
            'placed' => 'Your order has been placed.',
            'preparing' => 'Being prepared at :branch.',
            'driver_assigned' => ':driver has been assigned to your order.',
            'driver_accepted' => ':driver is heading to :branch to pick up your order.',
            'picked_up' => ':driver has picked up your order.',
            'out_for_delivery' => ':driver is on the way to you.',
            'out_for_delivery_anonymous' => 'Your order is out for delivery.',
            'ready' => 'Your order is ready for pickup at :branch.',
            'delivered' => 'Delivered. Enjoy, and thank you for shopping with Al Bahar.',
            'collected' => 'Collected. Thank you for shopping with Al Bahar.',
            'cancelled' => 'Your order was cancelled.',
            'failed' => 'We could not deliver your order. We will contact you shortly.',
            'cancellation_requested' => 'Your cancellation request is being reviewed.',
        ],

        // How the driver is named in the lines above.
        'driver_named' => ':name',
        'driver_unnamed' => 'Your driver',
    ],
];
