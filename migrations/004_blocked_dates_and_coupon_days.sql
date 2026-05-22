-- Migration: blocked dates + per-weekday "coupons allowed" flags on slots
-- Run this in phpMyAdmin AFTER your initial install.sql has been imported.
-- Safe to run on a fresh install too.

SET NAMES utf8mb4;

-- 1) Blocked dates: disable ALL bookings on specific calendar dates (holidays, maintenance).
CREATE TABLE IF NOT EXISTS `blocked_dates` (
  `block_date` DATE NOT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`block_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Per-weekday coupon control on each slot.
--    1 = coupons may be applied to this slot on that weekday (default).
--    0 = coupons are disabled for this slot on that weekday.
ALTER TABLE `slots`
  ADD COLUMN IF NOT EXISTS `mon_coupon` TINYINT(1) NOT NULL DEFAULT 1 AFTER `sun_rate`,
  ADD COLUMN IF NOT EXISTS `tue_coupon` TINYINT(1) NOT NULL DEFAULT 1 AFTER `mon_coupon`,
  ADD COLUMN IF NOT EXISTS `wed_coupon` TINYINT(1) NOT NULL DEFAULT 1 AFTER `tue_coupon`,
  ADD COLUMN IF NOT EXISTS `thu_coupon` TINYINT(1) NOT NULL DEFAULT 1 AFTER `wed_coupon`,
  ADD COLUMN IF NOT EXISTS `fri_coupon` TINYINT(1) NOT NULL DEFAULT 1 AFTER `thu_coupon`,
  ADD COLUMN IF NOT EXISTS `sat_coupon` TINYINT(1) NOT NULL DEFAULT 1 AFTER `fri_coupon`,
  ADD COLUMN IF NOT EXISTS `sun_coupon` TINYINT(1) NOT NULL DEFAULT 1 AFTER `sat_coupon`;

-- If you previously ran an earlier version of this migration that created a
-- `coupon_slots` table, you can drop it (the model changed to slot weekday flags):
DROP TABLE IF EXISTS `coupon_slots`;
