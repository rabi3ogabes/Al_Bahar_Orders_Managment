<?php

/** What the messaging feature adds to the customers list and the customer's page: ticking customers, the cart and last-message filters, the "Last message" column. */
return [
    'filter_cart' => 'Cart',
    'cart_any' => 'Any cart',
    'cart_yes' => 'Has an order left in the cart',
    'cart_no' => 'Nothing in the cart',
    'filter_contact' => 'Last message',
    'contact_any' => 'Last message',
    'contact_never' => 'Never messaged',
    'contact_24h' => 'Not messaged for 24 hours',
    'contact_3d' => 'Not messaged for 3 days',
    'contact_7d' => 'Not messaged for 7 days',
    'contact_30d' => 'Not messaged for 30 days',
    'col_last_message' => 'Last message',
    'never' => 'Never',
    'select_page' => 'Select everyone on this page',
    'select_row' => 'Select :name',
    'send' => 'Send message',
    'send_to' => 'Send a message to :name',
    'selected' => 'selected',
    'clear' => 'Clear',
    'bulk_label' => 'Selected customers',
    'nothing_selected' => 'Tick at least one customer first.',

    // Used by the live part of the list (ticking never waits for the server)
    'js' => [
        'page_selected' => 'Everyone on this page is selected (:count).',
        'select_all' => 'Select all :total customers matching your filters',
        'all_selected' => 'All :total customers matching your filters are selected.',
        'some_selected' => ':count of :total customers matching your filters are selected.',
        'clear' => 'Clear selection',
        'too_many' => 'One send reaches up to :max customers.',
    ],
];
