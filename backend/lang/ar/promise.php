<?php

// موعد التوصيل والاستلام الذي تعرضه صفحة المنتج والسلة والدفع (docs/guides/DELIVERY_PROMISE_ASSEMBLY_AND_SHARING.md).
// :day = «اليوم» أو «غداً» أو اليوم والتاريخ («السبت 22 نوفمبر»)، و:time = «9 م» أو «4:15 م».
return [
    'on_date' => 'يوم :date',
    'today' => 'اليوم',
    'tomorrow' => 'غداً',
    'delivery_slot' => 'التوصيل :day، من :from إلى :to',
    'delivery_day' => 'التوصيل :day',
    'delivery_today_before' => 'التوصيل اليوم قبل :time',
    'delivery_today' => 'التوصيل اليوم',
    'pickup_slot' => 'الاستلام :day، من :from إلى :to',
    'pickup_day' => 'الاستلام :day من :time',
    'pickup_today' => 'الاستلام اليوم من :time',
    'order_by' => 'إذا طلبت قبل :time',
    'all_delivery_by' => 'يصل الطلب كاملاً :day',
    'all_pickup_by' => 'يكون الطلب كاملاً جاهزاً للاستلام :day',
    'partial_delivery' => 'يصل جزء من طلبك :day وسنؤكد لك موعد الباقي.',
    'partial_pickup' => 'يكون جزء من طلبك جاهزاً :day وسنؤكد لك موعد الباقي.',
    'unconfirmed_delivery' => 'سنؤكد لك موعد التوصيل',
    'unconfirmed_pickup' => 'سنؤكد لك موعد الاستلام',
    'address_hint' => 'الموعد الدقيق يعتمد على عنوانك.',
    'reason' => [
        'out_of_stock' => 'غير متوفر حالياً.',
        'item_unavailable' => 'أحد المنتجات غير متاح حالياً.',
        'branch_closed' => ':branch مغلق حالياً.',
        'not_offered' => ':branch لا يقدّم هذه الخدمة.',
        'no_date' => 'لا يوجد موعد متاح بعد.',
    ],
];
