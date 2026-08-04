USE smart_review_system;

ALTER TABLE clients
    ADD COLUMN wallet_balance INT NOT NULL DEFAULT 0 AFTER monthly_quota_limit;

-- Keep DB session in IST for admin/manual SQL usage where supported.
SET time_zone = '+05:30';
