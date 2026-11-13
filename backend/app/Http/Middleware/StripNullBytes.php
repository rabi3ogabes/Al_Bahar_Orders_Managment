<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TransformsRequest;

/**
 * PostgreSQL text and jsonb columns cannot hold a NUL byte ("\0"): one in any submitted string made the insert fail with a 500
 * (and the audit-log row with it). A NUL is never legitimate user text, so drop it from every query/body/JSON string up front.
 */
class StripNullBytes extends TransformsRequest
{
    protected function transform($key, $value)
    {
        return is_string($value) ? str_replace("\0", '', $value) : $value;
    }
}
