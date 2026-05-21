<?php

declare(strict_types=1);

namespace App\Application\Command;

/**
 * Immutable DTO carrying validated input for a transfer initiation.
 * Constructed at the HTTP boundary after Symfony Validator passes.
 * All values are pre-validated — handler assumes they are clean.
 */
final readonly class InitiateTransferCommand
{
    public function __construct(
        public string $idempotencyKey,
        public string $fromAccountId,
        public string $toAccountId,
        public string $amount,
        public string $currency,
        public ?string $description,
    ) {}
}
