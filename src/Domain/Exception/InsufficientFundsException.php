<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class InsufficientFundsException extends \RuntimeException
{
    public function __construct(string $accountPublicId)
    {
        parent::__construct(sprintf('Insufficient funds in account %s', $accountPublicId));
    }
}
