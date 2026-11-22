<?php

/*
 | Promotions: leave out products, categories and brands (docs/guides/PROMOTION_EXCLUSIONS.md).
 */
return [
    'title' => 'Leave out',
    'hint' => 'Products, categories and brands this offer never discounts, even when they would otherwise be covered. Leaving something out always wins over including it.',
    'toggle_hint' => 'Optional',
    'products' => 'Products to leave out',
    'categories' => 'Categories to leave out',
    'categories_hint' => 'A category also leaves out all its sub-categories.',
    'brands' => 'Brands to leave out',
    'brands_none' => 'No brands yet.',
    'count' => '{0} Nothing is left out|{1} 1 thing is left out|[2,*] :count things are left out',
    'summary' => [
        'order' => 'Whole basket',
        'categories' => '{1} 1 category|[2,*] :count categories',
        'products' => '{1} 1 product|[2,*] :count products',
        'except' => ':what except :list',
        'n_products' => '{1} 1 product|[2,*] :count products',
        'n_categories' => '{1} 1 category|[2,*] :count categories',
        'n_brands' => '{1} 1 brand|[2,*] :count brands',
        'glue' => ', ',
    ],
];
