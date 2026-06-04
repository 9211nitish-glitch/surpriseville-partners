# Decorator Ranking System - Plesk Server Setup Guide

## Overview
This guide helps you automatically set up the decorator ranking system on your Plesk server using either Python or Bash scripts.

---

## Method 1: Python Script (Recommended)

### Prerequisites
```bash
pip install mysql-connector-python
```

### Run Setup
```bash
cd /path/to/partners.surpriseville.co.in
python3 setup_decorator_db.py
```

### What It Does
✓ Reads credentials from db.php
✓ Creates all 6 database tables
✓ Adds columns to orders table
✓ Initializes all vendors
✓ Saves detailed logs to decorator_setup.log

### Output
```
[2025-05-21 19:37:50] [INFO] Config loaded: Host=localhost, DB=surpriseville, User=root
[2025-05-21 19:37:51] [SUCCESS] ✓ Created table: decorator_rankings
[2025-05-21 19:37:51] [SUCCESS] ✓ Created table: decorator_ratings
[2025-05-21 19:37:51] [SUCCESS] ✓ Created table: decorator_videos
[2025-05-21 19:37:51] [SUCCESS] ✓ Created table: decorator_video_portfolio
[2025-05-21 19:37:51] [SUCCESS] ✓ Created table: order_broadcast_history
[2025-05-21 19:37:51] [SUCCESS] ✓ Created table: ranking_audit_log
[2025-05-21 19:37:52] [SUCCESS] ✓ Initialized 42 vendor(s)
[2025-05-21 19:37:52] [SUCCESS] ✓ SETUP COMPLETE - System is ready!
```

---

## Method 2: Bash Script

### Make Script Executable
```bash
chmod +x setup_decorator_db.sh
```

### Run Setup
```bash
./setup_decorator_db.sh
```

### What It Does
✓ Extracts database credentials automatically
✓ Tests database connection
✓ Creates all tables
✓ Modifies orders table
✓ Creates upload directories

### Requirements
- MySQL/MariaDB client installed
- Access to database credentials in db.php

---

## Method 3: Manual via Plesk Panel

### Step 1: Open phpMyAdmin
1. Login to Plesk Control Panel
2. Go to Databases
3. Click on your database name
4. Click "Open in phpMyAdmin"

### Step 2: Import SQL
1. Click "Import" tab
2. Upload `decorator_ranking_schema.sql`
3. Click "Go"

### Step 3: Run Migration
1. Paste contents of `migrate_vendors_to_ranking.sql`
2. Click "Go"

### Step 4: Add Columns
1. Copy and paste SQL from decorator_ranking_schema.sql (lines for ALTER TABLE)
2. Click "Go"

---

## Post-Installation

### 1. Create Upload Directory
```bash
cd /path/to/partners.surpriseville.co.in
mkdir -p uploads/decorator-videos
chmod 755 uploads/decorator-videos
```

### 2. Verify Installation
Visit: `https://yoursite.com/check_installation.php`

Should show all green checkmarks ✓

### 3. Access Admin Panel
- URL: `https://yoursite.com/admin/decorator_rankings.php`
- Login with admin account

### 4. View Public Page
- URL: `https://yoursite.com/public_top_decorators.php`

---

## Troubleshooting

### Python Script Issues

**Error: mysql-connector-python not installed**
```bash
pip3 install --user mysql-connector-python
```

**Error: Connection refused**
- Check DB_HOST in db.php (should be localhost for Plesk)
- Verify database name is correct
- Ensure user/password are correct

**Error: Table creation failed**
- Check database user has CREATE TABLE permission
- Run each query manually via phpMyAdmin

---

### Bash Script Issues

**Error: mysql: command not found**
```bash
# Install MySQL client
apt-get install mysql-client  # Debian/Ubuntu
yum install mysql             # CentOS/RHEL
```

**Permission denied**
```bash
chmod +x setup_decorator_db.sh
```

**Cannot parse db.php**
- Ensure file path is correct
- Check db.php variable names match expected format

---

## Verification Checklist

After running the setup script:

- [ ] All 6 tables created in database
- [ ] Orders table has order_source, broadcast_status, posted_by_admin_id columns
- [ ] All vendors appear in decorator_rankings table
- [ ] Upload directory exists and is writable
- [ ] /check_installation.php shows all green checkmarks

---

## File Locations on Plesk Server

Assuming Plesk document root is `/var/www/vhosts/yoursite.com/httpdocs`:

```
/var/www/vhosts/yoursite.com/httpdocs/
├── setup_decorator_db.py              ← Run this
├── setup_decorator_db.sh               ← Or this
├── db.php                              ← Reads from this
├── check_installation.php              ← Verify here
├── decorator_ranking_schema.sql        ← For manual setup
├── migrate_vendors_to_ranking.sql      ← For manual setup
├── uploads/
│   └── decorator-videos/               ← Created by script
├── backend/
│   ├── decorator_ranking_system.php
│   ├── decorator_video_uploader.php
│   └── order_management_system.php
├── admin/
│   ├── decorator_rankings.php
│   ├── decorator_videos.php
│   └── api/
│       ├── assign_decorator_points.php
│       ├── upload_video.php
│       └── manage_orders.php
├── vendor/
│   └── portfolio.php
└── public_top_decorators.php
```

---

## Quick Start Summary

### Option A (Python - Recommended)
```bash
cd /path/to/site
pip3 install mysql-connector-python
python3 setup_decorator_db.py
mkdir -p uploads/decorator-videos
chmod 755 uploads/decorator-videos
```

### Option B (Bash)
```bash
cd /path/to/site
chmod +x setup_decorator_db.sh
./setup_decorator_db.sh
```

### Option C (Manual via Plesk)
1. Open phpMyAdmin
2. Import decorator_ranking_schema.sql
3. Run migration queries
4. Create uploads directory manually

---

## Database Connection Details

The script reads from `db.php`:

```php
$db_host = 'localhost';        // Database host
$db_name = 'surpriseville';    // Database name
$db_user = 'root';             // Database user
$db_password = 'password';     // Database password
```

Plesk typically stores these automatically in db.php for your application.

---

## Support

If you encounter issues:

1. Check `decorator_setup.log` (created by Python script)
2. Run `/check_installation.php` to see what's missing
3. Review troubleshooting section above
4. Check phpMyAdmin for manual verification

---

## Success Indicators

After successful setup, you should see:

✅ 6 new tables in database (decorator_*)
✅ All vendors initialized with 0 points
✅ Orders table modified with new columns
✅ Upload directory created and writable
✅ /check_installation.php shows all green
✅ Admin can access /admin/decorator_rankings.php
✅ Public can view /public_top_decorators.php

---

**System Ready for Use!** 🎉
