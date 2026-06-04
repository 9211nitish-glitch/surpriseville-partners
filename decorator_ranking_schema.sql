-- ============================================================
-- Decorator Ranking System - Database Schema
-- ============================================================
-- This file contains all tables needed for the decorator ranking,
-- video review, and unified order management system.
-- ============================================================

-- 1. DECORATOR RANKINGS TABLE
-- Stores the aggregated ranking and points for each decorator
CREATE TABLE IF NOT EXISTS `decorator_rankings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vendor_id` int(11) NOT NULL,
  `total_points` decimal(5,2) NOT NULL DEFAULT 0.00,
  `client_satisfaction_points` decimal(5,2) NOT NULL DEFAULT 0.00,
  `video_review_points` decimal(5,2) NOT NULL DEFAULT 0.00,
  `grooming_points` decimal(5,2) NOT NULL DEFAULT 0.00,
  `completion_time_points` decimal(5,2) NOT NULL DEFAULT 0.00,
  `medal_tier` enum('bronze','silver','gold','none') NOT NULL DEFAULT 'none',
  `total_ratings_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vendor_id` (`vendor_id`),
  KEY `idx_total_points` (`total_points` DESC),
  KEY `idx_medal_tier` (`medal_tier`),
  CONSTRAINT `fk_rankings_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. DECORATOR RATINGS TABLE
-- Individual rating entries from clients/admins
CREATE TABLE IF NOT EXISTS `decorator_ratings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vendor_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `client_satisfaction_points` decimal(2,2) NOT NULL DEFAULT 0.00,
  `video_submitted` enum('yes','no') NOT NULL DEFAULT 'no',
  `video_review_points` decimal(2,2) NOT NULL DEFAULT 0.00,
  `grooming_points` decimal(2,2) NOT NULL DEFAULT 0.00,
  `completion_time_points` decimal(2,2) NOT NULL DEFAULT 0.00,
  `total_rating_points` decimal(5,2) NOT NULL DEFAULT 0.00,
  `rated_by_admin_id` int(11) DEFAULT NULL,
  `comments` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `vendor_id` (`vendor_id`),
  KEY `order_id` (`order_id`),
  KEY `rated_by_admin_id` (`rated_by_admin_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_ratings_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ratings_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. DECORATOR VIDEOS TABLE
-- Stores video submissions (before, after, vendor selfie)
CREATE TABLE IF NOT EXISTS `decorator_videos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vendor_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `before_video_url` varchar(500),
  `after_video_url` varchar(500),
  `vendor_selfie_url` varchar(500),
  `video_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `approval_notes` text,
  `approved_by_admin_id` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `vendor_id` (`vendor_id`),
  KEY `order_id` (`order_id`),
  KEY `video_status` (`video_status`),
  KEY `approved_by_admin_id` (`approved_by_admin_id`),
  CONSTRAINT `fk_videos_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_videos_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. DECORATOR VIDEO PORTFOLIO TABLE
-- Stores approved videos for vendor's public portfolio
CREATE TABLE IF NOT EXISTS `decorator_video_portfolio` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vendor_id` int(11) NOT NULL,
  `video_id` int(11) NOT NULL,
  `before_video_url` varchar(500),
  `after_video_url` varchar(500),
  `vendor_selfie_url` varchar(500),
  `title` varchar(255),
  `description` text,
  `project_category` varchar(255),
  `display_order` int(11) DEFAULT 0,
  `is_featured` tinyint(1) DEFAULT 0,
  `views_count` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `video_id` (`video_id`),
  KEY `vendor_id` (`vendor_id`),
  KEY `is_featured` (`is_featured`),
  KEY `idx_display_order` (`display_order`),
  CONSTRAINT `fk_portfolio_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_portfolio_video` FOREIGN KEY (`video_id`) REFERENCES `decorator_videos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. UNIFIED ORDERS TABLE (MODIFICATIONS)
-- Add columns to existing orders table if they don't exist
-- These enable offline/online order management
ALTER TABLE `orders` ADD COLUMN `order_source` enum('online','offline') NOT NULL DEFAULT 'online' AFTER `id`;
ALTER TABLE `orders` ADD COLUMN `broadcast_status` enum('draft','broadcast','assigned') NOT NULL DEFAULT 'assigned' AFTER `order_source`;
ALTER TABLE `orders` ADD COLUMN `posted_by_admin_id` int(11) DEFAULT NULL AFTER `broadcast_status`;
ALTER TABLE `orders` ADD KEY `idx_order_source` (`order_source`);
ALTER TABLE `orders` ADD KEY `idx_broadcast_status` (`broadcast_status`);

-- 6. ORDER BROADCAST HISTORY TABLE
-- Tracks when orders are reposted/broadcast to vendors
CREATE TABLE IF NOT EXISTS `order_broadcast_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `broadcast_type` enum('manual','auto','repost') NOT NULL,
  `broadcast_to_vendor_ids` longtext,
  `posted_by_admin_id` int(11),
  `broadcast_status` enum('pending','sent','partial') NOT NULL DEFAULT 'sent',
  `vendor_count` int(11),
  `acceptance_count` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `posted_by_admin_id` (`posted_by_admin_id`),
  CONSTRAINT `fk_broadcast_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. RANKING UPDATE AUDIT LOG
-- Tracks all point assignments and ranking changes
CREATE TABLE IF NOT EXISTS `ranking_audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vendor_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `action` enum('points_assigned','points_updated','points_removed','medal_assigned','video_approved') NOT NULL,
  `points_before` decimal(5,2),
  `points_after` decimal(5,2),
  `changed_by_admin_id` int(11),
  `reason` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `vendor_id` (`vendor_id`),
  KEY `order_id` (`order_id`),
  KEY `changed_by_admin_id` (`changed_by_admin_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_audit_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- End of Schema
-- ============================================================
