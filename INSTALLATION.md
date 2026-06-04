# Complete Installation Guide

## 📋 Prerequisites

- PHP 7.4 or higher
- MySQL 5.7 or higher
- Apache/Nginx web server
- phpMyAdmin (optional, for easier database management)

---

## 🚀 Step-by-Step Installation

### **Step 1: Download & Extract**

1. Download all files
2. Extract to a folder (e.g., `vendor-portal`)

### **Step 2: Database Setup**

#### Option A: Using phpMyAdmin

1. Open phpMyAdmin
2. Click "New" to create a database
3. Name it (e.g., `vendor_portal`)
4. Click on the database name
5. Go to "Import" tab
6. Import files in this order:
   - First: `database.sql`
   - Second: `wallet_admin_schema.sql`
   - Third (optional): `sample_data.sql` (for test data)

#### Option B: Using MySQL Command Line

```bash
# Login to MySQL
mysql -u root -p

# Create database
CREATE DATABASE vendor_portal;
USE vendor_portal;

# Import schemas
SOURCE /path/to/database.sql;
SOURCE /path/to/wallet_admin_schema.sql;
SOURCE /path/to/sample_data.sql;  # Optional test data

# Exit MySQL
exit;
```

#### Step 2.1: Add Column to Existing Orders Table

⚠️ **IMPORTANT**: If you already have an `orders` table, run this:

```sql
ALTER TABLE `orders` ADD COLUMN `assigned_vendor_id` INT NULL;
```

### **Step 3: Configure Database Connection**

1. Open `db.php` in a text editor
2. Update the credentials:

```php
$host = 'localhost';          // Usually 'localhost'
$user = 'your_username';      // Your MySQL username
$pass = 'your_password';      // Your MySQL password
$db_name = 'vendor_portal';   // Database name from Step 2
```

3. Save the file

### **Step 4: Upload to Server**

#### cPanel Method:

1. Login to cPanel
2. Open "File Manager"
3. Navigate to `public_html/`
4. Click "Upload"
5. Upload all project files (or upload as ZIP and extract)
6. Ensure folder structure is maintained:
   ```
   public_html/
   ├── index.php
   ├── db.php
   ├── backend/
   ├── vendor/
   ├── admin/
   └── assets/
   ```

#### FTP Method:

1. Use FileZilla or any FTP client
2. Connect to your server
3. Navigate to `public_html/`
4. Upload all files maintaining folder structure

### **Step 5: Set Permissions**

Set correct permissions for security:

```bash
# Files
find . -type f -exec chmod 644 {} \;

# Directories
find . -type d -exec chmod 755 {} \;
```

Or in cPanel File Manager:
- Right-click folders → Change Permissions → 755
- Right-click files → Change Permissions → 644

### **Step 6: Test Installation**

#### Test 1: Database Connection

Visit: `http://yourdomain.com/vendor/register.php`

- If it loads without errors, database connection is working ✅
- If you see an error, check `db.php` credentials

#### Test 2: Admin Panel

1. Visit: `http://yourdomain.com/admin/login.php`
2. Login with:
   - Username: `admin`
   - Password: `admin123`
3. You should see the admin dashboard ✅

#### Test 3: Vendor Registration

1. Visit: `http://yourdomain.com/vendor/register.php`
2. Fill the form and register
3. Categories should load automatically
4. After registration, login at: `http://yourdomain.com/vendor/login.php`

#### Test 4: Sample Data (if imported)

If you imported `sample_data.sql`, test with these credentials:

**Test Vendors:**
- Email: `rajesh@example.com` | Password: `password`
- Email: `priya@example.com` | Password: `password`
- Email: `amit@example.com` | Password: `password`

---

## 🔧 Configuration

### **Change Admin Password**

1. Login to phpMyAdmin
2. Go to `admin_users` table
3. Click "Edit" on admin row
4. In password field, enter:
   ```sql
   MD5('your_new_password')
   ```
   Or use PHP to generate bcrypt hash:
   ```php
   echo password_hash('your_new_password', PASSWORD_DEFAULT);
   ```

### **Add More Categories**

1. Login to admin panel
2. Go to "Categories"
3. Click "Add New Category"
4. Fill the form and save

### **Configure Email Notifications (Future)**

Currently not implemented. To add:
- Install PHPMailer or use PHP's `mail()` function
- Add email sending in `backend/dispatch_order.php`
- Send notification when job is dispatched

---

## 🔗 Integration with Main Website

### **Auto-Dispatch Orders**

When creating new orders in your main system, add this code:

```php
// After creating order
require_once 'backend/dispatch_order.php';
$order_id = $conn->insert_id;  // Get the newly created order ID
dispatch_order($order_id);      // Dispatch to vendors
```

Or via HTTP request:

```php
$order_id = $conn->insert_id;
$url = "https://yourdomain.com/backend/dispatch_order.php?order_id=" . $order_id;
file_get_contents($url);
```

### **Webhook Integration**

Create `webhook.php` in your main system:

```php
<?php
// webhook.php - Call this after order creation
require_once 'path/to/vendor-portal/backend/dispatch_order.php';

$order_id = $_POST['order_id'] ?? 0;

if ($order_id > 0) {
    $result = dispatch_order($order_id);
    echo json_encode($result);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
}
?>
```

---

## 📊 Testing the Complete Flow

### **End-to-End Test:**

1. **Create Test Order** (in your orders table):
   ```sql
   INSERT INTO orders (customer_name, city, created_at) 
   VALUES ('Test Customer', 'Mumbai', NOW());
   
   SET @order_id = LAST_INSERT_ID();
   
   INSERT INTO order_items (order_id, service_id, design_name, price) 
   VALUES (@order_id, 1, 'Birthday Decoration', 5000);
   ```

2. **Trigger Dispatch**:
   Visit: `http://yourdomain.com/backend/dispatch_order.php?order_id=1`
   (Replace 1 with your order ID)

3. **Check Vendor Alerts**:
   - Login as a vendor in Mumbai with category "Decoration"
   - Go to "Pending Alerts"
   - You should see the new order

4. **Accept Job**:
   - Click "View Details"
   - Click "Accept This Job"
   - Check wallet - should be credited ₹5000

5. **Admin Check**:
   - Login to admin panel
   - Go to "Orders"
   - Order should show as "Assigned"

---

## ⚠️ Troubleshooting

### Error: "Connection failed"
**Solution:** Check `db.php` credentials, ensure MySQL is running

### Error: "Headers already sent"
**Solution:** Remove any spaces/text before `<?php` tags

### Error: "Call to undefined function password_hash()"
**Solution:** Update PHP to 5.5 or higher

### Categories not loading
**Solution:** Check `backend/get_categories.php` is accessible, check browser console

### 500 Internal Server Error
**Solutions:**
1. Enable error reporting in `db.php`:
   ```php
   error_reporting(E_ALL);
   ini_set('display_errors', 1);
   ```
2. Check Apache error logs
3. Verify file permissions (644 for files, 755 for folders)

### Wallet not crediting
**Solution:** 
1. Check `order_items` table has price data
2. Verify `vendor_wallet` table exists
3. Check `wallet_transactions` table for errors

---

## 🔒 Security Checklist

Before going live:

- [ ] Change admin password
- [ ] Remove `sample_data.sql` from server
- [ ] Disable error reporting in production:
  ```php
  error_reporting(0);
  ini_set('display_errors', 0);
  ```
- [ ] Add SSL certificate (HTTPS)
- [ ] Set strong database password
- [ ] Backup database regularly
- [ ] Keep PHP/MySQL updated

---

## 📞 Support

For issues:
1. Check this installation guide
2. Review error logs
3. Check browser console for JavaScript errors
4. Verify database tables are created correctly

---

## ✅ Installation Complete!

If all tests pass, your vendor portal is ready to use!

**Access URLs:**
- Public: `http://yourdomain.com/`
- Vendor Login: `http://yourdomain.com/vendor/login.php`
- Admin Login: `http://yourdomain.com/admin/login.php`

**Default Credentials:**
- Admin: `admin` / `admin123`

---

**Congratulations! 🎉 You're all set up!**
