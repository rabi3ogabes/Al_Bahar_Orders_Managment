<?php

/* Substitution consent: what the customer is asked, and what the branch sees while it waits. */
return [
    'price_same' => 'same price',
    'price_less' => ':amount less',
    'more' => ' (and :n more)',
    'fallback_remove' => 'If we do not hear from you, we will remove it from your order and refund you.',
    'fallback_keep' => 'If we do not hear from you, we will keep the replacement at no extra cost.',

    // The branch: orders waiting for the customer.
    'waiting' => 'Waiting for the customer',
    'waiting_n' => '{1} 1 replacement waits for the customer\'s answer|[2,*] :count replacements wait for the customer\'s answer',
    'time_left' => ':time left',
    'time_up' => 'Time is up',
    'keep_preparing' => 'You can keep preparing the rest. This part can be marked ready once the customer answers or the time is up.',
    'detail' => ':customer · :item → :replacement',
    'attention_note' => 'If nobody answers, the shop setting decides: :fallback',
    'fallback_remove_short' => 'the item is removed and refunded',
    'fallback_keep_short' => 'the replacement is kept',
];
