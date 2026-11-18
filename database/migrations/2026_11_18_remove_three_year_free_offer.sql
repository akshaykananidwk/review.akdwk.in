-- Withdraw the three-year free offer
--
-- The Devbhumi Dwarka offer was built as "free for 3 years". That is no
-- longer being given to anyone, so the promise has to go from the live
-- site as well as from the code defaults — changing a default in PHP
-- does nothing once a value is saved in system_settings, and these
-- values are saved from Admin > Global Settings.
--
-- Three things are corrected here:
--   1. special_district_trial_days 1095 -> 365, but ONLY if it is still
--      the old default. An admin who has deliberately set some other
--      number keeps it.
--   2. Any stored landing_offer_text still advertising 3 years.
--   3. Any stored landing_faqs still advertising 3 years.
--
-- Clients who were ALREADY granted the long validity are deliberately
-- left alone. Shortening access somebody has already been given is a
-- business decision, not a migration, and it would silently cut off a
-- live customer. Admin > Manage Businesses can change any individual
-- expiry date.
--
-- Safe to re-run: every statement is conditional on the old text still
-- being present.

-- --------------------------------------------------------------------
-- 1. The granted duration
-- --------------------------------------------------------------------
UPDATE system_settings
SET setting_value = '365'
WHERE setting_key = 'special_district_trial_days'
  AND TRIM(setting_value) = '1095';

-- --------------------------------------------------------------------
-- 2. The offer banner (Gujarati). Covers the shipped default and the
--    two spellings of "three years" someone may have typed.
-- --------------------------------------------------------------------
UPDATE system_settings
SET setting_value = REPLACE(setting_value, '3 વર્ષ', '1 વર્ષ')
WHERE setting_key = 'landing_offer_text'
  AND setting_value LIKE '%3 વર્ષ%';

UPDATE system_settings
SET setting_value = REPLACE(setting_value, 'ત્રણ વર્ષ', '1 વર્ષ')
WHERE setting_key = 'landing_offer_text'
  AND setting_value LIKE '%ત્રણ વર્ષ%';

UPDATE system_settings
SET setting_value = REPLACE(setting_value, '3 years', '1 year')
WHERE setting_key = 'landing_offer_text'
  AND setting_value LIKE '%3 years%';

-- --------------------------------------------------------------------
-- 3. The FAQ JSON
-- --------------------------------------------------------------------
UPDATE system_settings
SET setting_value = REPLACE(setting_value, 'up to 3 years', '1 year')
WHERE setting_key = 'landing_faqs'
  AND setting_value LIKE '%up to 3 years%';

UPDATE system_settings
SET setting_value = REPLACE(setting_value, '3 years', '1 year')
WHERE setting_key = 'landing_faqs'
  AND setting_value LIKE '%3 years%';

UPDATE system_settings
SET setting_value = REPLACE(setting_value, 'three years', '1 year')
WHERE setting_key = 'landing_faqs'
  AND setting_value LIKE '%three years%';

-- --------------------------------------------------------------------
-- 4. The same claim anywhere else the admin can edit public copy
-- --------------------------------------------------------------------
UPDATE system_settings
SET setting_value = REPLACE(REPLACE(REPLACE(
        setting_value, '3 years', '1 year'), '3 વર્ષ', '1 વર્ષ'), 'ત્રણ વર્ષ', '1 વર્ષ')
WHERE setting_key IN (
        'seo_title', 'seo_description', 'seo_keywords',
        'landing_hero_headline', 'landing_hero_subheadline',
        'homepage_how_it_works', 'landing_testimonials'
      )
  AND (setting_value LIKE '%3 years%' OR setting_value LIKE '%3 વર્ષ%' OR setting_value LIKE '%ત્રણ વર્ષ%');
