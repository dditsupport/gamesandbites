-- Games N Bites Booking System
-- Run once in phpMyAdmin (MilesWeb cPanel > phpMyAdmin) after creating the database

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `admins` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Slot templates (apply to every day). end_time < start_time => crosses midnight.
CREATE TABLE IF NOT EXISTS `slots` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `crosses_midnight` TINYINT(1) NOT NULL DEFAULT 0,
  `rate` DECIMAL(10,2) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `coupons` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(30) NOT NULL UNIQUE,
  `discount_amount` DECIMAL(10,2) NOT NULL,
  `usage_limit` INT UNSIGNED DEFAULT NULL,
  `used_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `expires_on` DATE DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bookings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_code` VARCHAR(20) NOT NULL UNIQUE,
  `slot_id` INT UNSIGNED NOT NULL,
  `booking_date` DATE NOT NULL,
  `customer_name` VARCHAR(100) NOT NULL,
  `customer_mobile` VARCHAR(20) NOT NULL,
  `slot_rate` DECIMAL(10,2) NOT NULL,
  `coupon_id` INT UNSIGNED DEFAULT NULL,
  `coupon_code` VARCHAR(30) DEFAULT NULL,
  `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `advance_amount` DECIMAL(10,2) NOT NULL DEFAULT 500.00,
  `payment_method` ENUM('cash','upi') NOT NULL,
  `upi_utr` VARCHAR(50) DEFAULT NULL,
  `upi_screenshot` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
  `admin_note` TEXT DEFAULT NULL,
  `pending_amount` DECIMAL(10,2) DEFAULT NULL,
  `pending_method` ENUM('cash','upi') DEFAULT NULL,
  `pending_remarks` TEXT DEFAULT NULL,
  `pending_recorded_at` DATETIME DEFAULT NULL,
  `extra_discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `extra_discount_reason` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_date_slot` (`booking_date`,`slot_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_booking_slot` FOREIGN KEY (`slot_id`) REFERENCES `slots`(`id`),
  CONSTRAINT `fk_booking_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `coupons`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `settings` (
  `key_name` VARCHAR(50) NOT NULL,
  `value` TEXT,
  PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `settings` (`key_name`,`value`) VALUES
  ('venue_name','Games N Bites'),
  ('venue_address','Nr. Nana Chiloda Ringroad Circle, Ranasan, Ahmedabad'),
  ('upi_id','yourname@upi'),
  ('upi_payee_name','Games N Bites'),
  ('upi_qr_image',''),
  ('advance_amount','500'),
  ('contact_phone',''),
  ('ntfy_topic',''),
  ('ntfy_server','https://ntfy.sh');

-- Default admin: username=admin / password=admin123  (CHANGE IMMEDIATELY AFTER LOGIN)
INSERT IGNORE INTO `admins` (`username`,`password_hash`) VALUES
  ('admin','$2b$10$Rhe8d4QVNiBdGvMsCB3ov.hB8Wt1LP/vhDQe1KHKoXrTLMNoynG6C');

SET FOREIGN_KEY_CHECKS = 1;
