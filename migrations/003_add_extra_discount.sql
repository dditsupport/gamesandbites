-- Migration: add admin "extra discount" / total adjustment to bookings
-- Use case: customer didn't pay full amount, or admin granted extra discount
-- beyond any coupon. Stored as a positive amount that is subtracted from the
-- computed total (slot_rate - discount_amount - extra_discount).

SET NAMES utf8mb4;

ALTER TABLE `bookings`
  ADD COLUMN IF NOT EXISTS `extra_discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `pending_recorded_at`,
  ADD COLUMN IF NOT EXISTS `extra_discount_reason` VARCHAR(255) DEFAULT NULL AFTER `extra_discount`;
