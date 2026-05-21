<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Immutable audit log entry for every transfer state transition.
 *
 * Written on every markProcessing(), markCompleted(), markFailed() call.
 * Never updated after insert — audit records are append-only by design.
 * actor field records who triggered the transition: 'system', 'api', 'compensator'.
 */
#[ORM\Entity]
#[ORM\Table(name: 'transfer_audit_log')]
class TransferAuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Transfer::class)]
    #[ORM\JoinColumn(name: 'transfer_id', referencedColumnName: 'id', nullable: false)]
    private Transfer $transfer;

    #[ORM\Column(type: 'string', length: 32)]
    private string $fromStatus;

    #[ORM\Column(type: 'string', length: 32)]
    private string $toStatus;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $reason;

    #[ORM\Column(type: 'string', length: 128)]
    private string $actor;

    #[ORM\Column(type: 'datetime_immutable', precision: 6)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Transfer $transfer,
        string $fromStatus,
        string $toStatus,
        string $actor,
        ?string $reason = null,
    ) {
        $this->transfer   = $transfer;
        $this->fromStatus = $fromStatus;
        $this->toStatus   = $toStatus;
        $this->actor      = $actor;
        $this->reason     = $reason;
        $this->createdAt  = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTransfer(): Transfer
    {
        return $this->transfer;
    }

    public function getFromStatus(): string
    {
        return $this->fromStatus;
    }

    public function getToStatus(): string
    {
        return $this->toStatus;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getActor(): string
    {
        return $this->actor;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
