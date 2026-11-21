<?php

// Dashboard words for "Shop by room" (Content > Rooms). See docs/guides/ALERTS_AFTER_DELIVERY_AND_ROOMS.md.
return [
    'hint' => 'The rooms of "Shop by room": big tiles on the home page and a room filter in the shop. A room shows the products of its categories and the products that carry one of its tags.',
    'new' => 'New room', 'edit' => 'Edit room', 'empty' => 'No rooms yet', 'empty_hint' => 'Add a room such as Bedroom or Kitchen, and choose the categories that belong to it.',
    'off' => 'Hidden', 'no_products' => 'No products yet',
    'hidden_hint' => 'Customers do not see a room that has no products. Add a category to it, or put one of its tags on a product (Catalogue > Products > Tags).',
    'n_products' => '{0} No products|{1} 1 product|[2,*] :n products',
    'n_categories' => '{0} No categories|{1} 1 category|[2,*] :n categories',
    'show' => 'Show this room', 'show_hint' => 'Switched off, the room is not on the home page, in the shop filter or in the sitemap. The page address still exists for people who have the link, but shows nothing.',
    'shown' => 'The room is shown.', 'hidden' => 'The room is hidden.', 'deleted' => 'Room deleted.', 'confirm_delete' => 'Delete this room? Products and categories are not affected.',
    'move_up' => 'Move up', 'move_down' => 'Move down',
    'image' => 'Picture', 'image_hint' => 'A tall picture works best. Without one, the room uses the picture of its first category.', 'remove_image' => 'Remove the picture',
    'title_en' => 'Name (English)', 'title_ar' => 'Name (Arabic)', 'slug' => 'Address word', 'slug_hint' => 'Lowercase letters, numbers and dashes. It is in the link: /shop?room=bedroom.',
    'description_en' => 'Short text (English)', 'description_ar' => 'Short text (Arabic)', 'description_hint' => 'Shown to search engines and when the link is shared. Up to 400 characters.',
    'belongs' => 'What belongs to this room',
    'belongs_hint' => 'Tick the categories (their sub-categories come with them). A product also belongs when it carries one of the tags below.',
    'tags' => 'Product tags', 'tags_hint' => 'Separate with commas. Upper and lower case do not matter. Put the same word on a product under Catalogue > Products > Tags.',
];
