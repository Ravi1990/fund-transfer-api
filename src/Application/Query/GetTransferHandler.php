<?php

declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Entity\Transfer;
use App\Infrastructure\Repository\DoctrineTransferRepository;

final class GetTransferHandler
{
    public function __construct(
        private readonly DoctrineTransferRepository $transferRepository,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetTransferQuery $query): array
    {
        $transfer = $this->transferRepository->findByPublicId($query->transferPublicId);

        return $this->serialize($transfer);
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(Transfer $transfer): array
    {
        return [
            'transfer_id'     => $transfer->getPublicId(),
            'status'          => $transfer->getStatus()->value,
            'from_account_id' => $transfer->getFromAccount()->getPublicId(),
            'to_account_id'   => $transfer->getToAccount()->getPublicId(),
            'amount'          => bcdiv((string) $transfer->getAmountCents(), '100', 2),
            'currency'        => $transfer->getCurrency(),
            'created_at'      => $transfer->getCreatedAt()->format(\DateTimeInterface::RFC3339),
        ];
    }
}
