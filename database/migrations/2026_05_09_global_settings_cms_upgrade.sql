USE smart_review_system;

CREATE TABLE IF NOT EXISTS homepage_sliders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    image_path VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO system_settings (setting_key, setting_value, value_type, created_at, updated_at)
SELECT 'system_name', 'Krishna Review System', 'string', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'system_name');

INSERT INTO system_settings (setting_key, setting_value, value_type, created_at, updated_at)
SELECT 'support_mobile', '', 'string', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'support_mobile');

INSERT INTO system_settings (setting_key, setting_value, value_type, created_at, updated_at)
SELECT 'global_logo_path', '', 'string', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'global_logo_path');

INSERT INTO system_settings (setting_key, setting_value, value_type, created_at, updated_at)
SELECT 'homepage_how_it_works', '["Customer scans business QR","Chooses star rating","Gets guided Google review flow"]', 'json', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE setting_key = 'homepage_how_it_works');
