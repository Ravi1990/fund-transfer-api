<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class DuplicateTransferException extends \RuntimeException
{
    public function __construct(string $idempotencyKey)
    {
        parent::__construct(sprintf('Transfer with idempotency key "%s" is already processing', $idempotencyKey));
    }
}
