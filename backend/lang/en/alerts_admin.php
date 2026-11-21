<?php

// Dashboard words for back-in-stock and price-drop alerts (docs/guides/ALERTS_AFTER_DELIVERY_AND_ROOMS.md). The settings are under Settings > Notifications (lang/*/org_admin.php).
return [
    'waiting_stock' => '{1} 1 customer is waiting for it to be back|[2,*] :n customers are waiting for it to be back',
    'waiting_stock_hint' => 'Customers who pressed "Notify me" for this product while it was out of stock. Each customer counts once. They get one message when it can be bought again.',
    'waiting_price' => '{1} 1 customer watches the price|[2,*] :n customers watch the price',
    'waiting_price_hint' => 'Customers who asked to be told if the price drops. Each customer counts once.',
];
