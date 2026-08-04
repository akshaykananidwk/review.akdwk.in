-- ============================================================================
-- Security/features: standee business-name overlay + WhatsApp notify settings
-- Idempotent where possible (checks information_schema).
-- Run after prior standee_qr_box migration.
-- ============================================================================

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- standee_templates: optional business name overlay (template-relative px)
-- ---------------------------------------------------------------------------
SET @has_bn := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'standee_templates'
      AND COLUMN_NAME = 'business_name_enabled'
);
SET @ddl_bn := IF(@has_bn = 0,
    "ALTER TABLE standee_templates
       ADD COLUMN business_name_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER qr_height,
       ADD COLUMN business_name_pos_x INT NOT NULL DEFAULT 0 AFTER business_name_enabled,
       ADD COLUMN business_name_pos_y INT NOT NULL DEFAULT 0 AFTER business_name_pos_x,
       ADD COLUMN business_name_box_w INT NOT NULL DEFAULT 0 AFTER business_name_pos_y,
       ADD COLUMN business_name_box_h INT NOT NULL DEFAULT 0 AFTER business_name_box_w,
       ADD COLUMN business_name_font_pt INT NOT NULL DEFAULT 36 AFTER business_name_box_h,
       ADD COLUMN business_name_color VARCHAR(16) NOT NULL DEFAULT '#0f172a' AFTER business_name_font_pt",
    'SELECT 1');
PREPARE stmt_bn FROM @ddl_bn;
EXECUTE stmt_bn;
DEALLOCATE PREPARE stmt_bn;

-- ---------------------------------------------------------------------------
-- clients: extra WhatsApp numbers + daily summary opt-out
-- ---------------------------------------------------------------------------
SET @has_ex := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'clients'
      AND COLUMN_NAME = 'extra_whatsapp_numbers'
);
SET @ddl_ex := IF(@has_ex = 0,
    'ALTER TABLE clients
       ADD COLUMN extra_whatsapp_numbers VARCHAR(600) NULL AFTER mobile,
       ADD COLUMN daily_summary_whatsapp_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER extra_whatsapp_numbers',
    'SELECT 1');
PREPARE stmt_ex FROM @ddl_ex;
EXECUTE stmt_ex;
DEALLOCATE PREPARE stmt_ex;

COMMIT;
