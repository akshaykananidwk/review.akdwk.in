-- Recharge plans: mobile-style validity (days) bundled with wallet credits.
-- Run once on production DB.

ALTER TABLE `payment_plans`
  ADD COLUMN `duration_days` int(11) unsigned NOT NULL DEFAULT 30
  AFTER `bonus_credits`;
