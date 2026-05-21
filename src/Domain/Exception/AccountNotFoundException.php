<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class AccountNotFoundException extends \RuntimeException
{
    public function __construct(string $publicId)
    {
        parent::__construct(sprintf('Account not found: %s', $publicId));
    }
}
