<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Entity\Account;
use App\Domain\Entity\Transfer;
use App\Domain\Entity\TransferAuditLog;
use App\Domain\Exception\AccountFrozenException;
use App\Domain\Exception\CurrencyMismatchException;
use App\Domain\Exception\InsufficientFundsException;
use App\Domain\Exception\SameAccountTransferException;
use App\Domain\ValueObject\Money;

/**
 * Pure domain service — zero I/O, zero infrastructure dependencies.
 *
 * All validation rules live here so they can be tested in isolation
 * without a database or HTTP stack. The handler calls this service
 * AFTER acquiring pessimistic FOR UPDATE locks on both account rows,
 * ensuring the validated state is the committed state.
 *
 * Locking protocol (enforced by the handler, documented here):
 *   Step 1: Validate from != to BEFORE acquiring any lock (this method).
 *   Step 2: Open DB transaction.
 *   Step 3: Lock MIN(from_id, to_id) first to prevent deadlocks.
 *   Step 4: SELECT FOR UPDATE on both accounts.
 *   Step 5: Re-validate business rules AFTER locks (this method).
 *   Step 6: Debit, credit, persist — all within the same transaction.
 *   Step 7: Commit.
 */
final class TransferDomainService
{
    /**
     * Validate that source and destination are different accounts.
     * Called BEFORE acquiring any DB lock — cheapest check first.
     *
     * @throws SameAccountTransferException
     */
    public function assertDifferentAccounts(string $fromPublicId, string $toPublicId): void
    {
        if ($fromPublicId === $toPublicId) {
            throw new SameAccountTransferException();
        }
    }

    /**
     * Validate all business rules after locks are acquired.
     * Order: active status → currency match → sufficient balance.
     * Each check is fast and throws immediately on failure.
     *
     * @throws AccountFrozenException
     * @throws CurrencyMismatchException
     * @throws InsufficientFundsException
     */
    public function validateTransfer(
        Account $fromAccount,
        Account $toAccount,
        Money $amount,
    ): void {
        // Both accounts must be active — check source first, then destination.
        if (!$fromAccount->isActive()) {
            throw new AccountFrozenException($fromAccount->getPublicId());
        }

        if (!$toAccount->isActive()) {
            throw new AccountFrozenException($toAccount->getPublicId());
        }

        // Currency must match between source account and transfer amount.
        if ($fromAccount->getCurrency() !== $amount->currency) {
            throw new CurrencyMismatchException($fromAccount->getCurrency(), $amount->currency);
        }

        // Currency must match between destination account and transfer amount.
        if ($toAccount->getCurrency() !== $amount->currency) {
            throw new CurrencyMismatchException($toAccount->getCurrency(), $amount->currency);
        }

        // Sufficient balance check — plain int comparison, no bcmath.
        if ($fromAccount->getBalanceCents() < $amount->cents) {
            throw new InsufficientFundsException($fromAccount->getPublicId());
        }
    }

    /**
     * Apply debit and credit mutations to both accounts.
     * Called only after validateTransfer() passes.
     * Both mutations happen in the same DB transaction — atomicity
     * is guaranteed by the caller's transaction boundary.
     */
    public function applyTransfer(
        Account $fromAccount,
        Account $toAccount,
        Money $amount,
    ): void {
        $fromAccount->debit($amount->cents);
        $toAccount->credit($amount->cents);
    }

    /**
     * Create an audit log entry for a state transition.
     * Returns the entity — caller persists it within the transaction.
     */
    public function createAuditLog(
        Transfer $transfer,
        string $fromStatus,
        string $toStatus,
        string $actor = 'system',
        ?string $reason = null,
    ): TransferAuditLog {
        return new TransferAuditLog(
            transfer: $transfer,
            fromStatus: $fromStatus,
            toStatus: $toStatus,
            actor: $actor,
            reason: $reason,
        );
    }
}
