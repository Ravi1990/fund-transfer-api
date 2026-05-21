<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial schema migration.
 *
 * Design notes:
 * - All monetary values stored as BIGINT cents — never DECIMAL/FLOAT.
 * - Dual-ID pattern: BIGINT PK for internal joins, CHAR(26) ULID for API exposure.
 * - Full CONSTRAINT ... FOREIGN KEY syntax required — MySQL silently ignores
 *   inline REFERENCES clauses in column definitions.
 * - CHECK constraint on balance_cents enforces non-negative balance at DB level
 *   as a last-resort safety net. Application logic enforces this first.
 */
final class Version20240115000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create accounts, transfers, and transfer_audit_log tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE accounts (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                public_id     CHAR(26)        NOT NULL COMMENT "Raw ULID, no prefix. Lexicographic sort preserved.",
                owner_name    VARCHAR(255)    NOT NULL,
                balance_cents BIGINT          NOT NULL DEFAULT 0 COMMENT "Integer cents. Never float.",
                currency      CHAR(3)         NOT NULL,
                status        ENUM("active","frozen","closed") NOT NULL DEFAULT "active",
                created_at    DATETIME(6)     NOT NULL,
                updated_at    DATETIME(6)     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_public_id (public_id),
                CONSTRAINT chk_balance CHECK (balance_cents >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->addSql('
            CREATE TABLE transfers (
                id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                public_id        CHAR(26)        NOT NULL COMMENT "Raw ULID, no prefix.",
                idempotency_key  VARCHAR(128)    NOT NULL,
                from_account_id  BIGINT UNSIGNED NOT NULL,
                to_account_id    BIGINT UNSIGNED NOT NULL,
                amount_cents     BIGINT          NOT NULL COMMENT "Integer cents. Never float.",
                currency         CHAR(3)         NOT NULL,
                status           ENUM("pending","processing","completed","failed") NOT NULL,
                failure_reason   VARCHAR(255)    NULL,
                description      VARCHAR(512)    NULL,
                created_at       DATETIME(6)     NOT NULL,
                updated_at       DATETIME(6)     NOT NULL,
                completed_at     DATETIME(6)     NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_public_id (public_id),
                UNIQUE KEY uk_idempotency (idempotency_key),
                INDEX idx_from_status (from_account_id, status),
                INDEX idx_to_status (to_account_id, status),
                CONSTRAINT fk_transfer_from_account
                    FOREIGN KEY (from_account_id) REFERENCES accounts (id),
                CONSTRAINT fk_transfer_to_account
                    FOREIGN KEY (to_account_id) REFERENCES accounts (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->addSql('
            CREATE TABLE transfer_audit_log (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                transfer_id BIGINT UNSIGNED NOT NULL,
                from_status VARCHAR(32)     NOT NULL,
                to_status   VARCHAR(32)     NOT NULL,
                reason      VARCHAR(255)    NULL,
                actor       VARCHAR(128)    NOT NULL COMMENT "system | api | compensator",
                created_at  DATETIME(6)     NOT NULL,
                PRIMARY KEY (id),
                INDEX idx_transfer (transfer_id),
                CONSTRAINT fk_audit_transfer
                    FOREIGN KEY (transfer_id) REFERENCES transfers (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(Schema $schema): void
    {
        // Drop in reverse FK dependency order
        $this->addSql('ALTER TABLE transfer_audit_log DROP FOREIGN KEY fk_audit_transfer');
        $this->addSql('ALTER TABLE transfers DROP FOREIGN KEY fk_transfer_from_account');
        $this->addSql('ALTER TABLE transfers DROP FOREIGN KEY fk_transfer_to_account');
        $this->addSql('DROP TABLE transfer_audit_log');
        $this->addSql('DROP TABLE transfers');
        $this->addSql('DROP TABLE accounts');
    }
}
