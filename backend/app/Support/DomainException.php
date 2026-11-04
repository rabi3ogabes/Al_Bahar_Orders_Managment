<?php

namespace App\Support;

use RuntimeException;

/**
 * Business-rule violation with a stable machine-readable code and a translated, customer-safe message.
 * Rendered by bootstrap/app.php as a JSON error envelope (HTTP status defaults to 422).
 */
class DomainException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        ?string $message = null,
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message ?? __('errors.'.$errorCode, $context));
    }

    public static function make(string $code, int $status = 422, array $context = []): static
    {
        return new static($code, null, $status, $context);
    }
}
