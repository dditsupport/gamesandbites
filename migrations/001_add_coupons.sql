-- Migration: add coupon support
-- Run this in phpMyAdmin AFTER your initial install.sql has been imported.
-- (If you're doing a fresh install, this is also safe to run after install.sql.)

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `coupons` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(30) NOT NULL UNIQUE,
  `discount_amount` DECIMAL(10,2) NOT NULL,
  `usage_limit` INT UNSIGNED DEFAULT NULL COMMENT 'NULL = unlimited',
  `used_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `expires_on` DATE DEFAULT NULL COMMENT 'NULL = never expires',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add coupon columns to bookings. Wrapped to be safe if already added.
ALTER TABLE `bookings`
  ADD COLUMN IF NOT EXISTS `coupon_id` INT UNSIGNED DEFAULT NULL AFTER `slot_rate`,
  ADD COLUMN IF NOT EXISTS `coupon_code` VARCHAR(30) DEFAULT NULL AFTER `coupon_id`,
  ADD COLUMN IF NOT EXISTS `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `coupon_code`;

-- Add foreign key only if it doesn't exist (some MySQL versions don't support IF NOT EXISTS on FK)
-- If this line errors with "duplicate key", ignore it.
ALTER TABLE `bookings`
  ADD CONSTRAINT `fk_booking_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `coupons`(`id`) ON DELETE SET NULL;
