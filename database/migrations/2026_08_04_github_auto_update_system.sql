-- GitHub Auto Update System
-- Adds update history, backup history and migration tracking tables.
-- Run once on production DB (the updater itself runs future migrations
-- automatically and records them in `system_migrations`).

CREATE TABLE IF NOT EXISTS system_update_backups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    backup_name VARCHAR(190) NOT NULL,
    files_path VARCHAR(255) NULL,
    db_dump_path VARCHAR(255) NULL,
    commit_hash VARCHAR(64) NULL,
    app_version VARCHAR(32) NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('created','restored','deleted') NOT NULL DEFAULT 'created',
    created_by_admin_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    restored_at DATETIME NULL,
    KEY idx_backups_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_updates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    update_type ENUM('update','rollback') NOT NULL DEFAULT 'update',
    from_version VARCHAR(32) NULL,
    to_version VARCHAR(32) NULL,
    from_commit VARCHAR(64) NULL,
    to_commit VARCHAR(64) NULL,
    commit_message TEXT NULL,
    commit_author VARCHAR(190) NULL,
    commit_date DATETIME NULL,
    changed_files MEDIUMTEXT NULL,
    status ENUM('pending','running','success','failed','rolled_back') NOT NULL DEFAULT 'pending',
    next_step VARCHAR(64) NULL,
    progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
    backup_id BIGINT UNSIGNED NULL,
    log_text MEDIUMTEXT NULL,
    error_message TEXT NULL,
    initiated_by_admin_id BIGINT UNSIGNED NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_updates_status (status),
    KEY idx_updates_created (created_at),
    CONSTRAINT fk_updates_backup FOREIGN KEY (backup_id)
        REFERENCES system_update_backups(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration_file VARCHAR(190) NOT NULL UNIQUE,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_id BIGINT UNSIGNED NULL,
    KEY idx_migrations_update (update_id)
) ENGINE=InnoDB;
