# Vendor Portal + Auto Job Allocation System

## 📋 Overview

This is a complete **Vendor Portal & Auto Job Allocation System** built using pure PHP, HTML, CSS, JavaScript, and MySQL. No frameworks or external libraries are used.

## 🎯 Features

✅ **Vendor Registration & Authentication**
- Secure vendor registration with password hashing
- PHP session-based login system
- Multi-category service selection

✅ **Auto-Dispatch Job Logic**
- Automatically matches orders with vendors based on city and service category
- Notifies top 5 matching vendors
- First-come-first-serve job allocation

✅ **Real-time Job Notifications**
- Auto-refreshing alerts every 10 seconds via AJAX
- Pending, accepted, and missed job status tracking

✅ **Smart Information Disclosure**
- Limited details shown before job acceptance (service, price, city)
- Full client details revealed after acceptance (phone, address, notes)

✅ **Vendor Dashboard**
- Overview statistics (pending, accepted, missed jobs)
- Quick navigation to alerts and jobs
- Profile management

---

## 📁 Project Structure

```
project/
├── db.php                          # Database connection
├── database.sql                    # Database schema
├── backend/
│   ├── vendor_register.php         # Handle vendor registration
│   ├── vendor_login.php            # Handle vendor login
│   ├── dispatch_order.php          # Auto-dispatch orders to vendors
│   ├── vendor_accept_job.php       # Handle job acceptance
│   └── get_alerts.php              # AJAX endpoint for pending alerts
├── vendor/
│   ├── register.php                # Vendor registration page
│   ├── login.php                   # Vendor login page
│   ├── dashboard.php               # Main dashboard
│   ├── pending-alerts.php          # View pending job alerts
│   ├── job-details.php             # View job details
│   ├── my-jobs.php                 # View accepted jobs
│   ├── profile.php                 # View vendor profile
│   └── logout.php                  # Logout script
└── assets/
    ├── style.css                   # Main stylesheet
    └── script.js                   # JavaScript for AJAX and interactions
```

---

## 🗄️ Database Tables

### 1. `vendors`
Stores vendor information.

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| name | VARCHAR(255) | Vendor name |
| business_name | VARCHAR(255) | Business name |
| email | VARCHAR(255) | Email (unique) |
| phone | VARCHAR(50) | Phone number |
| password | VARCHAR(255) | Hashed password |
| city | VARCHAR(100) | City |
| status | ENUM | active/inactive/pending |
| created_at | TIMESTAMP | Created timestamp |
| updated_at | TIMESTAMP | Updated timestamp |

### 2. `vendor_categories`
Links vendors to service categories.

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| vendor_id | INT | Foreign key to vendors |
| category_id | INT | Category ID |

### 3. `order_vendor_notifications`
Tracks job notifications sent to vendors.

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| order_id | INT | Order ID |
| vendor_id | INT | Vendor ID |
| status | ENUM | pending/accepted/missed |
| sent_at | DATETIME | Notification sent time |
| responded_at | DATETIME | Response time |

### 4. Modified `orders` table
**Added column:**
- `assigned_vendor_id` (INT NULL) - The vendor ID who accepted the job

---

## 🚀 Installation Guide

### Step 1: Setup Database

1. Open phpMyAdmin or MySQL command line
2. Create a new database (e.g., `vendor_portal`)
3. Import the `database.sql` file
4. Run the SQL to add `assigned_vendor_id` to the existing `orders` table:

```sql
ALTER TABLE `orders` ADD COLUMN `assigned_vendor_id` INT NULL;
```

### Step 2: Configure Database Connection

Edit `db.php` and update the database credentials:

```php
$host = 'localhost';
$user = 'your_username';        // Change this
$pass = 'your_password';        // Change this
$db_name = 'your_database_name'; // Change this
```

### Step 3: Upload Files to cPanel

1. Login to your cPanel
2. Open **File Manager**
3. Navigate to `public_html/` (or your domain root)
4. Upload all project files maintaining the folder structure:
   ```
   public_html/
   ├── db.php
   ├── database.sql
   ├── backend/
   ├── vendor/
   └── assets/
   ```

### Step 4: Set File Permissions

Ensure correct permissions (usually 644 for files, 755 for folders):
```
Files: 644
Folders: 755
```

### Step 5: Test the System

1. Visit: `http://yourdomain.com/vendor/register.php`
2. Register a new vendor
3. Login at: `http://yourdomain.com/vendor/login.php`
4. You should be redirected to the dashboard

---

## 🔧 How to Integrate Order Dispatch

When a new order is created in your main website, you need to trigger the auto-dispatch logic.

### Option 1: Include in your order creation script

```php
require_once 'backend/dispatch_order.php';

// After order is created
$order_id = $conn->insert_id; // The newly created order ID
dispatch_order($order_id);
```

### Option 2: Call via HTTP request

```php
// After order creation
$order_id = $conn->insert_id;
file_get_contents("https://yourdomain.com/backend/dispatch_order.php?order_id=$order_id");
```

---

## 🎨 UI Design

- **Clean light theme** with white and light grey colors
- **Responsive layout** with sidebar navigation
- **Auto-refreshing alerts** using AJAX (10-second intervals)
- **Card-based design** for better readability

---

## 🔒 Security Features

✅ Password hashing using `password_hash()` and `password_verify()`
✅ PHP session-based authentication
✅ SQL injection protection using prepared statements
✅ Input validation and sanitization
✅ XSS protection using `htmlspecialchars()`
✅ Transaction-based job acceptance to prevent race conditions

---

## 📝 Workflow

### 1. Vendor Registration
- Vendor fills registration form
- Data validated and stored in database
- Password is securely hashed

### 2. Order Auto-Dispatch
- New order created in main website
- System finds top 5 matching vendors (by city & category)
- Notifications sent to vendors

### 3. Vendor Receives Alert
- Vendor sees limited job details
- Can choose to accept or skip

### 4. Job Acceptance
- Vendor accepts job
- Full client details revealed
- Other vendors marked as "missed"
- Order assigned to accepting vendor

### 5. Job Management
- Vendor can view all accepted jobs
- Access full client information
- Track job history

---

## 🐛 Troubleshooting

### Issue: "Connection failed"
**Solution:** Check database credentials in `db.php`

### Issue: "Session not working"
**Solution:** Ensure PHP sessions are enabled on your server

### Issue: "Alerts not auto-refreshing"
**Solution:** Check browser console for JavaScript errors

### Issue: "Cannot register vendor"
**Solution:** Ensure email is unique and all required fields are filled

---

## 📊 Testing Checklist

- [ ] Can register a new vendor
- [ ] Can login with valid credentials
- [ ] Dashboard shows correct stats
- [ ] Can view pending alerts
- [ ] Alerts auto-refresh every 10 seconds
- [ ] Can view job details
- [ ] Limited details shown before acceptance
- [ ] Can accept a job
- [ ] Full details shown after acceptance
- [ ] Other vendors marked as "missed"
- [ ] Order's `assigned_vendor_id` updated correctly
- [ ] Can view accepted jobs in "My Jobs"
- [ ] Can view profile
- [ ] Can logout successfully

---

## 🎓 Notes

1. **Category IDs**: Update the category checkboxes in `register.php` to match your actual service categories
2. **Order Fields**: The system expects certain fields in the `orders`, `order_items`, and `order_addons` tables. Adjust field names in the code if your database schema differs.
3. **Time Zone**: Set your PHP timezone in `php.ini` for accurate timestamps
4. **Production**: Remove any dummy/test data before going live

---

## 📞 Support

For issues or questions, review the code comments in each file for detailed explanations.

---

## ✨ Built With

- **PHP** (Pure procedural, no OOP)
- **MySQL** with **mysqli**
- **HTML5**
- **CSS3** (Vanilla, no frameworks)
- **JavaScript** (Vanilla, no jQuery)

---

**Created for maximum compatibility with shared cPanel hosting.**
