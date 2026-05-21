<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Enum\TransferStatus;
use App\Domain\Exception\InvalidTransferStateException;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * Transfer entity with explicit state machine guards.
 *
 * Every state transition goes through transitionTo() which:
 *   1. Validates the transition is legal via TransferStatus::transitionTo()
 *   2. Persists an audit log entry (caller responsibility via addAuditLog())
 *   3. Updates timestamps
 *
 * amount_cents is BIGINT — never float. See Money value object for
 * the bcmath boundary conversion strategy.
 */
#[ORM\Entity]
#[ORM\Table(name: 'transfers')]
#[ORM\HasLifecycleCallbacks]
class Transfer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 26, unique: true)]
    private string $publicId;

    #[ORM\Column(type: 'string', length: 128, unique: true)]
    private string $idempotencyKey;

    /**
     * FK to accounts.id — BIGINT, never exposed via API.
     * Stored as the internal integer ID for efficient index lookups.
     */
    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'from_account_id', referencedColumnName: 'id', nullable: false)]
    private Account $fromAccount;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'to_account_id', referencedColumnName: 'id', nullable: false)]
    private Account $toAccount;

    /**
     * Integer cents — plain PHP int arithmetic only.
     * bcmath used only at API input/output boundaries.
     */
    #[ORM\Column(type: 'bigint')]
    private int $amountCents;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    #[ORM\Column(enumType: TransferStatus::class)]
    private TransferStatus $status;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(type: 'string', length: 512, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'datetime_immutable', precision: 6)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', precision: 6)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetime_immutable', precision: 6, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(
        Account $fromAccount,
        Account $toAccount,
        int $amountCents,
        string $currency,
        string $idempotencyKey,
        ?string $description = null,
    ) {
        $this->publicId       = (string) new Ulid();
        $this->fromAccount    = $fromAccount;
        $this->toAccount      = $toAccount;
        $this->amountCents    = $amountCents;
        $this->currency       = $currency;
        $this->idempotencyKey = $idempotencyKey;
        $this->description    = $description;
        $this->status         = TransferStatus::Pending;
        $this->createdAt      = new \DateTimeImmutable();
        $this->updatedAt      = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicId(): string
    {
        return $this->publicId;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getFromAccount(): Account
    {
        return $this->fromAccount;
    }

    public function getToAccount(): Account
    {
        return $this->toAccount;
    }

    public function getAmountCents(): int
    {
        return $this->amountCents;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStatus(): TransferStatus
    {
        return $this->status;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    /**
     * Transition to Processing state.
     * Call immediately after creating the Transfer record, before any
     * balance mutations. If the process crashes between this and markCompleted(),
     * the "processing" status identifies transfers needing compensating review.
     */
    public function markProcessing(): void
    {
        $this->status->transitionTo(TransferStatus::Processing);
        $this->status    = TransferStatus::Processing;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Transition to Completed state.
     * Call only after both debit and credit have been applied and persisted.
     */
    public function markCompleted(): void
    {
        $this->status->transitionTo(TransferStatus::Completed);
        $this->status      = TransferStatus::Completed;
        $this->completedAt = new \DateTimeImmutable();
        $this->updatedAt   = new \DateTimeImmutable();
    }

    /**
     * Transition to Failed state.
     * Records the failure reason for audit and client visibility.
     */
    public function markFailed(string $reason): void
    {
        $this->status->transitionTo(TransferStatus::Failed);
        $this->status        = TransferStatus::Failed;
        $this->failureReason = $reason;
        $this->updatedAt     = new \DateTimeImmutable();
    }
}
