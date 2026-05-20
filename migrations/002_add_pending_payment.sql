-- Migration: add admin-recorded "pending payment" note to bookings
-- Single note per booking. Independent of booking.status. Internal only.
-- Run this in phpMyAdmin AFTER your initial install.sql has been imported.

SET NAMES utf8mb4;

ALTER TABLE `bookings`
  ADD COLUMN IF NOT EXISTS `pending_amount` DECIMAL(10,2) DEFAULT NULL AFTER `admin_note`,
  ADD COLUMN IF NOT EXISTS `pending_method` ENUM('cash','upi') DEFAULT NULL AFTER `pending_amount`,
  ADD COLUMN IF NOT EXISTS `pending_remarks` TEXT DEFAULT NULL AFTER `pending_method`,
  ADD COLUMN IF NOT EXISTS `pending_recorded_at` DATETIME DEFAULT NULL AFTER `pending_remarks`;
