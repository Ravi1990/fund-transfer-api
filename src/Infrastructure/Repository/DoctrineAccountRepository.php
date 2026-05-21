<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Entity\Account;
use App\Domain\Exception\AccountNotFoundException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Account repository.
 *
 * findByPublicIdForUpdate() uses SELECT FOR UPDATE — must be called inside
 * an active DB transaction. This is the pessimistic locking primitive that
 * prevents concurrent balance mutations on the same account row.
 *
 * Lock order discipline (enforced by the handler, not here):
 *   Always lock MIN(from_id, to_id) first to prevent deadlocks across
 *   concurrent crossing transfers. This repository provides the primitive;
 *   the handler determines the order.
 */
final class DoctrineAccountRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Find account by public ULID — read-only, no lock.
     * Use for existence checks before opening a transaction.
     *
     * @throws AccountNotFoundException
     */
    public function findByPublicId(string $publicId): Account
    {
        $account = $this->entityManager
            ->getRepository(Account::class)
            ->findOneBy(['publicId' => $publicId]);

        if ($account === null) {
            throw new AccountNotFoundException($publicId);
        }

        return $account;
    }

    /**
     * Find account by public ULID with a pessimistic write lock.
     * Issues SELECT ... FOR UPDATE — caller MUST be inside a transaction.
     *
     * FOR UPDATE (not SKIP LOCKED): SKIP LOCKED silently bypasses locked rows,
     * which is correct for job queues but dangerous here — it would silently
     * skip a locked account rather than waiting for the lock to be released.
     *
     * @throws AccountNotFoundException
     */
    public function findByPublicIdForUpdate(string $publicId): Account
    {
        $account = $this->entityManager
            ->getRepository(Account::class)
            ->findOneBy(['publicId' => $publicId]);

        if ($account === null) {
            throw new AccountNotFoundException($publicId);
        }

        // Acquire pessimistic write lock on the row.
        $this->entityManager->lock(
            $account,
            \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE,
        );

        return $account;
    }

    /**
     * Find account by internal BIGINT id with a pessimistic write lock.
     * Used by the handler after resolving public IDs to internal IDs,
     * enabling deterministic lock ordering by numeric ID.
     *
     * @throws AccountNotFoundException
     */
    public function findByIdForUpdate(int $id): Account
    {
        $account = $this->entityManager->find(Account::class, $id);

        if ($account === null) {
            throw new AccountNotFoundException((string) $id);
        }

        $this->entityManager->lock(
            $account,
            \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE,
        );

        return $account;
    }

    public function save(Account $account): void
    {
        $this->entityManager->persist($account);
    }
}
