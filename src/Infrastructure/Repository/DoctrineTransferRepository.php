<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Entity\Transfer;
use App\Domain\Entity\TransferAuditLog;
use App\Domain\Exception\TransferNotFoundException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Transfer repository.
 *
 * findByIdempotencyKey() provides the DB fallback for idempotency Layer 2.
 * When Redis evicts an idempotency key, the handler queries here before
 * treating the request as new. This ensures correctness across Redis restarts.
 */
final class DoctrineTransferRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Find transfer by public ULID for GET endpoint.
     *
     * @throws TransferNotFoundException
     */
    public function findByPublicId(string $publicId): Transfer
    {
        $transfer = $this->entityManager
            ->getRepository(Transfer::class)
            ->findOneBy(['publicId' => $publicId]);

        if ($transfer === null) {
            throw new TransferNotFoundException($publicId);
        }

        return $transfer;
    }

    /**
     * Find transfer by idempotency key — DB Layer 2 fallback.
     * Returns null when no matching transfer exists (new request).
     */
    public function findByIdempotencyKey(string $idempotencyKey): ?Transfer
    {
        return $this->entityManager
            ->getRepository(Transfer::class)
            ->findOneBy(['idempotencyKey' => $idempotencyKey]);
    }

    public function save(Transfer $transfer): void
    {
        $this->entityManager->persist($transfer);
    }

    public function saveAuditLog(TransferAuditLog $auditLog): void
    {
        $this->entityManager->persist($auditLog);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
