# Architecture Decision Record

## 1. Dual-ID Pattern (BIGINT internal / raw ULID public)

**Decision:** Every entity has two identifiers:
- `id BIGINT UNSIGNED AUTO_INCREMENT` — internal only, used for all FK references, JOINs, and Doctrine relations. Never exposed via API.
- `public_id CHAR(26)` — raw ULID, exposed via API only.

**Rationale:**
- BIGINT PKs provide clustered index locality in InnoDB. Sequential auto-increment means new rows are always appended to the end of the B-tree, keeping the index hot in the buffer pool.
- ULIDs are lexicographically sortable by time without a type prefix. Storing raw ULIDs (not prefixed strings like `ACC_01H...`) preserves this sort order. If a prefix is needed for logging or debugging, it is added only at the serialization layer: `'ACC_' . $account->getPublicId()`. The DB column stays `CHAR(26)`, stays sortable, stays clean.
- Exposing internal integer IDs in APIs leaks row counts, enables enumeration attacks, and creates tight coupling between API contracts and DB implementation.

**Alternative considered:** UUID v4 as primary key. Rejected because random UUIDs cause page splits and index fragmentation in InnoDB's clustered index, degrading insert performance significantly at scale.

---

## 2. Pessimistic DB Locking as Sole Concurrency Control

**Decision:** MySQL pessimistic row-level locking (`SELECT ... FOR UPDATE`) is the single concurrency control mechanism. No application-level locks, no Redis locks.

**Rationale:**
- MySQL InnoDB ACID transactions provide the strongest correctness guarantees available. `FOR UPDATE` acquires exclusive row locks that are held until the transaction commits or rolls back. No other transaction can read or modify the locked rows.
- The locking protocol uses deterministic lock ordering: always lock `MIN(from_id, to_id)` first. This eliminates deadlocks across concurrent crossing transfers (A→B and B→A arriving simultaneously will both lock the lower ID first, causing one to block rather than deadlock).
- `FOR UPDATE` (not `FOR UPDATE SKIP LOCKED`): `SKIP LOCKED` is correct for job queues where bypassing a locked row is acceptable. For financial transfers, silently skipping a locked account would allow a concurrent transfer to proceed against an inconsistent balance snapshot. We wait for the lock.

**Alternative considered:** Optimistic locking with a `version` column. Rejected because it requires retry logic in the application layer and performs poorly under high contention (many retries = many aborted transactions = wasted work). Pessimistic locking serialises conflicting operations cleanly.

---

## 3. Why Redlock Was Rejected

**Decision:** Redis distributed locking (Redlock) is not used for transfer correctness.

**Rationale:**
- MySQL row-level locking already provides mutual exclusion at the required granularity (per account row). Adding a second locking layer adds complexity without adding correctness.
- Redlock has a fundamental split-brain problem for DB-authoritative systems: a Redis lock can expire (due to clock skew, network partition, or GC pause) while the DB transaction is still in flight. A second request could then acquire the lock and proceed concurrently with the first transaction, violating mutual exclusion.
- Redis is not designed as a coordination primitive for systems where another store (MySQL) is the authoritative source of truth. The CAP theorem tradeoffs of Redis (availability over consistency under partition) make it unsuitable for financial coordination.

**Redis is used for:** idempotency key caching (performance optimisation with DB fallback) and rate limiter state (sliding window counters). Neither requires strong consistency guarantees.

---

## 4. Two-Layer Idempotency (Redis fast path + DB fallback)

**Decision:** Idempotency is enforced at two layers:
1. **Redis (Layer 1):** Fast path. Key: `idempotency_{key}`. TTL: 24h. States: `processing | completed | failed`.
2. **DB (Layer 2):** `UNIQUE KEY uk_idempotency (idempotency_key)` on the `transfers` table. Fallback when Redis misses.

**Rationale:**
- Redis provides sub-millisecond idempotency checks for the common case (replay of a recent request).
- Redis can lose data: LRU eviction, restart, or memory pressure can evict keys. Without the DB fallback, an evicted key would allow a duplicate transfer to proceed. The DB UNIQUE constraint is the authoritative guard.
- The two-layer design means Redis is a performance optimisation, not a correctness requirement. If Redis is unavailable, the DB constraint still prevents duplicates (at the cost of a slower query).
- `processing` state in Redis catches concurrent duplicate requests (same key in-flight). These return HTTP 409 immediately without touching the DB.

---

## 5. Integer Cents Internally, bcmath Only at API Boundaries

**Decision:** All monetary values are stored and computed as integer cents (BIGINT). `bcmath` is used at exactly two points: API input parsing and API output formatting.

**Rationale:**
- Floating-point arithmetic is unsuitable for money. IEEE 754 doubles cannot represent most decimal fractions exactly: `0.1 + 0.2 !== 0.3` in floating-point. A single float operation on financial data can introduce a rounding error that silently corrupts a balance.
- DECIMAL/NUMERIC types in MySQL solve the representation problem but are slower than BIGINT and require bcmath or similar for arithmetic in PHP.
- Integer cents with BIGINT avoid both problems. PHP 64-bit integers hold up to ~92 trillion USD cents — no realistic financial amount causes overflow. All arithmetic (`+`, `-`, `<`, `>=`) uses native PHP integer operations, which are exact.
- bcmath is confined to two boundary functions: `Money::fromDecimalString()` (input) and `Money::toDecimalString()` (output). This makes the "no float" invariant easy to audit: search for `bcmath` and find exactly two call sites.

---

## 6. REPEATABLE READ Isolation and When READ COMMITTED Would Be Revisited

**Decision:** Use MySQL InnoDB's default isolation level, `REPEATABLE READ`.

**Rationale:**
- `REPEATABLE READ` provides a consistent read view within the transaction. All reads within a transaction see the same snapshot, preventing non-repeatable reads.
- Combined with `FOR UPDATE`, gap locks prevent phantom inserts between the locked rows, giving effectively serialisable behaviour for our access pattern.
- This is the safer default. Its predictability reduces the risk of subtle concurrency bugs introduced by developers who don't account for varying read snapshots.

**When READ COMMITTED would be considered:**
- `READ COMMITTED` releases row locks sooner (after each statement, not at transaction end), reducing lock contention at very high concurrency.
- The tradeoff: each statement sees the latest committed data, which requires careful validation-after-lock discipline to remain safe.
- `READ COMMITTED` would only be adopted after load testing demonstrates that lock contention (measurable via `SHOW ENGINE INNODB STATUS` → `TRANSACTIONS` section, or `lock_wait_time` metrics) is the proven bottleneck. Changing isolation levels without evidence is premature optimisation.

---

## 7. Three-State Machine and Its Crash-Recovery Value

**Decision:** Transfer states are `pending → processing → completed` and `pending → processing → failed`. The `processing` state is preserved even in a synchronous flow.

**Rationale:**
- In a synchronous flow, one might argue `pending → completed` is sufficient. This is wrong for financial systems.
- If the process dies after debiting the source account but before crediting the destination (a crash between two DB writes within the same transaction is impossible with proper ACID transactions, but a crash after committing the debit and before issuing the credit in a two-phase operation is possible in more complex flows), `processing` records identify exactly which transfers need compensating review.
- More concretely: if a bug causes the transaction to commit the debit but throw before the credit, the `processing` state in the audit log tells operations that this transfer was started. `pending → completed` alone cannot distinguish "never started" from "started and crashed mid-flight" — a critical gap in any financial audit trail.
- Every state transition is written to `transfer_audit_log` with a timestamp and actor. This provides a complete, immutable history of every transfer's lifecycle.

---

## 8. PHP-FPM Connection Behaviour and ProxySQL as Production Solution

**Decision:** Accept PHP-FPM's share-nothing connection model in this service. Document ProxySQL as the production solution for connection pooling.

**Rationale:**
- PHP-FPM workers are share-nothing: each worker process holds its own persistent DB connection. Doctrine does not pool connections across workers. With 20 FPM workers, up to 20 concurrent DB connections are held, matching MySQL's `max_connections=50` with headroom for admin connections.
- This is correct and safe for a single-server deployment. The risk emerges at scale: under traffic spikes, FPM worker count can exceed MySQL's connection limit, causing `Too many connections` errors.
- The correct production solution is ProxySQL, a high-performance MySQL proxy that pools connections between application servers and MySQL. ProxySQL maintains a small number of backend connections and multiplexes many FPM workers across them, preventing connection exhaustion without changing application code.
- PgBouncer is the equivalent for PostgreSQL. Doctrine-level "connection pooling" (via `persistent: true`) is not a substitute — it still holds one connection per worker.

**Not implemented here** because ProxySQL adds operational complexity inappropriate for a single-node development environment. It is the mandatory next step before production deployment at any meaningful scale.
