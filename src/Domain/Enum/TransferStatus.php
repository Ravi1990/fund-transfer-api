<?php

declare(strict_types=1);

namespace App\Domain\Enum;

use App\Domain\Exception\InvalidTransferStateException;

enum TransferStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case Completed  = 'completed';
    case Failed     = 'failed';

    /**
     * @return array<TransferStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            // pending → failed: business rule rejected before processing started
            // pending → processing: normal flow
            self::Pending    => [self::Processing, self::Failed],
            self::Processing => [self::Completed, self::Failed],
            self::Completed  => [],
            self::Failed     => [],
        };
    }

    public function transitionTo(self $next): void
    {
        if (!in_array($next, $this->allowedTransitions(), strict: true)) {
            throw new InvalidTransferStateException(sprintf(
                'Invalid transfer state transition: %s → %s',
                $this->value,
                $next->value,
            ));
        }
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed => true,
            default                       => false,
        };
    }
}
