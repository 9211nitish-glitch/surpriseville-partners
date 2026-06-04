#!/bin/bash
###############################################################################
# Decorator Ranking System - Plesk Server Setup Script
# 
# This script automatically:
# 1. Extracts database credentials from db.php
# 2. Connects to your Plesk MySQL database
# 3. Creates all required tables
# 4. Initializes vendor rankings
# 5. Sets up upload directories
###############################################################################

set -e  # Exit on error

# Color codes for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging function
log() {
    echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1"
}

success() {
    echo -e "${GREEN}✓${NC} $1"
}

error() {
    echo -e "${RED}✗${NC} $1"
}

warning() {
    echo -e "${YELLOW}!${NC} $1"
}

# Step 1: Extract database credentials from db.php
log "Extracting database credentials from db.php..."

if [ ! -f "db.php" ]; then
    error "db.php not found in current directory"
    exit 1
fi

# Extract values using grep and sed
DB_HOST=$(grep -oP '\$db_host\s*=\s*[\'\"]\K[^\'\"]*' db.php)
DB_USER=$(grep -oP '\$db_user\s*=\s*[\'\"]\K[^\'\"]*' db.php)
DB_PASS=$(grep -oP '\$db_password\s*=\s*[\'\"]\K[^\'\"]*' db.php)
DB_NAME=$(grep -oP '\$db_name\s*=\s*[\'\"]\K[^\'\"]*' db.php)

if [ -z "$DB_HOST" ] || [ -z "$DB_NAME" ] || [ -z "$DB_USER" ]; then
    error "Could not extract database credentials from db.php"
    exit 1
fi

success "Credentials loaded: Host=$DB_HOST, Database=$DB_NAME, User=$DB_USER"

# Step 2: Check MySQL connection
log "Testing database connection..."

mysql_test=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT 1" 2>&1)

if [ $? -ne 0 ]; then
    error "Could not connect to database"
    echo "$mysql_test"
    exit 1
fi

success "Database connection successful"

# Step 3: Create tables
log "Creating decorator ranking tables..."

mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" << 'EOF'

-- 1. DECORATOR RANKINGS TABLE
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

-- 5. ORDER BROADCAST HISTORY TABLE
CREATE TABLE IF NOT EXISTS `order_broadcast_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `broadcast_type` enum('manual','auto','repost') NOT NULL,
  `broadcast_to_vendor_ids` longtext,
  `posted_by_admin_id` int(11) DEFAULT NULL,
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

-- 6. RANKING AUDIT LOG TABLE
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

EOF

if [ $? -eq 0 ]; then
    success "Tables created successfully"
else
    error "Failed to create tables"
    exit 1
fi

# Step 4: Modify orders table
log "Adding columns to orders table..."

mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" << 'EOF'

SET @db_name = DATABASE();

-- Add columns if they don't exist
ALTER TABLE `orders` 
ADD COLUMN `order_source` enum('online','offline') NOT NULL DEFAULT 'online' AFTER `id`,
ADD COLUMN `broadcast_status` enum('draft','broadcast','assigned') NOT NULL DEFAULT 'assigned' AFTER `order_source`,
ADD COLUMN `posted_by_admin_id` int(11) DEFAULT NULL AFTER `broadcast_status`,
ADD KEY `idx_order_source` (`order_source`),
ADD KEY `idx_broadcast_status` (`broadcast_status`);

EOF

success "Orders table modified"

# Step 5: Initialize vendors
log "Initializing vendors in ranking system..."

mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" << 'EOF'

INSERT INTO `decorator_rankings` (
    `vendor_id`, `total_points`, `client_satisfaction_points`,
    `video_review_points`, `grooming_points`, `completion_time_points`,
    `medal_tier`, `total_ratings_count`
)
SELECT 
    `id`, 0.00, 0.00, 0.00, 0.00, 0.00, 'none', 0
FROM `vendors`
WHERE `id` NOT IN (SELECT DISTINCT `vendor_id` FROM `decorator_rankings`)
ON DUPLICATE KEY UPDATE 
    `total_points` = 0.00,
    `medal_tier` = 'none';

EOF

success "Vendors initialized"

# Step 6: Create upload directories
log "Creating upload directories..."

mkdir -p uploads/decorator-videos
chmod 755 uploads/decorator-videos

if [ -d "uploads/decorator-videos" ]; then
    success "Upload directories created with proper permissions"
else
    error "Failed to create upload directories"
fi

# Step 7: Summary
log "=========================================="
success "SETUP COMPLETE!"
log "=========================================="

echo ""
log "Next steps:"
log "1. Verify installation: Visit /check_installation.php"
log "2. Access admin panel: /admin/decorator_rankings.php"
log "3. View top decorators: /public_top_decorators.php"
log "4. Upload files to your server if needed"

echo ""
success "Database is ready for use!"
