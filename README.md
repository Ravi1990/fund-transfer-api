# Fund Transfer API

A production-grade Fund Transfer API built with PHP 8.4, Symfony 8, MySQL 8, and Redis 7.

## Quick Start

```bash
# 1. Start all services
docker compose up -d --build

# 2. Run database migrations
docker compose exec php php bin/console doctrine:database:create --if-not-exists
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

# 3. Verify health
curl http://localhost:8080/health
```

## API Reference

### POST /api/v1/transfers

Initiate a fund transfer between two accounts.

```bash
curl -X POST http://localhost:8080/api/v1/transfers \
  -H "Content-Type: application/json" \
  -d '{
    "idempotency_key": "unique-client-key-001",
    "from_account_id": "01HXYZ1234567890ABCDEFGHIJ",
    "to_account_id":   "01HXYZ1234567890ABCDEFGHIK",
    "amount":          "100.50",
    "currency":        "USD",
    "description":     "Optional description"
  }'
```

**Response 201:**
```json
{
  "transfer_id":     "01HABC...",
  "status":          "completed",
  "from_account_id": "01HXYZ...",
  "to_account_id":   "01HXYZ...",
  "amount":          "100.50",
  "currency":        "USD",
  "created_at":      "2024-01-15T10:30:00+00:00"
}
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
| 500 | INTERNAL_ERROR | Unexpected error (never leaks internals) |

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

Retrieve a transfer by public ULID.

```bash
curl http://localhost:8080/api/v1/transfers/01HABC...
```

**Response 200:** Same shape as POST 201 response.

### GET /health

```bash
curl http://localhost:8080/health
```

```json
{
  "status": "ok",
  "checks": { "database": "ok", "redis": "ok" }
}
```

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

# Run all tests
docker compose exec \
  -e APP_ENV=test \
  -e DATABASE_URL="mysql://app:app_pass@mysql:3306/fund_transfer_test?serverVersion=8.0&charset=utf8mb4" \
  -e REDIS_URL="redis://redis:6379/1" \
  -e DEFAULT_URI="http://localhost" \
  -e LOCK_DSN="flock" \
  php php bin/phpunit --testdox
```

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_ENV` | `dev` | Application environment |
| `APP_SECRET` | — | Symfony secret (min 32 chars) |
| `DATABASE_URL` | — | MySQL DSN |
| `REDIS_URL` | — | Redis DSN |
| `RATE_LIMIT_MAX` | `100` | Max transfers per window per account |
| `RATE_LIMIT_WINDOW` | `3600` | Rate limit window in seconds |
| `LOCK_DSN` | `flock` | Symfony Lock store DSN |
| `DEFAULT_URI` | `http://localhost` | Base URI for router context |

## What I'd Add Next

- **Async processing via Symfony Messenger + outbox pattern** — The current synchronous flow is correct and simple. Under very high load, decoupling transfer initiation from execution via a message queue (with the outbox pattern for exactly-once delivery) would improve throughput and resilience.
- **Circuit breaker for DB unavailability** — Fail fast when MySQL is unreachable rather than queuing requests that will all timeout.
- **Event sourcing for immutable audit trail** — Replace `transfer_audit_log` with an append-only event store. Every state change becomes an immutable event, making the audit trail tamper-evident.
- **ProxySQL in production network topology** — PHP-FPM workers each hold one DB connection. Under traffic spikes, connection exhaustion is the primary scaling bottleneck. ProxySQL pools connections across workers transparently.
- **k6 load test suite with SLO thresholds** — 500 RPS targeting the transfer endpoint, measuring p50/p99 latency, `lock_wait_time`, DB QPS, Redis hit rate, and error rate under contention.
- **Authentication layer** — API key or JWT validation at the gateway/middleware level. Intentionally out of scope for this deliverable.

## Time Spent

Approximately 6–8 hours including iterative debugging of Symfony 8 / DoctrineBundle 3 / PHP 8.4 compatibility issues encountered during setup.

## AI Tools Used

Claude (Anthropic) was used as a pair-programming assistant throughout. Prompts focused on: architecture decisions with explicit justification requirements, phase-by-phase generation with confirmation gates, and iterative debugging of version compatibility issues. All architectural decisions, tradeoffs, and code were reviewed and validated against the specification.
