<?php

namespace App\Support\Scale;

use Closure;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fills a SCALE database with realistic volumes so the query plans can be proven at size (docs/guides/SCALE_PROOF.md).
 *
 * Everything is bulk SQL (generate_series, INSERT ... SELECT): a million orders take minutes, not hours. The data is deterministic
 * (ids are md5 hashes of a counter, every "random" choice is a hash of the row number), so two runs make the same database, and the
 * rows of one table can name the rows of another without a lookup. Orders are written in chunks through one temporary table that holds
 * the shape of each order (status, branch, totals), so the order, its parts, lines, payment, delivery and history always agree.
 *
 * It refuses to run unless the database name ends in "_scale" and the environment is not production: it is never part of the normal test suite.
 */
class ScaleSeeder
{
    /** Orders per chunk: big enough to be fast, small enough to keep the temporary table and the WAL in check. */
    private const CHUNK = 100_000;

    /** Number of drivers per branch. */
    private const DRIVERS_PER_BRANCH = 6;

    /** The password hash every generated customer shares (no one logs in as them; hashing 200k passwords would take an hour). */
    private const PASSWORD_HASH = '$2y$12$4Kx0q0y5y3m9m2E3x0Jx3e8k6J2wZ2uQ3q1o2z8T1c0u5n7c3m1yK';

    /** @var Closure(string): void */
    private Closure $say;

    public function __construct(private readonly int $orders, private readonly int $products, ?Closure $say = null)
    {
        $this->say = $say ?? static function (string $line): void {};
    }

    /** The guard every entry point calls first: a scale database is a throw-away database whose name says so. */
    public static function assertSafe(): void
    {
        $name = (string) DB::connection()->getDatabaseName();
        if (! str_ends_with($name, '_scale') || app()->environment('production')) {
            throw new RuntimeException("Refusing to run: the database \"{$name}\" does not end in _scale or APP_ENV is production.");
        }
    }

    public function customers(): int
    {
        return max(1, intdiv($this->orders, 5));
    }

    public function run(): void
    {
        self::assertSafe();
        DB::statement('SET statement_timeout = 0');
        DB::statement('SET synchronous_commit = off');
        DB::statement("SET maintenance_work_mem = '256MB'");

        $this->stage('base data (roles, zones, branches, demo catalogue)', fn () => $this->base());
        $this->stage('drivers', fn () => $this->drivers());
        $this->stage('catalogue', fn () => $this->catalogue());
        $this->stage('customers and addresses', fn () => $this->customerRows());
        for ($from = 1; $from <= $this->orders; $from += self::CHUNK) {
            $to = min($this->orders, $from + self::CHUNK - 1);
            $this->stage("orders {$from}..{$to}", fn () => $this->orderChunk($from, $to));
        }
        $this->stage('carts, tickets, sequences', fn () => $this->extras());
        $this->stage('ANALYZE', fn () => DB::unprepared('ANALYZE'));
    }

    /** @param  Closure(): void  $work */
    private function stage(string $label, Closure $work): void
    {
        $t = microtime(true);
        $work();
        ($this->say)(sprintf('%-52s %7.1fs', $label, microtime(true) - $t));
    }

    private function base(): void
    {
        if (DB::table('roles')->doesntExist()) {
            (new RolesAndPermissionsSeeder)->run();
            (new ReferenceDataSeeder)->run();
        }
        if (DB::table('branches')->doesntExist()) {
            // The demo seeder refuses in production, which assertSafe() already ruled out; it gives the four real branches, their zones and slots, the staff and 14 products.
            (new DemoSeeder)->run();
        }
    }

    private function drivers(): void
    {
        $perBranch = self::DRIVERS_PER_BRANCH;
        DB::statement(<<<SQL
            INSERT INTO users (id, type, name, email, password, locale, status, created_at, updated_at)
            SELECT md5('drv:'||b.code||':'||k)::uuid, 'staff', 'Driver '||b.code||' '||k, lower('scale.driver.'||b.code||'.'||k||'@albahar.test'), '{$this->hash()}', 'en', 'active', now() - interval '900 days', now()
            FROM branches b CROSS JOIN generate_series(1, {$perBranch}) k
            ON CONFLICT DO NOTHING
        SQL);
        DB::statement(<<<'SQL'
            INSERT INTO branch_employees (id, branch_id, user_id, job_title, is_active, is_delivery_driver, created_at, updated_at)
            SELECT md5('bemp:'||b.code||':'||k)::uuid, b.id, md5('drv:'||b.code||':'||k)::uuid, 'Driver', true, true, now(), now()
            FROM branches b CROSS JOIN generate_series(1, 6) k
            ON CONFLICT DO NOTHING
        SQL);
        DB::statement(<<<'SQL'
            INSERT INTO user_roles (id, user_id, role_id, branch_id, created_at, updated_at)
            SELECT md5('urole:'||b.code||':'||k)::uuid, md5('drv:'||b.code||':'||k)::uuid, (SELECT id FROM roles WHERE key = 'delivery_employee'), b.id, now(), now()
            FROM branches b CROSS JOIN generate_series(1, 6) k
            ON CONFLICT DO NOTHING
        SQL);
        DB::statement(<<<'SQL'
            INSERT INTO driver_profiles (user_id, on_duty, last_seen_at, created_at, updated_at)
            SELECT md5('drv:'||b.code||':'||k)::uuid, (k <= 3), now() - interval '5 minutes', now(), now()
            FROM branches b CROSS JOIN generate_series(1, 6) k
            ON CONFLICT DO NOTHING
        SQL);
    }

    private function catalogue(): void
    {
        $p = $this->products;
        DB::statement(<<<'SQL'
            CREATE TEMP TABLE sc_cat AS
            SELECT row_number() OVER (ORDER BY slug) AS n, id FROM categories WHERE parent_id IS NOT NULL
        SQL);
        DB::statement(<<<'SQL'
            CREATE TEMP TABLE sc_brand AS SELECT row_number() OVER (ORDER BY slug) AS n, id FROM brands
        SQL);
        DB::statement(<<<SQL
            INSERT INTO products (id, sku, category_id, brand_id, status, has_variants, currency, price, sale_price, is_featured, is_bestseller, is_new_arrival,
                requires_assembly, available_in_all_branches, rating_average, rating_count, sales_count, published_at, created_at, updated_at, lead_time_days)
            SELECT md5('prod:'||i)::uuid, 'SC-'||lpad(i::text, 6, '0'),
                (SELECT id FROM sc_cat WHERE n = 1 + abs(hashtextextended(i::text||'c', 0)) % (SELECT count(*) FROM sc_cat)),
                (SELECT id FROM sc_brand WHERE n = 1 + abs(hashtextextended(i::text||'b', 0)) % (SELECT count(*) FROM sc_brand)),
                CASE WHEN abs(hashtextextended(i::text||'s', 0)) % 100 < 94 THEN 'active' ELSE 'draft' END,
                (abs(hashtextextended(i::text||'v', 0)) % 100 < 40), 'QAR',
                (100 + abs(hashtextextended(i::text||'p', 0)) % 900 * 10)::numeric(12,2),
                CASE WHEN abs(hashtextextended(i::text||'d', 0)) % 100 < 20 THEN (80 + abs(hashtextextended(i::text||'p', 0)) % 900 * 8)::numeric(12,2) END,
                (i % 29 = 0), (i % 23 = 0), (i % 31 = 0), (i % 7 = 0), true,
                (3 + abs(hashtextextended(i::text||'r', 0)) % 20 / 10.0)::numeric(3,2), abs(hashtextextended(i::text||'rc', 0)) % 120,
                (1000000 * power(1 - i::numeric / {$p}, 3))::bigint, now() - ((abs(hashtextextended(i::text||'t', 0)) % 700) || ' days')::interval,
                now() - ((abs(hashtextextended(i::text||'t', 0)) % 900) || ' days')::interval, now(), 0
            FROM generate_series(1, {$p}) i
            ON CONFLICT DO NOTHING
        SQL);
        DB::statement(<<<SQL
            INSERT INTO product_translations (id, product_id, locale, name, slug, short_description, description, search_keywords, search_text, created_at, updated_at)
            SELECT md5('ptr:'||i||':'||l.loc)::uuid, md5('prod:'||i)::uuid, l.loc,
                CASE WHEN l.loc = 'en' THEN (ARRAY['Oslo','Milano','Atlas','Dune','Sahara','Zafran','Najma','Layal','Manar','Fanoos'])[1 + i % 10] || ' ' || (ARRAY['Sofa','Table','Chair','Bed','Lamp','Rug','Wardrobe','Shelf'])[1 + i % 8] || ' ' || i
                     ELSE (ARRAY['أوسلو','ميلانو','أطلس','ديون','صحارى','زعفران','نجمة','ليال','منار','فانوس'])[1 + i % 10] || ' ' || (ARRAY['كنبة','طاولة','كرسي','سرير','مصباح','سجادة','خزانة','رف'])[1 + i % 8] || ' ' || i END,
                'scale-product-'||i||'-'||l.loc, 'Crafted for calm, considered living.', '<p>A scale-test product.</p>', 'scale',
                CASE WHEN l.loc = 'en' THEN (ARRAY['Oslo','Milano','Atlas','Dune','Sahara','Zafran','Najma','Layal','Manar','Fanoos'])[1 + i % 10] || ' ' || (ARRAY['Sofa','Table','Chair','Bed','Lamp','Rug','Wardrobe','Shelf'])[1 + i % 8] || ' ' || i || ' SC-'||lpad(i::text, 6, '0')
                     ELSE (ARRAY['أوسلو','ميلانو','أطلس','ديون','صحارى','زعفران','نجمة','ليال','منار','فانوس'])[1 + i % 10] || ' ' || (ARRAY['كنبة','طاولة','كرسي','سرير','مصباح','سجادة','خزانة','رف'])[1 + i % 8] || ' ' || i END,
                now(), now()
            FROM generate_series(1, {$p}) i CROSS JOIN (VALUES ('en'), ('ar')) AS l(loc)
            ON CONFLICT DO NOTHING
        SQL);
        // A default variant for every product, and two more for the 40% that have variants.
        DB::statement(<<<SQL
            INSERT INTO product_variants (id, product_id, sku, name_en, name_ar, status, is_default, sort_order, created_at, updated_at)
            SELECT md5('var:'||i||':'||k)::uuid, md5('prod:'||i)::uuid, 'SC-'||lpad(i::text, 6, '0')||'-'||k, 'Option '||k, 'خيار '||k, 'active', (k = 0), k, now(), now()
            FROM generate_series(1, {$p}) i CROSS JOIN generate_series(0, 2) k
            WHERE k = 0 OR abs(hashtextextended(i::text||'v', 0)) % 100 < 40
            ON CONFLICT DO NOTHING
        SQL);
        DB::statement(<<<SQL
            INSERT INTO product_images (id, product_id, disk, path, alt_en, alt_ar, width, height, sort_order, created_at, updated_at)
            SELECT md5('img:'||i)::uuid, md5('prod:'||i)::uuid, 'public', 'products/scale/'||(i % 40)||'.jpg', 'Scale product', 'منتج', 1200, 1200, 0, now(), now()
            FROM generate_series(1, {$p}) i
            ON CONFLICT DO NOTHING
        SQL);
        DB::statement(<<<'SQL'
            INSERT INTO branch_inventory (id, branch_id, product_variant_id, on_hand, reserved, minimum_stock, average_cost, created_at, updated_at, out_since)
            SELECT md5('inv:'||b.id||v.id)::uuid, b.id, v.id,
                CASE WHEN abs(hashtextextended(v.id::text||b.code, 0)) % 100 < 12 THEN 0 ELSE abs(hashtextextended(v.id::text||b.code||'q', 0)) % 40 END,
                0, 2, 120, now(), now(),
                CASE WHEN abs(hashtextextended(v.id::text||b.code, 0)) % 100 < 12 THEN now() - interval '9 days' END
            FROM branches b CROSS JOIN product_variants v
            WHERE v.sku LIKE 'SC-%'
            ON CONFLICT DO NOTHING
        SQL);
        DB::statement('DROP TABLE sc_cat');
        DB::statement('DROP TABLE sc_brand');
    }

    private function customerRows(): void
    {
        $c = $this->customers();
        $hash = $this->hash();
        DB::statement(<<<SQL
            INSERT INTO users (id, type, name, phone, password, phone_verified_at, locale, status, created_at, updated_at)
            SELECT md5('cust:'||i)::uuid, 'customer', 'Customer '||i, '+9745'||lpad(i::text, 7, '0'), '{$hash}', now() - interval '800 days',
                CASE WHEN i % 3 = 0 THEN 'ar' ELSE 'en' END, 'active',
                now() - ((1095 * (1 - sqrt(i::numeric / {$c}))) || ' days')::interval, now()
            FROM generate_series(1, {$c}) i
            ON CONFLICT DO NOTHING
        SQL);
        DB::statement(<<<SQL
            INSERT INTO user_addresses (id, user_id, label, recipient_name, recipient_phone, delivery_zone_id, zone_number, street_number, building_number, unit_number, area, city, is_default, created_at, updated_at)
            SELECT md5('addr:'||i||':'||k)::uuid, md5('cust:'||i)::uuid, 'Home', 'Customer '||i, '+9745'||lpad(i::text, 7, '0'),
                (SELECT array_agg(id ORDER BY code) FROM delivery_zones)[1 + i % (SELECT count(*) FROM delivery_zones)],
                (10 + i % 80)::text, (1 + i % 400)::text, (1 + i % 60)::text, (1 + i % 20)::text, 'Doha', 'Doha', (k = 0), now(), now()
            FROM generate_series(1, {$c}) i CROSS JOIN generate_series(0, 1) k
            WHERE k = 0 OR i % 3 = 0
            ON CONFLICT DO NOTHING
        SQL);
        DB::statement(<<<SQL
            INSERT INTO loyalty_accounts (id, user_id, balance, lifetime_earned, tier, created_at, updated_at)
            SELECT md5('loy:'||i)::uuid, md5('cust:'||i)::uuid, abs(hashtextextended(i::text||'l', 0)) % 5000, abs(hashtextextended(i::text||'l', 0)) % 20000, 'silver', now(), now()
            FROM generate_series(1, {$c}) i
            ON CONFLICT DO NOTHING
        SQL);
    }

    /** One chunk of orders and everything that hangs off them. */
    private function orderChunk(int $from, int $to): void
    {
        $n = $this->orders;
        $c = $this->customers();
        $p = $this->products;
        $q = fn (string $e, string $salt, int $mod): string => "(abs(hashtextextended(({$e})::text||'{$salt}', 0)) % {$mod})";

        DB::statement('DROP TABLE IF EXISTS sc_o');
        DB::statement('CREATE TEMP TABLE sc_b AS SELECT row_number() OVER (ORDER BY code) AS n, id, code FROM branches');
        DB::statement('CREATE TEMP TABLE sc_z AS SELECT row_number() OVER (ORDER BY code) AS n, id FROM delivery_zones');
        // The shape of every order of the chunk, decided once.
        DB::statement(<<<SQL
            CREATE TEMP TABLE sc_o AS
            WITH base AS (
                SELECT i, (1095 * (1 - sqrt((i - 0.5) / {$n}::numeric)) * 86400 + {$q('i', 'j', 86400)})::bigint AS secs_ago,
                       1 + {$q('i', 'cu', $c)} AS cust, {$q('i', 'st', 1000)} AS st, {$q('i', 'pm', 100)} AS pm, {$q('i', 'ft', 100)} AS ft,
                       1 + {$q('i', 'k', 3)} AS k, {$q('i', 'h2', 100)} AS h2, 1 + {$q('i', 'b1', 4)} AS b1
                FROM generate_series({$from}, {$to}) i
            ), shaped AS (
                SELECT *, now() - make_interval(secs => secs_ago) AS placed_at,
                       CASE WHEN pm < 60 THEN 'sadad' WHEN pm < 90 THEN 'cod' ELSE 'pay_at_pickup' END AS method,
                       CASE WHEN ft < 78 THEN 'delivery' ELSE 'pickup' END AS ftype,
                       (k >= 2 AND h2 < 30) AS has2
                FROM base
            ), statused AS (
                SELECT *, CASE
                    WHEN secs_ago < 172800 THEN (CASE WHEN st < 150 THEN 'confirmed' WHEN st < 300 THEN 'processing'
                        WHEN st < 420 THEN (CASE WHEN ftype = 'delivery' THEN 'out_for_delivery' ELSE 'ready_for_pickup' END)
                        WHEN st < 500 THEN 'pending_payment' WHEN st < 540 THEN 'cancelled' ELSE 'completed' END)
                    WHEN st < 4 AND secs_ago < 1209600 THEN 'confirmed'
                    WHEN st < 45 THEN 'cancelled' WHEN st < 70 THEN 'returned' ELSE 'completed' END AS status
                FROM shaped
            )
            SELECT s.*, 'AB'||(100000 + i) AS number, md5('order:'||i)::uuid AS id, md5('cust:'||cust)::uuid AS cust_id,
                   (SELECT coalesce(sum((50 + ((i * 31 + j * 17) % 900) * 5) * (1 + (((i + j) % 4 = 0))::int)), 0) FROM generate_series(1, k) j)::numeric(12,2) AS subtotal,
                   CASE WHEN ftype = 'delivery' THEN (ARRAY[0, 25, 50])[1 + i % 3] ELSE 0 END::numeric(12,2) AS delivery_fee
            FROM statused s
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE sc_o ADD COLUMN grand numeric(12,2), ADD COLUMN pay_status text, ADD COLUMN done_at timestamptz
        SQL);
        DB::statement(<<<'SQL'
            UPDATE sc_o SET grand = subtotal + delivery_fee,
                pay_status = CASE WHEN status = 'pending_payment' THEN 'pending' WHEN status = 'cancelled' THEN 'cancelled'
                    WHEN method = 'sadad' THEN 'paid' WHEN status IN ('completed', 'returned') THEN 'paid' ELSE 'pending' END,
                done_at = CASE WHEN status IN ('completed', 'returned') THEN placed_at + make_interval(hours => 24 + (i % 120)) END
        SQL);
        DB::statement('CREATE INDEX ON sc_o (i)');

        DB::statement(<<<'SQL'
            INSERT INTO orders (id, number, user_id, source, status, payment_status, payment_method, fulfillment_type, currency, subtotal, delivery_fee_total, grand_total, paid_total,
                customer_name, customer_phone, locale, placed_at, confirmed_at, completed_at, cancelled_at, created_at, updated_at, progress_seq, address_snapshot)
            SELECT id, number, cust_id, CASE WHEN i % 11 = 0 THEN 'admin' ELSE 'app' END, status, pay_status, method, ftype, 'QAR', subtotal, delivery_fee, grand,
                CASE WHEN pay_status = 'paid' THEN grand ELSE 0 END, 'Customer '||cust, '+9745'||lpad(cust::text, 7, '0'), CASE WHEN cust % 3 = 0 THEN 'ar' ELSE 'en' END,
                placed_at, CASE WHEN status <> 'pending_payment' THEN placed_at + interval '5 minutes' END, done_at,
                CASE WHEN status = 'cancelled' THEN placed_at + interval '2 hours' END, placed_at, coalesce(done_at, placed_at + interval '5 minutes'), 2,
                jsonb_build_object('zone_number', (10 + cust % 80)::text, 'area', 'Doha', 'city', 'Doha')
            FROM sc_o
        SQL);

        // Parts: every order has one; 30% of the multi-line orders have a second part at the next branch.
        DB::statement(<<<'SQL'
            INSERT INTO order_fulfillments (id, order_id, branch_id, number, type, status, delivery_zone_id, subtotal, delivery_fee, slot_date, accepted_at, ready_at, dispatched_at, delivered_at, picked_up_at, cancelled_at, created_at, updated_at)
            SELECT md5('ful:'||o.i||':'||part)::uuid, o.id, (SELECT id FROM sc_b WHERE n = 1 + ((o.b1 - 1 + part) % 4)), o.number||'-'||(part + 1), o.ftype,
                CASE o.status
                    WHEN 'completed' THEN (CASE WHEN o.ftype = 'delivery' THEN 'delivered' ELSE 'picked_up' END)
                    WHEN 'returned' THEN 'returned' WHEN 'cancelled' THEN 'cancelled' WHEN 'pending_payment' THEN 'pending'
                    WHEN 'confirmed' THEN 'pending' WHEN 'processing' THEN 'preparing'
                    WHEN 'out_for_delivery' THEN 'out_for_delivery' ELSE 'pickup_ready' END,
                (SELECT id FROM sc_z WHERE n = 1 + o.cust % (SELECT count(*) FROM sc_z)),
                (SELECT coalesce(sum((50 + ((o.i * 31 + j * 17) % 900) * 5) * (1 + (((o.i + j) % 4 = 0))::int)), 0) FROM generate_series(1, o.k) j WHERE NOT o.has2 OR j % 2 = part),
                CASE WHEN part = 0 THEN o.delivery_fee ELSE 0 END, (o.placed_at + interval '2 days')::date,
                CASE WHEN o.status <> 'pending_payment' THEN o.placed_at + interval '10 minutes' END,
                CASE WHEN o.status IN ('completed', 'returned', 'out_for_delivery', 'ready_for_pickup') THEN o.placed_at + interval '20 hours' END,
                CASE WHEN o.status IN ('completed', 'returned', 'out_for_delivery') AND o.ftype = 'delivery' THEN o.placed_at + interval '21 hours' END,
                CASE WHEN o.status IN ('completed', 'returned') AND o.ftype = 'delivery' THEN o.done_at END,
                CASE WHEN o.status = 'completed' AND o.ftype = 'pickup' THEN o.done_at END,
                CASE WHEN o.status = 'cancelled' THEN o.placed_at + interval '2 hours' END, o.placed_at, coalesce(o.done_at, o.placed_at + interval '5 minutes')
            FROM sc_o o CROSS JOIN generate_series(0, 1) part
            WHERE part = 0 OR o.has2
        SQL);

        // Lines: item j of an order belongs to the first part, or to the second when the order has two parts and j is even.
        DB::statement(<<<SQL
            INSERT INTO order_items (id, order_id, order_fulfillment_id, branch_id, product_id, product_variant_id, sku, name_en, name_ar, unit_price, unit_final_price, quantity, line_subtotal, line_total,
                quantity_handed_over, price_source, created_at, updated_at)
            SELECT md5('item:'||o.i||':'||j)::uuid, o.id, f.id, f.branch_id, md5('prod:'||pr.p)::uuid, md5('var:'||pr.p||':0')::uuid, 'SC-'||lpad(pr.p::text, 6, '0'), 'Scale product '||pr.p, 'منتج '||pr.p,
                u.price, u.price, u.qty, u.price * u.qty, u.price * u.qty, CASE WHEN o.status IN ('completed', 'returned') THEN u.qty ELSE 0 END, 'base', o.placed_at, o.placed_at
            FROM sc_o o CROSS JOIN LATERAL generate_series(1, o.k) j
            CROSS JOIN LATERAL (SELECT 1 + floor({$p} * power(abs(hashtextextended((o.i * 10 + j)::text||'p', 0)) % 1000000 / 1000000.0, 2))::int AS p) pr
            CROSS JOIN LATERAL (SELECT (50 + ((o.i * 31 + j * 17) % 900) * 5)::numeric(12,2) AS price, 1 + (((o.i + j) % 4 = 0))::int AS qty) u
            JOIN order_fulfillments f ON f.id = md5('ful:'||o.i||':'||(CASE WHEN o.has2 AND j % 2 = 0 THEN 1 ELSE 0 END))::uuid
        SQL);

        DB::statement(<<<'SQL'
            INSERT INTO payments (id, payable_type, payable_id, user_id, order_id, method, provider, status, currency, amount, captured_amount, refunded_amount, provider_reference, idempotency_key,
                attempt, paid_at, created_at, updated_at)
            SELECT md5('pay:'||o.i)::uuid, 'order', o.id, o.cust_id, o.id, o.method, CASE WHEN o.method = 'sadad' THEN 'sadad' ELSE 'offline' END,
                CASE o.pay_status WHEN 'paid' THEN 'paid' WHEN 'cancelled' THEN 'cancelled' ELSE 'pending' END, 'QAR', o.grand,
                CASE WHEN o.pay_status = 'paid' THEN o.grand ELSE 0 END, 0,
                CASE WHEN o.method = 'sadad' THEN 'SDD'||o.i END, 'pay-'||o.i, 1,
                CASE WHEN o.pay_status = 'paid' THEN o.placed_at + interval '3 minutes' END, o.placed_at, o.placed_at
            FROM sc_o o
        SQL);

        // Refunds on 3% of paid orders, and returns on the orders that came back.
        DB::statement(<<<'SQL'
            INSERT INTO refunds (id, number, payment_id, order_id, amount, currency, reason_code, method, status, idempotency_key, approved_at, processed_at, created_at, updated_at)
            SELECT md5('ref:'||o.i)::uuid, 'RF'||(100000 + o.i), md5('pay:'||o.i)::uuid, o.id, round(o.grand / 2, 2), 'QAR', 'damaged', 'original', 'processed', 'ref-'||o.i,
                o.done_at + interval '1 day', o.done_at + interval '2 days', o.done_at + interval '1 day', o.done_at + interval '2 days'
            FROM sc_o o WHERE o.pay_status = 'paid' AND (o.i % 33 = 0 OR o.status = 'returned') AND o.done_at IS NOT NULL AND o.grand > 1
        SQL);
        DB::statement(<<<'SQL'
            UPDATE payments p SET refunded_amount = r.amount, status = 'partially_refunded'
            FROM refunds r WHERE r.payment_id = p.id AND p.id IN (SELECT md5('pay:'||i)::uuid FROM sc_o)
        SQL);
        DB::statement(<<<'SQL'
            INSERT INTO return_requests (id, number, order_id, user_id, branch_id, status, reason_code, reason, items, decided_at, refund_id, created_at, updated_at, idempotency_key)
            SELECT md5('ret:'||o.i)::uuid, 'RT'||(100000 + o.i), o.id, o.cust_id, f.branch_id, CASE WHEN o.i % 5 = 0 THEN 'requested' ELSE 'refunded' END, 'damaged', 'Scale test return', '[]'::jsonb,
                o.done_at + interval '2 days', CASE WHEN o.i % 5 <> 0 THEN md5('ref:'||o.i)::uuid END, o.done_at + interval '1 day', o.done_at + interval '2 days', 'ret-'||o.i
            FROM sc_o o JOIN order_fulfillments f ON f.id = md5('ful:'||o.i||':0')::uuid
            WHERE o.status = 'returned' OR (o.status = 'completed' AND o.i % 50 = 0)
        SQL);

        // Deliveries for every delivery part that left the shop; the cash of cash-on-delivery orders that were delivered.
        DB::statement(<<<'SQL'
            INSERT INTO deliveries (id, order_fulfillment_id, branch_id, mode, driver_id, status, tracking_number, cod_amount, requires_proof, attempt_count, scheduled_for, assigned_at, picked_up_at,
                out_for_delivery_at, delivered_at, returned_at, created_at, updated_at)
            SELECT md5('del:'||f.id)::uuid, f.id, f.branch_id, 'own_fleet', md5('drv:'||b.code||':'||(1 + abs(hashtextextended(f.id::text, 0)) % 6))::uuid,
                CASE f.status WHEN 'delivered' THEN 'delivered' WHEN 'returned' THEN 'returned' ELSE 'out_for_delivery' END,
                'TRK'||substr(md5(f.id::text), 1, 12), CASE WHEN o.method = 'cod' THEN o.grand ELSE 0 END, true, 1, f.slot_date::timestamptz, f.created_at + interval '1 hour', f.dispatched_at,
                f.dispatched_at, f.delivered_at, CASE WHEN f.status = 'returned' THEN f.delivered_at END, f.created_at, f.updated_at
            FROM order_fulfillments f JOIN sc_o o ON f.order_id = o.id JOIN branches b ON b.id = f.branch_id
            WHERE f.type = 'delivery' AND f.status IN ('delivered', 'returned', 'out_for_delivery')
        SQL);
        DB::statement(<<<'SQL'
            INSERT INTO delivery_proofs (id, delivery_id, type, disk, path, recipient_name, captured_at, created_at, updated_at)
            SELECT md5('proof:'||d.id)::uuid, d.id, 'photo', 'private', 'proofs/scale/'||(abs(hashtextextended(d.id::text, 0)) % 40)||'.jpg', 'Recipient', d.delivered_at, d.delivered_at, d.delivered_at
            FROM deliveries d WHERE d.delivered_at IS NOT NULL AND d.id IN (SELECT md5('del:'||f.id)::uuid FROM order_fulfillments f JOIN sc_o o ON o.id = f.order_id)
        SQL);
        DB::statement(<<<'SQL'
            INSERT INTO cash_collections (id, payment_id, order_fulfillment_id, branch_id, collected_by, context, tender, amount_due, amount_collected, status, collected_at, handed_in_at, created_at, updated_at)
            SELECT md5('cash:'||d.id)::uuid, md5('pay:'||o.i)::uuid, f.id, f.branch_id, d.driver_id, 'delivery', 'cash', o.grand, o.grand, 'collected', d.delivered_at,
                CASE WHEN d.delivered_at < now() - interval '2 days' THEN d.delivered_at + interval '1 day' END, d.delivered_at, d.delivered_at
            FROM sc_o o JOIN order_fulfillments f ON f.order_id = o.id AND f.number = o.number||'-1'
            JOIN deliveries d ON d.order_fulfillment_id = f.id
            WHERE o.method = 'cod' AND o.status = 'completed' AND d.delivered_at IS NOT NULL
        SQL);

        // History, stock, messages and the audit trail.
        DB::statement(<<<'SQL'
            INSERT INTO order_status_history (order_id, kind, from_status, to_status, actor_type, is_customer_visible, created_at)
            SELECT o.id, 'order', 'pending_payment', 'confirmed', 'system', true, o.placed_at + interval '5 minutes' FROM sc_o o WHERE o.status <> 'pending_payment'
            UNION ALL
            SELECT o.id, 'order', 'confirmed', o.status, 'staff', true, coalesce(o.done_at, o.placed_at + interval '2 hours') FROM sc_o o WHERE o.status NOT IN ('pending_payment', 'confirmed')
        SQL);
        DB::statement(<<<'SQL'
            INSERT INTO stock_movements (branch_id, product_variant_id, type, quantity, reserved_delta, on_hand_after, reserved_after, reference_type, reference_id, created_at)
            SELECT i.branch_id, i.product_variant_id, 'sale_deduction', -i.quantity, 0, 10, 0, 'order', i.order_id::text, i.created_at
            FROM order_items i WHERE i.order_id IN (SELECT id FROM sc_o) AND i.created_at < now() - interval '1 day'
        SQL);
        DB::statement(<<<'SQL'
            INSERT INTO notifications (id, user_id, event, category, title, body, data, read_at, created_at, updated_at)
            SELECT md5('ntf:'||o.i||':'||nk)::uuid, o.cust_id, CASE WHEN nk = 0 THEN 'order.placed' ELSE 'order.delivered' END, 'orders',
                CASE WHEN nk = 0 THEN 'Order placed' ELSE 'Order delivered' END, 'Your order '||o.number||' update', jsonb_build_object('order_id', o.id),
                CASE WHEN o.placed_at < now() - interval '3 days' THEN o.placed_at + interval '1 hour' END, CASE WHEN nk = 0 THEN o.placed_at ELSE o.done_at END, o.placed_at
            FROM sc_o o CROSS JOIN generate_series(0, 1) nk WHERE nk = 0 OR (o.status = 'completed' AND o.done_at IS NOT NULL AND o.i % 2 = 0)
        SQL);
        DB::statement(<<<'SQL'
            INSERT INTO notification_logs (id, user_id, event, channel, provider, recipient, status, attempts, sent_at, created_at, updated_at)
            SELECT md5('nlog:'||o.i)::uuid, o.cust_id, 'order.placed', CASE WHEN o.i % 2 = 0 THEN 'push' ELSE 'email' END, 'scale', 'customer', 'sent', 1, o.placed_at, o.placed_at, o.placed_at
            FROM sc_o o WHERE o.i % 2 = 0
        SQL);
        DB::statement(<<<'SQL'
            INSERT INTO audit_logs (actor_id, actor_type, action, auditable_type, auditable_id, branch_id, metadata, ip, created_at)
            SELECT o.cust_id, 'customer', 'order.placed', 'Order', o.id::text, (SELECT id FROM sc_b WHERE n = o.b1), jsonb_build_object('number', o.number, 'total', o.grand), '10.0.0.1', o.placed_at
            FROM sc_o o
            UNION ALL
            SELECT NULL, 'staff', 'order.status_changed', 'Order', o.id::text, (SELECT id FROM sc_b WHERE n = o.b1), jsonb_build_object('status', o.status), '10.0.0.2', coalesce(o.done_at, o.placed_at + interval '2 hours')
            FROM sc_o o WHERE o.i % 2 = 0
        SQL);

        DB::statement('DROP TABLE sc_o');
        DB::statement('DROP TABLE sc_b');
        DB::statement('DROP TABLE sc_z');
    }

    private function extras(): void
    {
        $c = $this->customers();
        DB::statement(<<<SQL
            INSERT INTO carts (id, user_id, branch_id, fulfillment_type, status, last_activity_at, expires_at, created_at, updated_at)
            SELECT md5('cart:'||i)::uuid, md5('cust:'||(1 + i * 7 % {$c}))::uuid, (SELECT id FROM branches ORDER BY code LIMIT 1), 'delivery',
                CASE WHEN i % 4 = 0 THEN 'active' ELSE 'abandoned' END, now() - ((i % 900) || ' hours')::interval, now() + interval '7 days', now() - ((i % 900) || ' hours')::interval, now()
            FROM generate_series(1, {$this->abandoned()}) i
            ON CONFLICT DO NOTHING
        SQL);
        DB::statement(<<<SQL
            INSERT INTO cart_items (id, cart_id, product_variant_id, quantity, price_at_add, created_at, updated_at)
            SELECT md5('citem:'||i)::uuid, md5('cart:'||i)::uuid, md5('var:'||(1 + i * 13 % {$this->products})||':0')::uuid, 1 + i % 2, 500, now(), now()
            FROM generate_series(1, {$this->abandoned()}) i
            ON CONFLICT DO NOTHING
        SQL);
        DB::statement(<<<SQL
            INSERT INTO support_tickets (id, number, user_id, category, subject, priority, status, created_at, updated_at)
            SELECT md5('tkt:'||i)::uuid, 'TK'||(100000 + i), md5('cust:'||(1 + i * 11 % {$c}))::uuid, 'order', 'Where is my order?', 'normal',
                CASE WHEN i % 5 = 0 THEN 'open' ELSE 'closed' END, now() - ((i % 20000) || ' minutes')::interval, now()
            FROM generate_series(1, {$this->abandoned()} / 2) i
            ON CONFLICT DO NOTHING
        SQL);
        DB::statement("SELECT setval('order_number_seq', ".(100000 + $this->orders + 1).', false)');
    }

    private function abandoned(): int
    {
        return max(10, intdiv($this->orders, 30));
    }

    private function hash(): string
    {
        return str_replace("'", "''", self::PASSWORD_HASH);
    }
}
