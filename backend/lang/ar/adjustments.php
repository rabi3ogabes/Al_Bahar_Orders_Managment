<?php

/** عبارات يقرؤها العميل عندما يغيّر الفرع محتويات طلبه (الإشعار وصفحة الطلب والفاتورة). */
return [
    'reason' => [
        'out_of_stock' => 'نفد من المخزون',
        'not_found' => 'لم يُعثر عليه في الفرع',
        'damaged' => 'تالف',
        'quality' => 'لم يجتز فحص الجودة',
        'substitute_declined' => 'لم تقبل البديل',
        'substitute_expired' => 'لم يصل رد بشأن البديل في الوقت المحدد',
        'other' => 'غير متوفر',
    ],
    'line' => [
        'reduce' => ':name: من :from إلى :to',
        'remove' => 'تمت إزالة :name',
        'replace' => 'تم استبدال :name بـ :new',
        'replace_qty' => 'تم استبدال :name بـ :new (× :qty)',
    ],
    'outcome' => [
        'total' => ' الإجمالي الجديد: :total.',
        'refund' => ' تم طلب استرداد :amount للفرق.',
        'cancelled' => ' تم إلغاء طلبك وسيُسترد أي مبلغ مدفوع.',
    ],
    'kind' => [
        'reduced' => 'تم تقليل الكمية',
        'removed' => 'تمت الإزالة',
        'replaced' => 'تم الاستبدال',
        'replacement' => 'بديل',
    ],
];
