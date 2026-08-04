-- =====================================================================
-- Migration: Pure Wallet/Credit SaaS Model
-- Date: 2026-05-09
-- Drops the legacy "quota" concept, introduces a full wallet transaction
-- ledger and adds a configurable sign-up bonus.
--
-- Idempotent — safe to re-run.
-- =====================================================================

USE sql_review_akdwk_in;

-- ---------------------------------------------------------------------
-- 1) Drop legacy quota columns from `clients`. Wallet is the only truth.
-- ---------------------------------------------------------------------
SET @col_used := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'monthly_quota_used'
);
SET @ddl := IF(@col_used > 0, 'ALTER TABLE clients DROP COLUMN monthly_quota_used', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_limit := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'monthly_quota_limit'
);
SET @ddl := IF(@col_limit > 0, 'ALTER TABLE clients DROP COLUMN monthly_quota_limit', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_month := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'quota_usage_month'
);
SET @ddl := IF(@col_month > 0, 'ALTER TABLE clients DROP COLUMN quota_usage_month', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Make sure wallet_balance exists (older installs may be missing it).
SET @col_wallet := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'wallet_balance'
);
SET @ddl := IF(@col_wallet = 0,
    'ALTER TABLE clients ADD COLUMN wallet_balance INT NOT NULL DEFAULT 0 AFTER mobile',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 2) Wallet transactions (ledger).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wallet_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    txn_type ENUM('credit','debit') NOT NULL,
    amount INT NOT NULL,
    balance_after INT NOT NULL,
    source VARCHAR(60) NOT NULL,
    description VARCHAR(255) NULL,
    related_admin_id BIGINT UNSIGNED NULL,
    related_review_session_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_wt_client (client_id, created_at),
    KEY idx_wt_source (source),
    CONSTRAINT fk_wt_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_wt_admin FOREIGN KEY (related_admin_id) REFERENCES admins(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_wt_session FOREIGN KEY (related_review_session_id) REFERENCES review_sessions(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3) Default settings (signup bonus + leave wallet-only mode marker).
-- ---------------------------------------------------------------------
INSERT INTO system_settings (setting_key, setting_value, value_type, is_encrypted, created_at, updated_at)
VALUES
('signup_bonus_amount', '50', 'int', 0, NOW(), NOW())
ON DUPLICATE KEY UPDATE setting_key = setting_key;
