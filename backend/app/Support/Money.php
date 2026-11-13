<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Immutable, decimal-safe money value in integer minor units (QAR has 2 decimals: 1 QAR = 100 dirham).
 * Never uses floating point for arithmetic. Database columns are NUMERIC(12,2) and round-trip as strings.
 */
final class Money implements JsonSerializable, Stringable
{
    private function __construct(public readonly int $minor, public readonly string $currency = 'QAR') {}

    public static function zero(string $currency = 'QAR'): self
    {
        return new self(0, $currency);
    }

    public static function ofMinor(int $minor, string $currency = 'QAR'): self
    {
        return new self($minor, $currency);
    }

    /** Parse a decimal string/int such as "1250.50", "12.5", 99. Rejects floats to avoid binary rounding. */
    public static function of(string|int|self|null $amount, string $currency = 'QAR'): self
    {
        if ($amount instanceof self) {
            return $amount;
        }
        if ($amount === null || $amount === '') {
            return self::zero($currency);
        }
        if (is_int($amount)) {
            return new self($amount * 100, $currency);
        }
        $amount = trim($amount);
        if (! preg_match('/^(-)?(\d{1,13})(?:\.(\d{1,4}))?$/', $amount, $m)) {
            throw new InvalidMoney("Invalid money amount [{$amount}].");
        }
        $sign = $m[1] === '-' ? -1 : 1;
        $fraction = str_pad($m[3] ?? '', 4, '0');
        // Round half-up from 4 decimal digits to 2.
        $minor = ((int) $m[2]) * 100 + (int) substr($fraction, 0, 2);
        if ((int) substr($fraction, 2, 2) >= 50) {
            $minor++;
        }

        return new self($sign * $minor, $currency);
    }

    public static function nullable(string|int|null $amount, string $currency = 'QAR'): ?self
    {
        return $amount === null ? null : self::of($amount, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function times(int $quantity): self
    {
        return new self($this->minor * $quantity, $this->currency);
    }

    /** Percentage given as a decimal string, e.g. "15" or "12.5"; rounds half-up to the minor unit. */
    public function percent(string|int $percent): self
    {
        $p = Money::of((string) $percent)->minor; // percent * 100
        $value = intdiv($this->minor * $p * 2 + 10000 * ($this->minor >= 0 ? 1 : -1), 20000);

        return new self($value, $this->currency);
    }

    /** Split into $parts allocations proportional to $weights (largest remainder; sum is exact). */
    public function allocate(array $weights): array
    {
        $total = array_sum($weights);
        if ($total <= 0) {
            return array_map(fn () => self::zero($this->currency), $weights);
        }
        $results = [];
        $allocated = 0;
        $remainders = [];
        foreach ($weights as $k => $w) {
            $share = intdiv($this->minor * $w, $total);
            $results[$k] = $share;
            $remainders[$k] = ($this->minor * $w) % $total;
            $allocated += $share;
        }
        arsort($remainders);
        foreach (array_keys($remainders) as $k) {
            if ($allocated >= $this->minor) {
                break;
            }
            $results[$k]++;
            $allocated++;
        }

        return array_map(fn ($m) => new self($m, $this->currency), $results);
    }

    public function min(self $other): self
    {
        return $this->minor <= $other->minor ? $this : $other;
    }

    public function max(self $other): self
    {
        return $this->minor >= $other->minor ? $this : $other;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function greaterThan(self $o): bool
    {
        return $this->minor > $o->minor;
    }

    public function greaterThanOrEqual(self $o): bool
    {
        return $this->minor >= $o->minor;
    }

    public function lessThan(self $o): bool
    {
        return $this->minor < $o->minor;
    }

    public function equals(self $o): bool
    {
        return $this->minor === $o->minor && $this->currency === $o->currency;
    }

    /** Decimal string suitable for NUMERIC columns and JSON, e.g. "1250.50". */
    public function toDecimal(): string
    {
        $abs = abs($this->minor);

        return ($this->minor < 0 ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    public function format(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $abs = abs($this->minor);
        $whole = number_format(intdiv($abs, 100));
        $value = ($this->minor < 0 ? '-' : '').$whole.'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        return $locale === 'ar' ? $value.' ر.ق' : 'QAR '.$value;
    }

    public function jsonSerialize(): array
    {
        return ['amount' => $this->toDecimal(), 'currency' => $this->currency, 'formatted' => $this->format()];
    }

    public function __toString(): string
    {
        return $this->toDecimal();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException('Currency mismatch.');
        }
    }
}
