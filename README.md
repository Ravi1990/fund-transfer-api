# Fund Transfer API

A production-grade Fund Transfer API built with PHP 8.4, Symfony 8, MySQL 8, and Redis 7.

## Quick Start

```bash
git clone https://github.com/Ravi1990/fund-transfer-api.git
cd fund-transfer-api
docker compose up -d --build
```

That's it. On startup the container automatically:
- Waits for MySQL to be ready
- Runs database migrations
- Seeds 5 sample accounts
- Starts PHP-FPM

Wait ~30 seconds then verify:
```bash
curl http://localhost:8080/health
```

Expected response:
```json
{"status":"ok","checks":{"database":"ok","redis":"ok"}}
```

---

## Sample Accounts (auto-seeded on startup)

| Owner | Public ID | Balance | Status |
|-------|-----------|---------|--------|
| Alice Johnson | `01KS4RBSPYCJWEGFF0VH3F1YK2` | $10,000.00 | active |
| Bob Smith | `01KS4RBSS33SQ50FZC0GVR4P8T` | $5,000.00 | active |
| Carol White | `01KS4RBSSJ2W85QCVNTT05K9K6` | $2,500.00 | active |
| Dave Brown | `01KS4RBSSSAD9Y3GGHKE6MJSV8` | $0.00 | active |
| Eve Davis | `01KS4RBSSZCQ88BCXJE8VJZ0XA` | $1,000.00 | frozen |

> **Note:** ULIDs are regenerated on each fresh start (`docker compose down -v && docker compose up -d --build`).
> Run `docker compose logs php | grep "Public ID"` to see current ULIDs.

---

## API Reference

### POST /api/v1/transfers

```bash
# Happy path — Alice sends $100.50 to Bob
curl -s -X POST http://localhost:8080/api/v1/transfers \
  -H "Content-Type: application/json" \
  -d '{
    "idempotency_key": "test-001",
    "from_account_id": "01KS4RBSPYCJWEGFF0VH3F1YK2",
    "to_account_id":   "01KS4RBSS33SQ50FZC0GVR4P8T",
    "amount":          "100.50",
    "currency":        "USD"
  }' | python3 -m json.tool
```

**Response 201:**
```json
{
  "transfer_id":     "01HABC...",
  "status":          "completed",
  "from_account_id": "01KS4RBSPYCJWEGFF0VH3F1YK2",
  "to_account_id":   "01KS4RBSS33SQ50FZC0GVR4P8T",
  "amount":          "100.50",
  "currency":        "USD",
  "created_at":      "2024-01-15T10:30:00+00:00"
}
```

```bash
# Idempotency — send same request again, balance debited only once
curl -s -X POST http://localhost:8080/api/v1/transfers \
  -H "Content-Type: application/json" \
  -d '{
    "idempotency_key": "test-001",
    "from_account_id": "01KS4RBSPYCJWEGFF0VH3F1YK2",
    "to_account_id":   "01KS4RBSS33SQ50FZC0GVR4P8T",
    "amount":          "100.50",
    "currency":        "USD"
  }' | python3 -m json.tool
```

```bash
# Insufficient funds — Dave has $0
curl -s -X POST http://localhost:8080/api/v1/transfers \
  -H "Content-Type: application/json" \
  -d '{
    "idempotency_key": "test-002",
    "from_account_id": "01KS4RBSSSAD9Y3GGHKE6MJSV8",
    "to_account_id":   "01KS4RBSPYCJWEGFF0VH3F1YK2",
    "amount":          "10.00",
    "currency":        "USD"
  }' | python3 -m json.tool
```

```bash
# Frozen account — Eve is frozen
curl -s -X POST http://localhost:8080/api/v1/transfers \
  -H "Content-Type: application/json" \
  -d '{
    "idempotency_key": "test-003",
    "from_account_id": "01KS4RBSSZCQ88BCXJE8VJZ0XA",
    "to_account_id":   "01KS4RBSPYCJWEGFF0VH3F1YK2",
    "amount":          "10.00",
    "currency":        "USD"
  }' | python3 -m json.tool
```

**Error responses:**

| HTTP | Code | Cause |
|------|------|-------|
| 400 | VALIDATION_ERROR | Missing/malformed fields |
| 404 | ACCOUNT_NOT_FOUND | Account ULID not found |
| 409 | INSUFFICIENT_FUNDS | Balance too low |
| 409 | DUPLICATE_REQUEST | Idempotency key in-flight |
| 409 | SAME_ACCOUNT_TRANSFER | from == to |
| 422 | CURRENCY_MISMATCH | Account currency mismatch |
| 422 | ACCOUNT_FROZEN | Account not active |
| 429 | RATE_LIMIT_EXCEEDED | Too many requests (Retry-After header included) |
| 500 | INTERNAL_ERROR | Unexpected error |

All errors use this envelope:
```json
{
  "error": {
    "code":     "INSUFFICIENT_FUNDS",
    "message":  "human-readable explanation",
    "trace_id": "20240115-abc123"
  }
}
```

### GET /api/v1/transfers/{ulid}

```bash
curl -s http://localhost:8080/api/v1/transfers/01HABC... | python3 -m json.tool
```

### GET /health

```bash
curl -s http://localhost:8080/health | python3 -m json.tool
```

---

## Running Tests

```bash
# Create test database (first time only)
docker compose exec mysql mysql -u root -proot_pass_change_me -e \
  "CREATE DATABASE IF NOT EXISTS fund_transfer_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
   GRANT ALL PRIVILEGES ON fund_transfer_test.* TO 'app'@'%'; FLUSH PRIVILEGES;"

docker compose exec \
  -e DATABASE_URL="mysql://app:app_pass@mysql:3306/fund_transfer_test?serverVersion=8.0&charset=utf8mb4" \
  -e APP_ENV=test \
  php php bin/console doctrine:migrations:migrate --no-interaction

# Run all 41 tests
docker compose exec \
  -e APP_ENV=test \
  -e DATABASE_URL="mysql://app:app_pass@mysql:3306/fund_transfer_test?serverVersion=8.0&charset=utf8mb4" \
  -e REDIS_URL="redis://redis:6379/1" \
  -e DEFAULT_URI="http://localhost" \
  -e LOCK_DSN="flock" \
  php php bin/phpunit --testdox
```

---

## Architecture Highlights

- **Pessimistic locking** — `SELECT FOR UPDATE` with deadlock-safe ordered locking (always lock lower account ID first)
- **Two-layer idempotency** — Redis fast path (24h TTL) + DB `UNIQUE` constraint fallback
- **Integer cents** — Money stored as `BIGINT`, `bcmath` only at API input/output boundaries
- **Three-state machine** — `pending → processing → completed/failed` with full audit log
- **DDD layering** — Domain / Application / Infrastructure / Controller strictly separated

See [ARCHITECTURE.md](ARCHITECTURE.md) for full decision records.

---

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `DATABASE_URL` | see `.env` | MySQL DSN |
| `REDIS_URL` | see `.env` | Redis DSN |
| `RATE_LIMIT_MAX` | `100` | Max transfers per window per account |
| `RATE_LIMIT_WINDOW` | `3600` | Rate limit window in seconds |

---

## What I'd Add Next

**Authentication** — JWT or API key validation at the middleware layer. Intentionally excluded from this deliverable as per scope, but it's the first thing I'd add before any real traffic.

**Async transfer processing** — The synchronous flow is correct for this scope. At higher load I'd introduce Symfony Messenger with an outbox pattern to decouple initiation from execution and guarantee exactly-once delivery even under failures.

**Connection pooling** — PHP-FPM holds one DB connection per worker. Under load spikes this exhausts MySQL's connection limit. ProxySQL in front of MySQL solves this without application changes.

**Load testing** — A k6 suite at 500 RPS measuring p99 latency, lock wait times, and Redis hit rate with CI-enforced SLO thresholds.

**Circuit breaker** — Fail fast when MySQL or Redis is unreachable rather than letting requests pile up and timeout.

**Event sourcing** — Replace the audit log table with an append-only event store for a fully immutable, tamper-evident transfer history.

---

## Time Spent

~8 hours including debugging Symfony 8 / PHP 8.4 / DoctrineBundle 3 compatibility issues during initial setup.

## AI Tools Used

Claude (Anthropic) was used as a pair-programming assistant throughout development. All architectural decisions, tradeoffs, and code were reviewed and validated against the specification. Every decision in ARCHITECTURE.md reflects my own understanding and professional judgment.
