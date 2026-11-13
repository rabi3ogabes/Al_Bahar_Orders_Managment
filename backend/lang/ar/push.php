<?php

return [
    'staff_title' => 'البحر',
    // Short name of the stage the order is in (subtext of the customer's live order card).
    'step_0' => 'تم الطلب', 'step_1' => 'قيد التجهيز', 'step_2' => 'في الطريق', 'step_3' => 'تم التسليم',
    'driver_new_job' => 'توصيل جديد: الطلب :n. افتح التطبيق لقبوله.',
    'staff_ready' => 'الطلب :n جاهز وبانتظار سائق.',
    'staff_failed' => 'فشل توصيل الطلب :n. يرجى المتابعة.',

    // The customer's live order notification (App\Domain\Orders\Services\OrderProgress).
    'progress' => [
        // Pickup orders name the third and fourth stage differently.
        'pickup_step_2' => 'جاهز للاستلام', 'pickup_step_3' => 'تم الاستلام',

        // Headline with an arrival window: :from and :to are times, :day is "tomorrow" or a date such as "Sat 14 Nov".
        'arrives' => 'تصل بين :from و :to',
        'arrives_on' => 'تصل :day بين :from و :to',
        'pickup_at' => 'جاهز للاستلام بين :from و :to',
        'pickup_on' => 'جاهز للاستلام :day بين :from و :to',
        'window' => ':from - :to',
        'tomorrow' => 'غداً',

        // Headline when there is no window to show.
        'title' => [
            'placed' => 'تم استلام طلبك',
            'preparing' => 'نقوم بتجهيز طلبك',
            'on_the_way' => 'في الطريق إليك',
            'ready' => 'جاهز للاستلام',
            'delivered' => 'تم التسليم',
            'collected' => 'تم الاستلام',
            'cancelled' => 'تم إلغاء الطلب',
            'failed' => 'تعذر التسليم',
        ],

        // The status line under the headline.
        'body' => [
            'pending_payment' => 'بانتظار الدفع.',
            'placed' => 'تم استلام طلبك.',
            'preparing' => 'قيد التجهيز في :branch.',
            'driver_assigned' => 'تم تعيين :driver لطلبك.',
            'driver_accepted' => ':driver في طريقه إلى :branch لاستلام طلبك.',
            'picked_up' => 'استلم :driver طلبك.',
            'out_for_delivery' => ':driver في الطريق إليك.',
            'out_for_delivery_anonymous' => 'طلبك في الطريق إليك.',
            'ready' => 'طلبك جاهز للاستلام من :branch.',
            'delivered' => 'تم التسليم. نتمنى لك التمتع، وشكراً لتسوقك من البحر.',
            'collected' => 'تم الاستلام. شكراً لتسوقك من البحر.',
            'cancelled' => 'تم إلغاء طلبك.',
            'failed' => 'تعذر توصيل طلبك. سنتواصل معك قريباً.',
            'cancellation_requested' => 'طلب الإلغاء قيد المراجعة.',
        ],

        // How the driver is named in the lines above.
        'driver_named' => 'السائق :name',
        'driver_unnamed' => 'السائق',
    ],
];
