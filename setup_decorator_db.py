#!/usr/bin/env python3
"""
Decorator Ranking System - Database Setup & Update Script
For Plesk Servers

This script:
1. Reads database credentials from db.php
2. Connects to your MySQL database
3. Creates all required tables for decorator ranking system
4. Initializes existing vendors
5. Provides detailed status report
"""

import re
import sys
import json
from pathlib import Path

try:
    import mysql.connector
    from mysql.connector import Error
except ImportError:
    print("ERROR: mysql-connector-python is not installed")
    print("Install with: pip install mysql-connector-python")
    sys.exit(1)


class DatabaseUpdater:
    """Handle database operations for decorator ranking system"""
    
    def __init__(self, config_file='db.php'):
        """Initialize with database config from PHP file"""
        self.host = None
        self.user = None
        self.password = None
        self.database = None
        self.port = 3306
        self.connection = None
        self.cursor = None
        self.config_file = config_file
        self.logs = []
        
    def log(self, message, level='INFO'):
        """Log messages with level"""
        timestamp = self.get_timestamp()
        log_entry = f"[{timestamp}] [{level}] {message}"
        self.logs.append(log_entry)
        print(log_entry)
        
    def get_timestamp(self):
        """Get current timestamp"""
        from datetime import datetime
        return datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    
    def parse_db_config(self):
        """Parse database credentials from db.php"""
        try:
            with open(self.config_file, 'r') as f:
                content = f.read()
            
            # Extract database credentials using regex
            patterns = {
                'host': r"\$host\s*=\s*['\"]([^'\"]+)['\"]",
                'user': r"\$user\s*=\s*['\"]([^'\"]+)['\"]",
                'password': r"\$pass\s*=\s*['\"]([^'\"]+)['\"]",
                'database': r"\$db_name\s*=\s*['\"]([^'\"]+)['\"]",
            }
            
            for key, pattern in patterns.items():
                match = re.search(pattern, content)
                if match:
                    setattr(self, key, match.group(1))
                else:
                    self.log(f"Could not find {key} in {self.config_file}", 'WARNING')
            
            if not all([self.host, self.user, self.database]):
                self.log("Missing required database credentials", 'ERROR')
                return False
            
            self.log(f"Config loaded: Host={self.host}, DB={self.database}, User={self.user}")
            return True
            
        except FileNotFoundError:
            self.log(f"Config file not found: {self.config_file}", 'ERROR')
            return False
        except Exception as e:
            self.log(f"Error parsing config: {str(e)}", 'ERROR')
            return False
    
    def connect(self):
        """Connect to MySQL database"""
        try:
            self.connection = mysql.connector.connect(
                host=self.host,
                user=self.user,
                password=self.password if self.password else '',
                database=self.database,
                port=self.port,
                autocommit=False
            )
            
            self.cursor = self.connection.cursor()
            self.log(f"Connected to {self.database} @ {self.host}")
            return True
            
        except Error as e:
            self.log(f"Connection failed: {str(e)}", 'ERROR')
            return False
    
    def disconnect(self):
        """Close database connection"""
        if self.cursor:
            self.cursor.close()
        if self.connection:
            self.connection.close()
        self.log("Disconnected from database")
    
    def execute_query(self, query, params=None):
        """Execute a single query"""
        try:
            if params:
                self.cursor.execute(query, params)
            else:
                self.cursor.execute(query)
            return True
        except Error as e:
            self.log(f"Query failed: {str(e)}", 'ERROR')
            self.log(f"Query: {query}", 'DEBUG')
            return False
    
    def table_exists(self, table_name):
        """Check if table exists"""
        try:
            query = f"SHOW TABLES LIKE '{table_name}'"
            self.cursor.execute(query)
            return self.cursor.fetchone() is not None
        except Error as e:
            self.log(f"Error checking table: {str(e)}", 'ERROR')
            return False
    
    def create_decorator_ranking_tables(self):
        """Create all decorator ranking tables"""
        self.log("Creating decorator ranking tables...", 'INFO')
        
        tables_created = 0
        tables_skipped = 0
        
        tables = {
            'decorator_rankings': """
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            """,
            
            'decorator_ratings': """
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
                  CONSTRAINT `fk_ratings_order` FOREIGN KEY (`order_id`) REFERENCES `manual_tasks` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            """,
            
            'decorator_videos': """
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
                  CONSTRAINT `fk_videos_order` FOREIGN KEY (`order_id`) REFERENCES `manual_tasks` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            """,
            
            'decorator_video_portfolio': """
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            """,
            
            'order_broadcast_history': """
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
                  CONSTRAINT `fk_broadcast_order` FOREIGN KEY (`order_id`) REFERENCES `manual_tasks` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            """,
            
            'ranking_audit_log': """
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            """
        }
        
        for table_name, query in tables.items():
            if self.table_exists(table_name):
                self.log(f"Table already exists: {table_name}", 'INFO')
                tables_skipped += 1
            else:
                if self.execute_query(query):
                    self.log(f"Created table: {table_name}", 'SUCCESS')
                    tables_created += 1
                else:
                    self.log(f"Failed to create table: {table_name}", 'ERROR')
        
        self.log(f"\nTables created: {tables_created}, Skipped: {tables_skipped}", 'INFO')
        return tables_created > 0 or tables_skipped > 0
    
    def add_order_columns(self):
        """Add new columns to manual_tasks table if they don't exist"""
        self.log("Checking manual_tasks table columns...", 'INFO')
        
        columns_to_add = [
            ('order_source', "enum('online','offline') NOT NULL DEFAULT 'online'"),
            ('broadcast_status', "enum('draft','broadcast','assigned') NOT NULL DEFAULT 'assigned'"),
            ('posted_by_admin_id', "int(11) DEFAULT NULL")
        ]
        
        columns_added = 0
        
        try:
            # Get existing columns
            self.cursor.execute("DESCRIBE manual_tasks")
            existing_columns = {row[0] for row in self.cursor.fetchall()}
            
            for col_name, col_def in columns_to_add:
                if col_name in existing_columns:
                    self.log(f"Column already exists: {col_name}", 'INFO')
                else:
                    query = f"ALTER TABLE `manual_tasks` ADD COLUMN `{col_name}` {col_def}"
                    if self.execute_query(query):
                        self.log(f"Added column: {col_name}", 'SUCCESS')
                        columns_added += 1
                    else:
                        self.log(f"Failed to add column: {col_name}", 'ERROR')
            
            # Add indexes
            self.log("Adding indexes to manual_tasks table...", 'INFO')
            indexes = [
                ("ALTER TABLE `manual_tasks` ADD KEY `idx_order_source` (`order_source`)", "idx_order_source"),
                ("ALTER TABLE `manual_tasks` ADD KEY `idx_broadcast_status` (`broadcast_status`)", "idx_broadcast_status")
            ]
            
            for query, index_name in indexes:
                try:
                    self.execute_query(query)
                    self.log(f"Added index: {index_name}", 'SUCCESS')
                except:
                    self.log(f"Index may already exist: {index_name}", 'INFO')
            
            return True
            
        except Error as e:
            self.log(f"Error modifying manual_tasks table: {str(e)}", 'ERROR')
            return False
    
    def initialize_vendors(self):
        """Initialize all existing vendors in decorator_rankings table"""
        self.log("Initializing vendors in ranking system...", 'INFO')
        
        try:
            # Get all vendors
            self.cursor.execute("SELECT COUNT(*) as total FROM vendors")
            total_vendors = self.cursor.fetchone()[0]
            
            if total_vendors == 0:
                self.log("No vendors found in database", 'WARNING')
                return True
            
            # Insert vendors into rankings if not already there
            query = """
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
                    `medal_tier` = 'none'
            """
            
            self.cursor.execute(query)
            rows_affected = self.cursor.rowcount
            
            self.log(f"Initialized {rows_affected} vendor(s)", 'SUCCESS')
            
            # Get current ranking count
            self.cursor.execute("SELECT COUNT(*) as total FROM decorator_rankings")
            ranking_count = self.cursor.fetchone()[0]
            
            self.log(f"Total vendors in ranking system: {ranking_count}/{total_vendors}", 'INFO')
            
            return True
            
        except Error as e:
            self.log(f"Error initializing vendors: {str(e)}", 'ERROR')
            return False
    
    def commit_changes(self):
        """Commit all database changes"""
        try:
            self.connection.commit()
            self.log("All changes committed to database", 'SUCCESS')
            return True
        except Error as e:
            self.log(f"Error committing changes: {str(e)}", 'ERROR')
            self.connection.rollback()
            return False
    
    def run_full_setup(self):
        """Run complete database setup"""
        self.log("=" * 60, 'INFO')
        self.log("DECORATOR RANKING SYSTEM - DATABASE SETUP", 'INFO')
        self.log("=" * 60, 'INFO')
        
        # Step 1: Parse config
        if not self.parse_db_config():
            self.log("Setup failed: Could not load database config", 'ERROR')
            return False
        
        # Step 2: Connect
        if not self.connect():
            self.log("Setup failed: Could not connect to database", 'ERROR')
            return False
        
        # Step 3: Create tables
        if not self.create_decorator_ranking_tables():
            self.disconnect()
            self.log("Setup failed: Could not create tables", 'ERROR')
            return False
        
        # Step 4: Add order columns
        if not self.add_order_columns():
            self.disconnect()
            self.log("Setup failed: Could not modify manual_tasks table", 'ERROR')
            return False
        
        # Step 5: Initialize vendors
        if not self.initialize_vendors():
            self.disconnect()
            self.log("Setup failed: Could not initialize vendors", 'ERROR')
            return False
        
        # Step 6: Commit
        if not self.commit_changes():
            self.disconnect()
            return False
        
        # Step 7: Disconnect
        self.disconnect()
        
        # Final report
        self.log("=" * 60, 'INFO')
        self.log("SETUP COMPLETE - System is ready!", 'SUCCESS')
        self.log("=" * 60, 'INFO')
        self.log("\nNext steps:", 'INFO')
        self.log("1. Create upload directory: mkdir uploads/decorator-videos", 'INFO')
        self.log("2. Set permissions: chmod 755 uploads/decorator-videos", 'INFO')
        self.log("3. Visit: /check_installation.php to verify", 'INFO')
        self.log("4. Access admin: /admin/decorator_rankings.php", 'INFO')
        
        return True


def main():
    """Main entry point"""
    updater = DatabaseUpdater()
    success = updater.run_full_setup()
    
    # Save logs
    log_file = 'decorator_setup.log'
    with open(log_file, 'w') as f:
        for log in updater.logs:
            f.write(log + '\n')
    
    print(f"\nLogs saved to: {log_file}")
    
    return 0 if success else 1


if __name__ == '__main__':
    sys.exit(main())
