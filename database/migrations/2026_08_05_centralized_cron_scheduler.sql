-- Centralized Cron Scheduler
-- One master server cron (every minute) drives all background tasks.
-- Jobs register themselves from app/cron_jobs/registry.php; these tables
-- hold their schedule state, execution history and the notification queue.

CREATE TABLE IF NOT EXISTS cron_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_key VARCHAR(100) NOT NULL UNIQUE,
    job_name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    schedule_type ENUM('every_minutes','daily','weekly') NOT NULL DEFAULT 'every_minutes',
    schedule_value VARCHAR(20) NOT NULL DEFAULT '5',
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_run_at DATETIME NULL,
    last_success_at DATETIME NULL,
    last_status ENUM('never','running','success','failed','skipped') NOT NULL DEFAULT 'never',
    last_duration_ms INT UNSIGNED NULL,
    last_error TEXT NULL,
    next_run_at DATETIME NULL,
    run_count INT UNSIGNED NOT NULL DEFAULT 0,
    fail_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_cron_jobs_due (is_enabled, next_run_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cron_job_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_key VARCHAR(100) NOT NULL,
    trigger_type ENUM('auto','manual','retry') NOT NULL DEFAULT 'auto',
    status ENUM('running','success','failed') NOT NULL DEFAULT 'running',
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    duration_ms INT UNSIGNED NULL,
    summary VARCHAR(500) NULL,
    log_text MEDIUMTEXT NULL,
    error_message TEXT NULL,
    triggered_by_admin_id BIGINT UNSIGNED NULL,
    KEY idx_cron_runs_job (job_key, id),
    KEY idx_cron_runs_started (started_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notification_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel ENUM('whatsapp','email') NOT NULL DEFAULT 'whatsapp',
    client_id BIGINT UNSIGNED NULL,
    recipient VARCHAR(190) NOT NULL,
    subject VARCHAR(255) NULL,
    message TEXT NOT NULL,
    media_url VARCHAR(500) NULL,
    source VARCHAR(60) NOT NULL DEFAULT 'system',
    dedupe_key VARCHAR(190) NULL,
    status ENUM('pending','processing','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
    send_after DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_error TEXT NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notification_dedupe (dedupe_key),
    KEY idx_notification_due (status, send_after),
    KEY idx_notification_client (client_id)
) ENGINE=InnoDB;
