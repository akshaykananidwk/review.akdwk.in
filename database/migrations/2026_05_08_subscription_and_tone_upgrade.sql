USE smart_review_system;

ALTER TABLE clients
    ADD COLUMN review_tone VARCHAR(30) NOT NULL DEFAULT 'Professional' AFTER review_logic_type,
    ADD COLUMN monthly_quota_limit INT NULL DEFAULT NULL AFTER review_tone,
    ADD COLUMN monthly_quota_used INT NOT NULL DEFAULT 0 AFTER monthly_quota_limit,
    ADD COLUMN quota_usage_month CHAR(7) NULL AFTER monthly_quota_used;
