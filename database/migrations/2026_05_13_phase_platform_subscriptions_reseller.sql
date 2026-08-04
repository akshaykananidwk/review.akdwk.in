-- =============================================================================
-- Phase: Subscriptions + API keys + invite template + Reseller wallet module
-- MySQL 5.7+ / 8+ / MariaDB 10.x — re-run safe for CREATE IF NOT EXISTS tables;
-- ALTER blocks may error if already applied (safe to ignore duplicate column/key).
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 1) Subscription catalog
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subscription_plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    billing_period ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
    price_inr INT UNSIGNED NOT NULL,
    duration_days INT UNSIGNED NOT NULL DEFAULT 30,
    description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_sub_plans_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO subscription_plans (id, name, billing_period, price_inr, duration_days, description, is_active, sort_order, created_at, updated_at)
VALUES
    (1, 'Platform Monthly', 'monthly', 499, 30, 'SaaS platform access — billed monthly', 1, 1, NOW(), NOW()),
    (2, 'Platform Yearly', 'yearly', 4999, 365, 'SaaS platform access — billed yearly (best value)', 1, 2, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    billing_period = VALUES(billing_period),
    price_inr = VALUES(price_inr),
    duration_days = VALUES(duration_days),
    description = VALUES(description),
    is_active = VALUES(is_active),
    sort_order = VALUES(sort_order),
    updated_at = NOW();

-- ---------------------------------------------------------------------------
-- 2) Subscription Razorpay orders
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subscription_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    subscription_plan_id BIGINT UNSIGNED NOT NULL,
    gateway VARCHAR(40) NOT NULL DEFAULT 'razorpay',
    gateway_order_id VARCHAR(120) NULL,
    gateway_payment_id VARCHAR(120) NULL,
    gateway_signature VARCHAR(255) NULL,
    amount_inr INT UNSIGNED NOT NULL,
    status ENUM('created','paid','failed') NOT NULL DEFAULT 'created',
    notes LONGTEXT NULL,
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_subord_gateway_order (gateway_order_id),
    KEY idx_subord_client (client_id, created_at),
    KEY idx_subord_status (status),
    CONSTRAINT fk_subord_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_subord_plan FOREIGN KEY (subscription_plan_id) REFERENCES subscription_plans(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3) clients — new columns (skip statement if "Duplicate column name")
-- ---------------------------------------------------------------------------
ALTER TABLE clients ADD COLUMN subscription_plan_id BIGINT UNSIGNED NULL AFTER wallet_balance;
ALTER TABLE clients ADD COLUMN subscription_valid_until DATE NULL AFTER subscription_plan_id;
ALTER TABLE clients ADD COLUMN api_key CHAR(48) NULL AFTER subscription_valid_until;
ALTER TABLE clients ADD COLUMN reseller_id BIGINT UNSIGNED NULL AFTER api_key;

-- Indexes / FKs (skip if "Duplicate key name" / "already exists")
ALTER TABLE clients ADD UNIQUE KEY uk_clients_api_key (api_key);
ALTER TABLE clients ADD CONSTRAINT fk_clients_subscription_plan FOREIGN KEY (subscription_plan_id) REFERENCES subscription_plans(id) ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE clients ADD CONSTRAINT fk_clients_reseller FOREIGN KEY (reseller_id) REFERENCES admins(id) ON DELETE SET NULL ON UPDATE CASCADE;

-- Existing rows: grant 365 days from migration date if still null
UPDATE clients SET subscription_valid_until = DATE_ADD(CURDATE(), INTERVAL 365 DAY)
WHERE subscription_valid_until IS NULL;

-- ---------------------------------------------------------------------------
-- 4) admins — reseller role + wallet
-- ---------------------------------------------------------------------------
ALTER TABLE admins MODIFY COLUMN role ENUM('super_admin','reseller') NOT NULL DEFAULT 'super_admin';
ALTER TABLE admins ADD COLUMN reseller_wallet_balance INT NOT NULL DEFAULT 0 AFTER is_active;

CREATE TABLE IF NOT EXISTS reseller_wallet_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reseller_admin_id BIGINT UNSIGNED NOT NULL,
    txn_type ENUM('credit','debit') NOT NULL,
    amount INT NOT NULL,
    balance_after INT NOT NULL,
    source VARCHAR(80) NOT NULL,
    description VARCHAR(255) NULL,
    related_client_id BIGINT UNSIGNED NULL,
    related_super_admin_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rwt_reseller (reseller_admin_id, created_at),
    CONSTRAINT fk_rwt_reseller FOREIGN KEY (reseller_admin_id) REFERENCES admins(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_rwt_client FOREIGN KEY (related_client_id) REFERENCES clients(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_rwt_super FOREIGN KEY (related_super_admin_id) REFERENCES admins(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5) Default system_settings keys
-- ---------------------------------------------------------------------------
INSERT INTO system_settings (setting_key, setting_value, value_type, is_encrypted, created_at, updated_at)
VALUES
    ('default_welcome_standee_template_id', '0', 'int', 0, NOW(), NOW()),
    ('signup_subscription_trial_days', '30', 'int', 0, NOW(), NOW()),
    ('review_invite_message_template', 'Hi {name}, thank you for visiting us. Please rate your experience: {link}', 'string', 0, NOW(), NOW())
ON DUPLICATE KEY UPDATE updated_at = updated_at;
