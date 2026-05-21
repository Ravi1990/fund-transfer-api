<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

use App\Infrastructure\Repository\DoctrineTransferRepository;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Two-layer idempotency store.
 *
 * Layer 1 — Redis (fast path, 24h TTL):
 *   Key: "idempotency:{key}"
 *   States: "processing" | "completed" | "failed"
 *   On "completed" replay: return cached response JSON, HTTP 200.
 *   On "processing" replay: return HTTP 409 DUPLICATE_REQUEST.
 *   On miss: fall through to Layer 2.
 *
 * Layer 2 — DB fallback (Redis eviction resilience):
 *   Queries transfers table by idempotency_key UNIQUE index.
 *   If found: rehydrate response, repopulate Redis, return cached response.
 *   If not found: proceed with new transfer.
 *
 * This design ensures correctness even after Redis restart or LRU eviction.
 * Redis is a performance optimisation, not the source of truth — the DB is.
 *
 * Why not Redlock?
 *   Redis distributed locks create split-brain: the lock can expire while
 *   a DB transaction is still in flight, allowing a second request to
 *   acquire the lock and proceed concurrently. MySQL FOR UPDATE provides
 *   stronger mutual exclusion guarantees for DB-authoritative systems.
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
     * Check if an idempotency key has a cached result.
     *
     * Returns:
     *   ['status' => 'processing']                    — key mid-flight
     *   ['status' => 'completed', 'response' => [...]] — cached response
     *   ['status' => 'failed',    'response' => [...]] — cached failure
     *   null                                           — no record found
     */
    public function get(string $idempotencyKey): ?array
    {
        // Layer 1: Redis fast path
        $item = $this->cacheIdempotency->getItem($this->cacheKey($idempotencyKey));

        if ($item->isHit()) {
            /** @var array<string, mixed> $data */
            $data = $item->get();
            return $data;
        }

        // Layer 2: DB fallback — handles Redis eviction/restart scenarios
        $transfer = $this->transferRepository->findByIdempotencyKey($idempotencyKey);

        if ($transfer === null) {
            return null;
        }

        // Rehydrate from DB and repopulate Redis cache
        $data = [
            'status'   => $transfer->getStatus()->value,
            'response' => [
                'transfer_id'     => $transfer->getPublicId(),
                'status'          => $transfer->getStatus()->value,
                'from_account_id' => $transfer->getFromAccount()->getPublicId(),
                'to_account_id'   => $transfer->getToAccount()->getPublicId(),
                'amount'          => bcdiv((string) $transfer->getAmountCents(), '100', 2),
                'currency'        => $transfer->getCurrency(),
                'created_at'      => $transfer->getCreatedAt()->format(\DateTimeInterface::RFC3339),
            ],
        ];

        // Repopulate Redis so subsequent replays hit Layer 1
        $this->store($idempotencyKey, $data);

        return $data;
    }

    /**
     * Mark an idempotency key as "processing" — in-flight guard.
     * Called immediately before starting the transfer transaction.
     * If the process crashes, this key TTLs out after 24h.
     */
    public function markProcessing(string $idempotencyKey): void
    {
        $this->store($idempotencyKey, ['status' => 'processing']);
    }

    /**
     * Store the completed response for future replay.
     * Called after the transfer commits successfully.
     *
     * @param array<string, mixed> $response
     */
    public function markCompleted(string $idempotencyKey, array $response): void
    {
        $this->store($idempotencyKey, [
            'status'   => 'completed',
            'response' => $response,
        ]);
    }

    /**
     * Store a failed response for future replay.
     * Called when a transfer fails with a business rule violation.
     *
     * @param array<string, mixed> $response
     */
    public function markFailed(string $idempotencyKey, array $response): void
    {
        $this->store($idempotencyKey, [
            'status'   => 'failed',
            'response' => $response,
        ]);
    }

    /**
     * Remove an idempotency key — used when a new transfer attempt should
     * be permitted (e.g. after a transient infrastructure failure where
     * no DB record was created).
     */
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
