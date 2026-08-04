-- =====================================================================
-- Migration: WhatsApp API integration + Forgot Password (OTP via WA)
-- Date: 2026-05-09
-- Idempotent: safe to re-run.
-- =====================================================================

USE sql_review_akdwk_in;

-- ---------------------------------------------------------------------
-- 1) Password reset OTP table (used by both admins and clients)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_type ENUM('admin','client') NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    mobile VARCHAR(20) NOT NULL,
    email VARCHAR(190) NOT NULL,
    otp_hash CHAR(64) NOT NULL,
    reset_token CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used_at DATETIME NULL,
    UNIQUE KEY uk_reset_token (reset_token),
    KEY idx_pr_user (user_type, user_id),
    KEY idx_pr_mobile (mobile),
    KEY idx_pr_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2) Add `mobile` column to admins so forgot-password OTP can reach them.
--    Idempotent ALTER (uses INFORMATION_SCHEMA pre-check).
-- ---------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'admins'
      AND COLUMN_NAME = 'mobile'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE admins ADD COLUMN mobile VARCHAR(20) NULL AFTER email',
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 3) Default WhatsApp gateway settings (key/value rows in system_settings)
--    Only inserted if key does NOT already exist (preserves admin edits).
-- ---------------------------------------------------------------------
INSERT INTO system_settings (setting_key, setting_value, value_type, is_encrypted, created_at, updated_at)
VALUES
('whatsapp_endpoint',   'https://bulk.akdwk.in/api.php',                 'string', 0, NOW(), NOW()),
('whatsapp_api_key',    'c9f5b590100fc385c31b',                          'string', 1, NOW(), NOW()),
('whatsapp_session_id', 'user_4835_1774094200_1776318138',               'string', 1, NOW(), NOW()),
('whatsapp_instance_id','',                                              'string', 0, NOW(), NOW()),
('whatsapp_token',      '',                                              'string', 1, NOW(), NOW()),
('whatsapp_sender_name','Krishna Review System',                         'string', 0, NOW(), NOW()),
('whatsapp_country_code','91',                                           'string', 0, NOW(), NOW()),
('helpline_number',     '',                                              'string', 0, NOW(), NOW())
ON DUPLICATE KEY UPDATE setting_key = setting_key;
