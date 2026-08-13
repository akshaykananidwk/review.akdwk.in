-- Phase 1 — Observability + rate limiting
--
-- system_event_logs : structured warning/error/critical events with the
--                     reference ID shown to users (ERR-YYYYMMDD-XXXXXX).
-- rate_limit_buckets: sliding-window counters for login, registration,
--                     the public review flow and the POS invite API.
--                     Replaces the per-calendar-day ip_rate_limits table
--                     for new call sites (that table stays in place for
--                     the existing password-reset limiter).
--
-- Run once. The updater applies this automatically.

CREATE TABLE IF NOT EXISTS system_event_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference_id VARCHAR(32) NOT NULL,
    severity ENUM('debug','info','warning','error','critical') NOT NULL DEFAULT 'error',
    channel VARCHAR(40) NOT NULL DEFAULT 'app',
    message VARCHAR(1000) NOT NULL,
    context_json MEDIUMTEXT NULL,
    exception_class VARCHAR(190) NULL,
    exception_message TEXT NULL,
    exception_file VARCHAR(500) NULL,
    exception_line INT UNSIGNED NULL,
    request_uri VARCHAR(500) NULL,
    request_method VARCHAR(10) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    actor_type VARCHAR(20) NULL,
    actor_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sel_reference (reference_id),
    KEY idx_sel_severity_created (severity, created_at),
    KEY idx_sel_channel_created (channel, created_at),
    KEY idx_sel_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- login_history previously recorded successful logins only, so failed
-- attempts left no trace. Widen it to carry the outcome. Guarded so the
-- migration can be re-run safely.
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_history' AND COLUMN_NAME = 'outcome');
SET @sql := IF(@col = 0,
    "ALTER TABLE login_history
        MODIFY COLUMN user_type ENUM('admin','client','unknown') NOT NULL,
        ADD COLUMN outcome ENUM('success','failure') NOT NULL DEFAULT 'success' AFTER user_type,
        ADD COLUMN failure_reason VARCHAR(100) NULL AFTER outcome,
        ADD KEY idx_login_history_outcome (outcome, login_at)",
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS rate_limit_buckets (
    bucket_key VARCHAR(190) NOT NULL PRIMARY KEY,
    action_key VARCHAR(60) NOT NULL,
    hit_count INT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    blocked_until DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_rlb_window (window_started_at),
    KEY idx_rlb_action (action_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
