<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Human-friendly, non-guessable-enough document numbers (orders, transfers, POs...).
 * Order numbers come from a PostgreSQL sequence (gap-tolerant, concurrency-safe).
 * Other documents use a prefix + Qatar date + random suffix and rely on UNIQUE constraints.
 */
final class DocumentNumber
{
    public static function order(): string
    {
        $seq = (int) DB::selectOne("SELECT nextval('order_number_seq') AS n")->n;

        return 'AB'.$seq;
    }

    public static function make(string $prefix): string
    {
        return strtoupper($prefix).'-'.BusinessTime::now()->format('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    }
}
