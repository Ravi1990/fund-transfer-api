<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class AccountFrozenException extends \RuntimeException
{
    public function __construct(string $accountPublicId)
    {
        parent::__construct(sprintf('Account %s is not active', $accountPublicId));
    }
}
