<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Enum\AccountStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * Account entity.
 *
 * Dual-ID pattern:
 *   - id (BIGINT): internal only, never exposed via API. Used for all FK
 *     references and JOINs. Clustered index locality means sequential inserts
 *     are cache-friendly.
 *   - public_id (CHAR(26) ULID): exposed via API only. Raw ULID, no prefix.
 *     Lexicographic sort preserved — prepending a type prefix would break
 *     temporal ordering.
 *
 * balance_cents is BIGINT (integer cents), never float. PHP 64-bit int holds
 * up to ~92 trillion USD cents — no overflow risk for any realistic amount.
 */
#[ORM\Entity]
#[ORM\Table(name: 'accounts')]
#[ORM\HasLifecycleCallbacks]
class Account
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 26, unique: true)]
    private string $publicId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $ownerName;

    /**
     * Balance stored as integer cents. Never use float arithmetic on this value.
     * All arithmetic uses plain PHP int — bcmath is only at API boundaries.
     */
    #[ORM\Column(type: 'bigint')]
    private int $balanceCents;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    #[ORM\Column(enumType: AccountStatus::class)]
    private AccountStatus $status;

    #[ORM\Column(type: 'datetime_immutable', precision: 6)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', precision: 6)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $ownerName,
        int $balanceCents,
        string $currency,
        AccountStatus $status = AccountStatus::Active,
        ?string $publicId = null,
    ) {
        $this->publicId     = $publicId ?? (string) new Ulid();
        $this->ownerName    = $ownerName;
        $this->balanceCents = $balanceCents;
        $this->currency     = $currency;
        $this->status       = $status;
        $this->createdAt    = new \DateTimeImmutable();
        $this->updatedAt    = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicId(): string
    {
        return $this->publicId;
    }

    public function getOwnerName(): string
    {
        return $this->ownerName;
    }

    public function getBalanceCents(): int
    {
        return $this->balanceCents;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStatus(): AccountStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isActive(): bool
    {
        return $this->status === AccountStatus::Active;
    }

    /**
     * Debit cents from this account.
     * Caller MUST verify sufficient balance before calling.
     * The DB CHECK constraint (balance_cents >= 0) is the last-resort guard.
     */
    public function debit(int $cents): void
    {
        $this->balanceCents -= $cents;
        $this->touch();
    }

    /**
     * Credit cents to this account.
     */
    public function credit(int $cents): void
    {
        $this->balanceCents += $cents;
        $this->touch();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
