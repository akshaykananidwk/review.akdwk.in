USE smart_review_system;

CREATE TABLE IF NOT EXISTS login_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_type ENUM('admin','client') NOT NULL,
    admin_id BIGINT UNSIGNED NULL,
    client_id BIGINT UNSIGNED NULL,
    email VARCHAR(190) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_login_history_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_login_history_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_login_history_user_type_login_at (user_type, login_at),
    INDEX idx_login_history_admin_login_at (admin_id, login_at),
    INDEX idx_login_history_client_login_at (client_id, login_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(120) NOT NULL,
    target_type VARCHAR(80) NULL,
    target_id BIGINT UNSIGNED NULL,
    description VARCHAR(255) NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_admin_activity_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_admin_activity_admin_created (admin_id, created_at),
    INDEX idx_admin_activity_action_created (action, created_at)
) ENGINE=InnoDB;
