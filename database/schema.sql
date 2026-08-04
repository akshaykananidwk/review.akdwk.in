CREATE DATABASE IF NOT EXISTS smart_review_system
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE smart_review_system;

CREATE TABLE admins (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin') NOT NULL DEFAULT 'super_admin',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE system_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    value_type ENUM('string','int','json','bool') NOT NULL DEFAULT 'string',
    is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
    updated_by_admin_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_settings_admin FOREIGN KEY (updated_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE business_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE clients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_name VARCHAR(180) NOT NULL,
    owner_name VARCHAR(120) NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    mobile VARCHAR(20) NOT NULL,
    wallet_balance INT NOT NULL DEFAULT 0,
    address TEXT NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    google_place_id VARCHAR(255) NOT NULL,
    google_review_url TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_clients_category FOREIGN KEY (category_id) REFERENCES business_categories(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE wallet_transactions (
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
    CONSTRAINT fk_wt_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE standee_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NULL,
    image_path VARCHAR(512) NOT NULL,
    qr_pos_x INT NOT NULL DEFAULT 0,
    qr_pos_y INT NOT NULL DEFAULT 0,
    qr_width INT NOT NULL DEFAULT 0,
    qr_height INT NOT NULL DEFAULT 0,
    native_width INT NOT NULL DEFAULT 0,
    native_height INT NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_standee_tpl_sort (sort_order, id),
    KEY idx_standee_tpl_active (is_active)
) ENGINE=InnoDB;

CREATE TABLE facilities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    facility_name VARCHAR(120) NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE client_facilities (
    client_id BIGINT UNSIGNED NOT NULL,
    facility_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (client_id, facility_id),
    CONSTRAINT fk_cf_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_cf_facility FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE client_qr_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    qr_token VARCHAR(120) NOT NULL UNIQUE,
    public_review_url TEXT NOT NULL,
    qr_image_path VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_qr_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE review_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_uuid CHAR(36) NOT NULL UNIQUE,
    client_id BIGINT UNSIGNED NOT NULL,
    qr_code_id BIGINT UNSIGNED NULL,
    customer_rating TINYINT UNSIGNED NULL,
    flow_type ENUM('pending','internal_feedback','google_redirect') NOT NULL DEFAULT 'pending',
    used_pre_generated_review_id BIGINT UNSIGNED NULL,
    user_copied_review TINYINT(1) NOT NULL DEFAULT 0,
    redirected_to_google TINYINT(1) NOT NULL DEFAULT 0,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    device_type ENUM('mobile','desktop','tablet','unknown') NOT NULL DEFAULT 'unknown',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    rating_submitted_at DATETIME NULL,
    completed_at DATETIME NULL,
    CONSTRAINT fk_rs_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_rs_qr FOREIGN KEY (qr_code_id) REFERENCES client_qr_codes(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE internal_feedback (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    review_session_id BIGINT UNSIGNED NOT NULL UNIQUE,
    client_id BIGINT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    customer_name VARCHAR(120) NULL,
    customer_mobile VARCHAR(20) NULL,
    feedback_text TEXT NOT NULL,
    status ENUM('new','seen','resolved') NOT NULL DEFAULT 'new',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_if_session FOREIGN KEY (review_session_id) REFERENCES review_sessions(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_if_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE pre_generated_reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    review_text TEXT NOT NULL,
    review_hash CHAR(64) NOT NULL,
    status ENUM('unused','used') NOT NULL DEFAULT 'unused',
    generated_by ENUM('ai','manual') NOT NULL DEFAULT 'ai',
    prompt_snapshot JSON NULL,
    used_in_session_id BIGINT UNSIGNED NULL,
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used_at DATETIME NULL,
    CONSTRAINT fk_pgr_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pgr_used_session FOREIGN KEY (used_in_session_id) REFERENCES review_sessions(id) ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY uk_pgr_client_hash (client_id, review_hash)
) ENGINE=InnoDB;

CREATE TABLE ai_generation_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    trigger_source ENUM('client_registered','review_consumed','manual_refill','scheduled_check') NOT NULL,
    status ENUM('queued','processing','completed','failed') NOT NULL DEFAULT 'queued',
    requested_count INT UNSIGNED NOT NULL DEFAULT 1,
    generated_count INT UNSIGNED NOT NULL DEFAULT 0,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_jobs_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE ip_rate_limits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action_key VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    request_date DATE NOT NULL,
    hit_count INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_action_ip_date (action_key, ip_address, request_date)
) ENGINE=InnoDB;
