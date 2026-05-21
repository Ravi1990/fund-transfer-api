<?php

declare(strict_types=1);

namespace App\Application\Command;

use App\Application\Query\GetTransferHandler;
use App\Domain\Entity\Transfer;
use App\Domain\Exception\AccountFrozenException;
use App\Domain\Exception\AccountNotFoundException;
use App\Domain\Exception\CurrencyMismatchException;
use App\Domain\Exception\DuplicateTransferException;
use App\Domain\Exception\InsufficientFundsException;
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
 * Idempotency policy:
 *   - On success:          markCompleted() → replays return cached 201
 *   - On business failure: persist Transfer as 'failed' + markFailed()
 *                          → replays return cached failure response (409/422)
 *                          → client knows the outcome, can use a new key to retry
 *   - On infra failure:    delete() idempotency key
 *                          → client can safely retry with the same key
 *
 * This ensures the idempotency key never stays in 'processing' state
 * after the request completes, regardless of outcome.
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
                throw new DuplicateTransferException($command->idempotencyKey);
            }

            // Completed or failed replay — return cached response
            return [
                'response'   => $cached['response'],
                'httpStatus' => $cached['status'] === 'completed' ? 200 : $cached['httpStatus'],
            ];
        }

        // Step 3 — mark in-flight before opening DB transaction
        $this->idempotencyStore->markProcessing($command->idempotencyKey);

        $money = Money::fromDecimalString($command->amount, $command->currency);

        try {
            $this->entityManager->beginTransaction();

            // Resolve accounts without lock to get internal IDs
            $fromAccount = $this->accountRepository->findByPublicId($command->fromAccountId);
            $toAccount   = $this->accountRepository->findByPublicId($command->toAccountId);

            $fromId = (int) $fromAccount->getId();
            $toId   = (int) $toAccount->getId();

            // Acquire locks in ascending ID order to prevent deadlocks
            if ($fromId < $toId) {
                $fromAccount = $this->accountRepository->findByIdForUpdate($fromId);
                $toAccount   = $this->accountRepository->findByIdForUpdate($toId);
            } else {
                $toAccount   = $this->accountRepository->findByIdForUpdate($toId);
                $fromAccount = $this->accountRepository->findByIdForUpdate($fromId);
            }

            // Validate business rules AFTER acquiring locks
            $this->domainService->validateTransfer($fromAccount, $toAccount, $money);

            // Create Transfer record and transition through states
            $transfer = new Transfer(
                fromAccount:    $fromAccount,
                toAccount:      $toAccount,
                amountCents:    $money->cents,
                currency:       $money->currency,
                idempotencyKey: $command->idempotencyKey,
                description:    $command->description,
            );

            $this->transferRepository->save($transfer);
            $this->entityManager->flush();

            // Audit: pending → processing
            $auditProcessing = $this->domainService->createAuditLog(
                transfer:   $transfer,
                fromStatus: 'pending',
                toStatus:   'processing',
                actor:      'api',
            );
            $transfer->markProcessing();
            $this->transferRepository->saveAuditLog($auditProcessing);

            // Apply balance mutations
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
            $this->idempotencyStore->markCompleted($command->idempotencyKey, $response);

            return ['response' => $response, 'httpStatus' => 201];

        } catch (InsufficientFundsException
            | AccountFrozenException
            | CurrencyMismatchException
            | AccountNotFoundException $e
        ) {
            // Business rule failure — persist a failed Transfer record so the
            // audit trail shows the attempt, then cache the failure response.
            // Replays with the same idempotency key return the cached failure
            // immediately — client must use a new key to attempt a fresh transfer.
            $httpStatus = $this->resolveHttpStatus($e);
            $errorCode  = $this->resolveErrorCode($e);

            $this->persistFailedTransfer($command, $money, $e->getMessage());

            $failureResponse = [
                'error' => [
                    'code'    => $errorCode,
                    'message' => $e->getMessage(),
                ],
            ];

            $this->idempotencyStore->markFailed(
                $command->idempotencyKey,
                $failureResponse,
                $httpStatus,
            );

            $this->logger->warning('Transfer rejected — business rule violation', [
                'idempotency_key' => $command->idempotencyKey,
                'error_code'      => $errorCode,
                'error'           => $e->getMessage(),
                'outcome'         => 'failure',
            ]);

            throw $e;

        } catch (\Throwable $e) {
            // Infrastructure failure — rollback and delete idempotency key.
            // No DB record was created, so the client can safely retry
            // with the same idempotency key.
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            $this->idempotencyStore->delete($command->idempotencyKey);

            $this->logger->error('Transfer failed — infrastructure error', [
                'idempotency_key' => $command->idempotencyKey,
                'error'           => $e->getMessage(),
                'outcome'         => 'failure',
            ]);

            throw $e;
        }
    }

    /**
     * Persist a failed Transfer record for audit trail purposes.
     * Runs in its own transaction since the original one was rolled back.
     */
    private function persistFailedTransfer(
        InitiateTransferCommand $command,
        Money $money,
        string $reason,
    ): void {
        try {
            // Roll back any open transaction first
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            // Clear EM state to avoid stale entity issues
            $this->entityManager->clear();

            $this->entityManager->beginTransaction();

            $fromAccount = $this->accountRepository->findByPublicId($command->fromAccountId);
            $toAccount   = $this->accountRepository->findByPublicId($command->toAccountId);

            $transfer = new Transfer(
                fromAccount:    $fromAccount,
                toAccount:      $toAccount,
                amountCents:    $money->cents,
                currency:       $money->currency,
                idempotencyKey: $command->idempotencyKey,
                description:    $command->description,
            );

            $this->transferRepository->save($transfer);
            $this->entityManager->flush();

            $auditFailed = $this->domainService->createAuditLog(
                transfer:   $transfer,
                fromStatus: 'pending',
                toStatus:   'failed',
                actor:      'api',
                reason:     $reason,
            );
            $transfer->markFailed($reason);
            $this->transferRepository->saveAuditLog($auditFailed);

            $this->entityManager->flush();
            $this->entityManager->commit();

        } catch (\Throwable $e) {
            // If we can't persist the failed record, just log and continue.
            // The idempotency store will still cache the failure response.
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            $this->logger->error('Could not persist failed transfer record', [
                'idempotency_key' => $command->idempotencyKey,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    private function resolveHttpStatus(\Throwable $e): int
    {
        return match (true) {
            $e instanceof InsufficientFundsException  => 409,
            $e instanceof AccountNotFoundException    => 404,
            $e instanceof AccountFrozenException      => 422,
            $e instanceof CurrencyMismatchException   => 422,
            default                                   => 500,
        };
    }

    private function resolveErrorCode(\Throwable $e): string
    {
        return match (true) {
            $e instanceof InsufficientFundsException  => 'INSUFFICIENT_FUNDS',
            $e instanceof AccountNotFoundException    => 'ACCOUNT_NOT_FOUND',
            $e instanceof AccountFrozenException      => 'ACCOUNT_FROZEN',
            $e instanceof CurrencyMismatchException   => 'CURRENCY_MISMATCH',
            default                                   => 'INTERNAL_ERROR',
        };
    }
}
