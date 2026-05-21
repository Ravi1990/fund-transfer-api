<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use App\Domain\Exception\InvalidArgumentException;

/**
 * Money value object — immutable, integer cents internally.
 *
 * Two-layer bcmath strategy:
 *   - fromDecimalString(): ONLY place bcmath is used for input parsing.
 *     Converts "100.50" → 10050 safely without float precision loss.
 *   - toDecimalString(): ONLY place bcmath is used for output formatting.
 *     Converts 10050 → "100.50" for API responses.
 *
 * All internal arithmetic uses plain PHP int — never bcmath mid-domain,
 * never float anywhere. PHP 64-bit int holds ~92 trillion USD cents.
 */
final class Money
{
    private function __construct(
        public readonly int $cents,
        public readonly string $currency,
    ) {}

    /**
     * Parse a decimal string amount into integer cents.
     * bcmath boundary — this is the ONLY input conversion point.
     *
     * @throws \InvalidArgumentException on negative or malformed amount
     */
    public static function fromDecimalString(string $amount, string $currency): self
    {
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid amount format: "%s". Expected decimal string like "100.50".',
                $amount,
            ));
        }

        // bcmath converts decimal string to cents without float precision loss.
        // bcmul with scale=0 truncates — acceptable since we validate 2dp above.
        $cents = (int) bcmul($amount, '100', 0);

        if ($cents <= 0) {
            throw new \InvalidArgumentException(sprintf(
                'Amount must be greater than zero, got "%s".',
                $amount,
            ));
        }

        return new self($cents, strtoupper($currency));
    }

    /**
     * Format integer cents as a decimal string for API output.
     * bcmath boundary — this is the ONLY output conversion point.
     */
    public function toDecimalString(): string
    {
        // bcdiv with scale=2 always produces exactly 2 decimal places.
        return bcdiv((string) $this->cents, '100', 2);
    }

    /**
     * Subtract another Money from this one.
     * Plain int arithmetic — no bcmath.
     * Caller must verify isSameCurrency() before calling.
     */
    public function subtract(self $other): self
    {
        return new self($this->cents - $other->cents, $this->currency);
    }

    /**
     * Plain int comparison — no bcmath.
     */
    public function isLessThan(self $other): bool
    {
        return $this->cents < $other->cents;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->cents > $other->cents;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents
            && $this->currency === $other->currency;
    }

    public function isSameCurrency(self $other): bool
    {
        return $this->currency === $other->currency;
    }
}
