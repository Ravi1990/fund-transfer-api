<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class CurrencyMismatchException extends \RuntimeException
{
    public function __construct(string $fromCurrency, string $toCurrency)
    {
        parent::__construct(sprintf(
            'Currency mismatch: account currency %s does not match transfer currency %s',
            $fromCurrency,
            $toCurrency,
        ));
    }
}
