<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

use App\Infrastructure\Repository\DoctrineTransferRepository;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Two-layer idempotency store.
 *
 * Idempotency policy per outcome:
 *   completed:  cache response → replays return HTTP 200
 *   failed:     cache failure  → replays return same error (client needs new key)
 *   processing: in-flight guard → replays return HTTP 409 DUPLICATE_REQUEST
 *
 * Layer 1 — Redis (fast path, 24h TTL)
 * Layer 2 — DB fallback (Redis eviction resilience)
 */
final class IdempotencyStore
{
    private const KEY_PREFIX = 'idempotency_';
    private const TTL        = 86400; // 24 hours

    public function __construct(
        private readonly CacheItemPoolInterface $cacheIdempotency,
        private readonly DoctrineTransferRepository $transferRepository,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $idempotencyKey): ?array
    {
        $item = $this->cacheIdempotency->getItem($this->cacheKey($idempotencyKey));

        if ($item->isHit()) {
            /** @var array<string, mixed> $data */
            $data = $item->get();
            return $data;
        }

        // Layer 2: DB fallback
        $transfer = $this->transferRepository->findByIdempotencyKey($idempotencyKey);

        if ($transfer === null) {
            return null;
        }

        $data = [
            'status'     => $transfer->getStatus()->value,
            'httpStatus' => $transfer->getStatus()->value === 'completed' ? 201 : 409,
            'response'   => [
                'transfer_id'     => $transfer->getPublicId(),
                'status'          => $transfer->getStatus()->value,
                'from_account_id' => $transfer->getFromAccount()->getPublicId(),
                'to_account_id'   => $transfer->getToAccount()->getPublicId(),
                'amount'          => bcdiv((string) $transfer->getAmountCents(), '100', 2),
                'currency'        => $transfer->getCurrency(),
                'created_at'      => $transfer->getCreatedAt()->format(\DateTimeInterface::RFC3339),
            ],
        ];

        $this->store($idempotencyKey, $data);

        return $data;
    }

    public function markProcessing(string $idempotencyKey): void
    {
        $this->store($idempotencyKey, ['status' => 'processing']);
    }

    /**
     * @param array<string, mixed> $response
     */
    public function markCompleted(string $idempotencyKey, array $response): void
    {
        $this->store($idempotencyKey, [
            'status'     => 'completed',
            'httpStatus' => 201,
            'response'   => $response,
        ]);
    }

    /**
     * @param array<string, mixed> $response
     */
    public function markFailed(string $idempotencyKey, array $response, int $httpStatus): void
    {
        $this->store($idempotencyKey, [
            'status'     => 'failed',
            'httpStatus' => $httpStatus,
            'response'   => $response,
        ]);
    }

    public function delete(string $idempotencyKey): void
    {
        $this->cacheIdempotency->deleteItem($this->cacheKey($idempotencyKey));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function store(string $idempotencyKey, array $data): void
    {
        $item = $this->cacheIdempotency->getItem($this->cacheKey($idempotencyKey));
        $item->set($data);
        $item->expiresAfter(self::TTL);
        $this->cacheIdempotency->save($item);
    }

    private function cacheKey(string $idempotencyKey): string
    {
        return self::KEY_PREFIX . $idempotencyKey;
    }
}
