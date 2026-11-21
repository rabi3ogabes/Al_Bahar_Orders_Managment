<?php

namespace App\Domain\Orders\Printing;

/** A finished PDF: its bytes, the file name and how many parts (pages groups) it holds. */
final class PrintedDocument
{
    public function __construct(public readonly string $bytes, public readonly string $fileName, public readonly int $parts) {}
}
