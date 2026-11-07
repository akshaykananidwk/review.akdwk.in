-- Catalogue addition — Insurance & Investment Advisor
--
-- Adds one business category and the 24 facilities (sub-categories) that
-- sit under it, for life-insurance / investment advisors such as
-- TATA AIA, LIC, HDFC Life and Max Life agents.
--
-- Why these names: a facility name is injected verbatim into the AI
-- prompt ("Focus only on: <facility> and <facility>") and ends up inside
-- the customer's Google review, so each one is written as a phrase that
-- reads naturally in an English sentence.
--
-- Safe to re-run. Nothing is updated or deleted; a name that already
-- exists is skipped, because facilities.facility_name is UNIQUE across
-- the whole table.

-- --------------------------------------------------------------------
-- 1. facilities.category_id — normally already present (added by
--    2026_05_09_category_facilities_upgrade.sql). Guarded so this file
--    also works on an installation where that migration never ran.
-- --------------------------------------------------------------------
SET @fac_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'facilities'
                   AND COLUMN_NAME = 'category_id');
SET @sql := IF(@fac_col = 0,
    "ALTER TABLE facilities
        ADD COLUMN category_id BIGINT UNSIGNED NULL AFTER facility_name,
        ADD CONSTRAINT fk_facilities_category FOREIGN KEY (category_id)
            REFERENCES business_categories(id) ON DELETE SET NULL ON UPDATE CASCADE",
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------
-- 2. The category itself
-- --------------------------------------------------------------------
-- FROM DUAL is required: MySQL rejects a WHERE clause on a SELECT that
-- has no FROM.
INSERT INTO business_categories (category_name, is_active, created_at)
SELECT 'Insurance & Investment Advisor', 1, NOW()
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM business_categories
    WHERE category_name = 'Insurance & Investment Advisor'
);

-- --------------------------------------------------------------------
-- 3. Its sub-categories (facilities)
--
--    Group A  (1-5)   the service lines printed on the advisor's card
--    Group B  (6-12)  the other plan types a life advisor sells
--    Group C  (13-24) the service qualities customers actually praise
-- --------------------------------------------------------------------
INSERT INTO facilities (category_id, facility_name, is_active, created_at)
SELECT bc.id, t.facility_name, 1, NOW()
FROM business_categories bc
CROSS JOIN (
    -- Group A — straight from the business card
    SELECT 'Term Insurance Planning'            AS facility_name
    UNION ALL SELECT 'Child Education Plan'
    UNION ALL SELECT 'Child Marriage Plan'
    UNION ALL SELECT 'Retirement & Pension Planning'
    UNION ALL SELECT 'Wealth Creation Plan'
    -- Group B — other plan types
    UNION ALL SELECT 'Guaranteed Return Plan'
    UNION ALL SELECT 'Money Back Policy'
    UNION ALL SELECT 'ULIP & Market Linked Plan'
    UNION ALL SELECT 'Single Premium Investment Plan'
    UNION ALL SELECT 'Tax Saving Investment Plan'
    UNION ALL SELECT 'Health & Critical Illness Cover'
    UNION ALL SELECT 'Group & Employee Insurance'
    -- Group C — service experience
    UNION ALL SELECT 'Free Financial Planning Consultation'
    UNION ALL SELECT 'Family Financial Planning'
    UNION ALL SELECT 'Claim Settlement Support'
    UNION ALL SELECT 'Nominee Claim Assistance'
    UNION ALL SELECT 'Policy Portfolio Review'
    UNION ALL SELECT 'Premium Payment Reminder Service'
    UNION ALL SELECT 'Doorstep Policy Service'
    UNION ALL SELECT 'Online Premium Payment Support'
    UNION ALL SELECT 'Quick Policy Issuance'
    UNION ALL SELECT 'Honest & Transparent Advice'
    UNION ALL SELECT 'WhatsApp Support'
    UNION ALL SELECT 'Gujarati, Hindi & English Support'
) AS t
WHERE bc.category_name = 'Insurance & Investment Advisor'
  AND NOT EXISTS (
      SELECT 1 FROM facilities f WHERE f.facility_name = t.facility_name
  );
