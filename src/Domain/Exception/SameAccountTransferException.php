<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class SameAccountTransferException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Source and destination accounts must be different');
    }
}
