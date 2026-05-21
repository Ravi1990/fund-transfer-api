<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class TransferNotFoundException extends \RuntimeException
{
    public function __construct(string $publicId)
    {
        parent::__construct(sprintf('Transfer not found: %s', $publicId));
    }
}
