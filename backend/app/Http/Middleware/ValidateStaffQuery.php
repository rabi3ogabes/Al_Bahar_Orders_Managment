<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff list endpoints read plain scalar filters straight from the query string (?search=, ?status=, ?branch_id=…).
 * A client that sends an array (`?search[]=x`), an absurdly long value or a malformed id would otherwise reach the query
 * builder and end in a 500 — answer with a clean 422 field error instead, and cap free-text search at 120 characters.
 */
class ValidateStaffQuery
{
    private const TEXT = ['search', 'status', 'payment_status', 'type', 'filter', 'sort', 'scope', 'stage', 'variance', 'q'];

    public function handle(Request $request, Closure $next): Response
    {
        $errors = [];
        foreach ($request->query() as $key => $value) {
            $isId = is_string($key) && ($key === 'id' || str_ends_with($key, '_id'));
            if (! $isId && ! in_array($key, self::TEXT, true)) {
                continue;
            }
            if (! is_string($value)) {
                $errors[$key] = [__('validation.string', ['attribute' => (string) $key])];
            } elseif ($isId && $value !== '' && ! Str::isUuid($value)) {
                $errors[$key] = [__('validation.uuid', ['attribute' => (string) $key])];
            } elseif (! $isId && mb_strlen($value) > 120) {
                $errors[$key] = [__('validation.max.string', ['attribute' => (string) $key, 'max' => 120])];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $next($request);
    }
}
