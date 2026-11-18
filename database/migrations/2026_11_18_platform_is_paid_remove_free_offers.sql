-- The platform is paid. Withdraw every free offer.
--
-- Nothing is given away any more: no Devbhumi Dwarka location offer, no
-- sign-up trial, no "FREE" wording on the public site. Code defaults
-- alone cannot do this, because these values live in system_settings and
-- a saved value always beats a PHP default.
--
-- What this does:
--   1. Deletes the special-district offer settings outright.
--   2. Sets the sign-up trial to 0 days.
--   3. Turns off the landing offer banner.
--   4. Deletes stored public copy that still promises something free, so
--      the new paid defaults in public/index.php take over.
--
-- Why delete rather than rewrite (4): the earlier migration tried to
-- swap "3 years" for "1 year" inside the saved text. That worked while a
-- number was changing. An offer being removed is not a word swap - the
-- whole sentence has to go - and deleting the row hands the page back to
-- its default, which is now written for a paid product.
--
-- Deliberately NOT done: existing clients keep the validity they were
-- already granted. Cutting off a customer who was promised free access
-- is a business decision, and Admin > Manage Businesses can change any
-- individual expiry date. This migration never touches the clients table.
--
-- Safe to re-run: deletes are idempotent and each update is conditional.

-- --------------------------------------------------------------------
-- 1. The location offer is gone, settings and all
-- --------------------------------------------------------------------
DELETE FROM system_settings
WHERE setting_key IN ('special_district_names', 'special_district_trial_days');

-- --------------------------------------------------------------------
-- 2. No trial on sign-up. A new business recharges before their QR works.
-- --------------------------------------------------------------------
INSERT INTO system_settings (setting_key, setting_value, value_type, is_encrypted)
SELECT 'signup_subscription_trial_days', '0', 'int', 0
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM system_settings WHERE setting_key = 'signup_subscription_trial_days'
);

UPDATE system_settings
SET setting_value = '0'
WHERE setting_key = 'signup_subscription_trial_days'
  AND TRIM(setting_value) <> '0';

-- --------------------------------------------------------------------
-- 3. The offer banner is off unless the admin deliberately turns it on
-- --------------------------------------------------------------------
UPDATE system_settings
SET setting_value = '0'
WHERE setting_key = 'landing_offer_enabled'
  AND TRIM(setting_value) <> '0';

-- --------------------------------------------------------------------
-- 4. Stored public copy that still advertises something free
--
--    Each row is removed only when it actually carries such a claim, so
--    copy the admin has already rewritten for a paid product is kept.
-- --------------------------------------------------------------------
DELETE FROM system_settings
WHERE setting_key IN ('landing_offer_text', 'landing_faqs', 'seo_description', 'seo_title',
                      'landing_hero_headline', 'landing_hero_subheadline')
  AND (
        setting_value LIKE '%FREE%'
     OR setting_value LIKE '%free trial%'
     OR setting_value LIKE '%Free trial%'
     OR setting_value LIKE '%free plan%'
     OR setting_value LIKE '%ફ્રી%'
     OR setting_value LIKE '%મફત%'
     OR setting_value LIKE '%બિલકુલ%'
  );
