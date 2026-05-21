<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Application\Query\GetTransferHandler;
use App\Domain\Entity\Transfer;
use App\Domain\Service\TransferDomainService;
use App\Domain\ValueObject\Money;
use App\Infrastructure\Cache\IdempotencyStore;
use App\Infrastructure\Repository\DoctrineAccountRepository;
use App\Infrastructure\Repository\DoctrineTransferRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the transfer use case.
 *
 * Locking protocol (see TransferDomainService for full rationale):
 *   1. Validate from != to BEFORE any lock (cheapest check first).
 *   2. Check idempotency store (Redis Layer 1, then DB Layer 2).
 *   3. Mark idempotency key as "processing" in Redis.
 *   4. Open DB transaction.
 *   5. Resolve both accounts by public ID (no lock yet).
 *   6. Determine lock order: MIN(internal id) locked first.
 *      This eliminates deadlocks for concurrent crossing transfers
 *      e.g. A→B and B→A arriving simultaneously.
 *   7. SELECT FOR UPDATE on both accounts in determined order.
 *   8. Validate business rules (active, currency, balance) AFTER locks.
 *   9. Create Transfer record, mark processing, debit, credit, mark completed.
 *  10. Persist audit log entries, flush, commit.
 *  11. Cache completed response in idempotency store.
 *
 * On any business rule failure: mark transfer failed, flush, commit,
 * cache failure response so replay returns consistent error.
 *
 * On infrastructure failure: delete idempotency key so the client
 * can retry — no DB record was created.
 */
final class InitiateTransferHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DoctrineAccountRepository $accountRepository,
        private readonly DoctrineTransferRepository $transferRepository,
        private readonly TransferDomainService $domainService,
        private readonly IdempotencyStore $idempotencyStore,
        private readonly GetTransferHandler $getTransferHandler,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{response: array<string, mixed>, httpStatus: int}
     */
    public function handle(InitiateTransferCommand $command): array
    {
        // Step 1 — cheapest validation before any I/O
        $this->domainService->assertDifferentAccounts(
            $command->fromAccountId,
            $command->toAccountId,
        );

        // Step 2 — idempotency check (Redis → DB fallback)
        $cached = $this->idempotencyStore->get($command->idempotencyKey);

        if ($cached !== null) {
            if ($cached['status'] === 'processing') {
                throw new \App\Domain\Exception\DuplicateTransferException($command->idempotencyKey);
            }

            // Completed or failed replay — return cached response
            return [
                'response'   => $cached['response'],
                'httpStatus' => 200,
            ];
        }

        // Step 3 — mark in-flight before opening DB transaction
        $this->idempotencyStore->markProcessing($command->idempotencyKey);

        $money = Money::fromDecimalString($command->amount, $command->currency);

        try {
            $this->entityManager->beginTransaction();

            // Step 4 — resolve accounts without lock to get internal IDs
            $fromAccount = $this->accountRepository->findByPublicId($command->fromAccountId);
            $toAccount   = $this->accountRepository->findByPublicId($command->toAccountId);

            $fromId = (int) $fromAccount->getId();
            $toId   = (int) $toAccount->getId();

            // Step 5 — acquire locks in ascending ID order to prevent deadlocks.
            // If A→B and B→A arrive simultaneously, both will lock the lower ID
            // first, so one blocks until the other commits rather than deadlocking.
            if ($fromId < $toId) {
                $fromAccount = $this->accountRepository->findByIdForUpdate($fromId);
                $toAccount   = $this->accountRepository->findByIdForUpdate($toId);
            } else {
                $toAccount   = $this->accountRepository->findByIdForUpdate($toId);
                $fromAccount = $this->accountRepository->findByIdForUpdate($fromId);
            }

            // Step 6 — validate business rules AFTER acquiring locks.
            // Reading balance before lock could race with another transaction.
            $this->domainService->validateTransfer($fromAccount, $toAccount, $money);

            // Step 7 — create Transfer record and transition through states
            $transfer = new Transfer(
                fromAccount:    $fromAccount,
                toAccount:      $toAccount,
                amountCents:    $money->cents,
                currency:       $money->currency,
                idempotencyKey: $command->idempotencyKey,
                description:    $command->description,
            );

            $this->transferRepository->save($transfer);
            $this->entityManager->flush(); // Get DB-assigned ID for audit log

            // Audit: pending → processing
            $auditProcessing = $this->domainService->createAuditLog(
                transfer:   $transfer,
                fromStatus: 'pending',
                toStatus:   'processing',
                actor:      'api',
            );
            $transfer->markProcessing();
            $this->transferRepository->saveAuditLog($auditProcessing);

            // Step 8 — apply balance mutations within the same transaction
            $this->domainService->applyTransfer($fromAccount, $toAccount, $money);

            // Audit: processing → completed
            $auditCompleted = $this->domainService->createAuditLog(
                transfer:   $transfer,
                fromStatus: 'processing',
                toStatus:   'completed',
                actor:      'api',
            );
            $transfer->markCompleted();
            $this->transferRepository->saveAuditLog($auditCompleted);

            $this->entityManager->flush();
            $this->entityManager->commit();

            $this->logger->info('Transfer completed', [
                'transfer_id'     => $transfer->getPublicId(),
                'from_account_id' => $fromAccount->getPublicId(),
                'to_account_id'   => $toAccount->getPublicId(),
                'amount_cents'    => $money->cents,
                'currency'        => $money->currency,
                'outcome'         => 'success',
            ]);

            $response = $this->getTransferHandler->serialize($transfer);

            // Cache completed response for idempotency replay
            $this->idempotencyStore->markCompleted($command->idempotencyKey, $response);

            return ['response' => $response, 'httpStatus' => 201];

        } catch (\App\Domain\Exception\InsufficientFundsException
            | \App\Domain\Exception\AccountFrozenException
            | \App\Domain\Exception\CurrencyMismatchException $e
        ) {
            // Business rule failure — record in DB for audit trail, then re-throw
            $this->handleBusinessFailure($command->idempotencyKey, $e);
            throw $e;

        } catch (\Throwable $e) {
            // Infrastructure failure — rollback, remove idempotency key so
            // client can safely retry (no partial DB record exists)
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            $this->idempotencyStore->delete($command->idempotencyKey);

            $this->logger->error('Transfer failed with infrastructure error', [
                'idempotency_key' => $command->idempotencyKey,
                'error'           => $e->getMessage(),
                'outcome'         => 'failure',
            ]);

            throw $e;
        }
    }

    private function handleBusinessFailure(string $idempotencyKey, \Throwable $e): void
    {
        try {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
        } catch (\Throwable) {
            // Rollback failure — nothing more we can do
        }

        $this->logger->warning('Transfer failed with business rule violation', [
            'idempotency_key' => $idempotencyKey,
            'error'           => $e->getMessage(),
            'outcome'         => 'failure',
        ]);
    }
}
