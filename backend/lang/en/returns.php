<?php

/* Return and damage claims: the words the server itself says (API messages, notification fragments, the staff window). */
return [
    'type' => [
        'damaged' => 'Damaged on arrival',
        'wrong_item' => 'Wrong item',
        'missing_item' => 'Item missing from the box',
        'quality' => 'Quality problem',
        'not_as_described' => 'Quality problem',
        'changed_mind' => 'Changed my mind',
        'other' => 'Something else',
    ],
    // What happens next, as a sentence inside the "claim approved" message (one per way the goods come back).
    'how' => [
        'pickup' => 'We will arrange to collect the item from you.',
        'branch' => 'Please bring the item to :branch.',
        'none' => 'You do not need to send anything back. We are arranging your refund.',
    ],
    'note_prefix' => ' Message from the team: :note',
    'method' => [
        'pickup' => 'We collect it from the customer',
        'branch' => 'The customer brings it to the branch',
        'none' => 'Nothing comes back',
    ],
    'refund_method' => [
        'original' => 'to your original payment method',
        'manual_cash' => 'in cash',
        'manual_bank_transfer' => 'by bank transfer',
    ],
    'evidence_required' => 'Add a photo or write a short message.',
    'photo_required' => 'Please add at least one photo that shows the problem.',
    'photos_too_many' => 'You can add up to :max photos to one claim.',
    'one_branch' => 'These items come from different branches. Please report the items of each branch in a separate claim.',
    'note_too_long' => 'Please keep your message under :max characters.',
];
