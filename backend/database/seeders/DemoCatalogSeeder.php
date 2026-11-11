<?php

namespace Database\Seeders;

/**
 * Sample showrooms, delivery zones, categories, brands, products, prices and stock — **no accounts**.
 *
 * Safe on a live environment for trying the storefront: it never creates the demo staff/customer (published
 * password). Replace or remove the sample data from the admin once real data is in.
 *
 *   php artisan db:seed --class=DemoCatalogSeeder --force
 */
class DemoCatalogSeeder extends DemoSeeder
{
    protected bool $withAccounts = false;

    protected bool $allowProduction = true;
}
