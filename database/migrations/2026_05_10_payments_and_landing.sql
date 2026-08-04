-- ============================================================================
--  RAZORPAY / WALLET RECHARGE + PUBLIC LANDING CMS  (idempotent)
--
--  Adds:
--    1. payment_plans         — admin-defined recharge packs (price + credits + bonus)
--    2. payment_transactions  — full audit trail of every Razorpay order/payment
--    3. system_settings keys  — Razorpay creds + landing-page CMS values
--
--  Safe to re-run.
-- ============================================================================

START TRANSACTION;

-- ----------------------------------------------------------------------------
-- 1) payment_plans  (admin-managed recharge packs)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payment_plans` (
  `id`             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(120) NOT NULL,
  `description`    VARCHAR(255) DEFAULT NULL,
  `price_inr`      INT(11) UNSIGNED NOT NULL,
  `credits`        INT(11) UNSIGNED NOT NULL,
  `bonus_credits`  INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `is_popular`     TINYINT(1) NOT NULL DEFAULT 0,
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order`     INT(11) NOT NULL DEFAULT 0,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payment_plans_active_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed three default plans only if the table is empty.
INSERT INTO `payment_plans`
  (`name`, `description`, `price_inr`, `credits`, `bonus_credits`, `is_popular`, `is_active`, `sort_order`)
SELECT * FROM (
    SELECT 'Starter Pack'   AS name, 'Best for trying the system'    AS description, 500  AS price_inr,  500 AS credits,  50 AS bonus_credits, 0 AS is_popular, 1 AS is_active, 1 AS sort_order UNION ALL
    SELECT 'Growth Pack'    AS name, 'Most popular - 20% extra'      AS description, 1000 AS price_inr, 1000 AS credits, 200 AS bonus_credits, 1 AS is_popular, 1 AS is_active, 2 AS sort_order UNION ALL
    SELECT 'Business Pack'  AS name, 'Maximum value with 40% bonus'  AS description, 2500 AS price_inr, 2500 AS credits, 1000 AS bonus_credits, 0 AS is_popular, 1 AS is_active, 3 AS sort_order
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `payment_plans` LIMIT 1);

-- ----------------------------------------------------------------------------
-- 2) payment_transactions  (full Razorpay/UPI audit ledger)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payment_transactions` (
  `id`                 BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id`          BIGINT(20) UNSIGNED NOT NULL,
  `plan_id`            BIGINT(20) UNSIGNED DEFAULT NULL,
  `gateway`            VARCHAR(40) NOT NULL DEFAULT 'razorpay',
  `gateway_order_id`   VARCHAR(120) DEFAULT NULL,
  `gateway_payment_id` VARCHAR(120) DEFAULT NULL,
  `gateway_signature`  VARCHAR(255) DEFAULT NULL,
  `amount_inr`         INT(11) UNSIGNED NOT NULL,
  `credits_credited`   INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `bonus_credited`     INT(11) UNSIGNED NOT NULL DEFAULT 0,
  `status`             ENUM('created','paid','failed','refunded','cancelled') NOT NULL DEFAULT 'created',
  `notes`              LONGTEXT DEFAULT NULL,
  `wallet_txn_id`      BIGINT(20) UNSIGNED DEFAULT NULL,
  `paid_at`            DATETIME DEFAULT NULL,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pt_gateway_order` (`gateway_order_id`),
  KEY `idx_pt_client` (`client_id`, `created_at`),
  KEY `idx_pt_status` (`status`),
  CONSTRAINT `fk_pt_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pt_plan` FOREIGN KEY (`plan_id`) REFERENCES `payment_plans` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pt_wallet_txn` FOREIGN KEY (`wallet_txn_id`) REFERENCES `wallet_transactions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3) Razorpay + landing CMS keys in system_settings
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `value_type`, `is_encrypted`, `created_at`, `updated_at`) VALUES
-- Razorpay credentials
('razorpay_enabled',           '0',                                            'bool',   0, NOW(), NOW()),
('razorpay_mode',              'test',                                         'string', 0, NOW(), NOW()),
('razorpay_key_id',            '',                                             'string', 0, NOW(), NOW()),
('razorpay_key_secret',        '',                                             'string', 1, NOW(), NOW()),
('razorpay_webhook_secret',    '',                                             'string', 1, NOW(), NOW()),

-- Public landing page CMS
('landing_hero_headline',      'Get More 5-Star Google Reviews — Automatically.', 'string', 0, NOW(), NOW()),
('landing_hero_subheadline',   'AI-powered review collection for local businesses. Customers scan a QR code, share their experience, and your Google profile shines.', 'string', 0, NOW(), NOW()),
('landing_hero_video_url',     '',                                             'string', 0, NOW(), NOW()),
('landing_live_counter_offset','0',                                            'int',    0, NOW(), NOW()),
('landing_demo_qr_token',      '',                                             'string', 0, NOW(), NOW()),
('landing_testimonials',       '[]',                                           'json',   0, NOW(), NOW()),
('landing_show_pricing',       '1',                                            'bool',   0, NOW(), NOW());

COMMIT;

-- ============================================================================
--  Verification:
--    SELECT * FROM payment_plans;
--    SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'razorpay_%' OR setting_key LIKE 'landing_%';
-- ============================================================================
