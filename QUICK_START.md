# Quick Start Guide

## 🚀 Getting Started in 5 Minutes

### Step 1: Import Database
1. Open phpMyAdmin
2. Create a new database (e.g., `vendor_portal`)
3. Select the database and click **Import**
4. Choose `database.sql` file and click **Go**
5. Run this additional SQL:
```sql
ALTER TABLE `orders` ADD COLUMN `assigned_vendor_id` INT NULL;
```

### Step 2: Configure Database Connection
Edit `db.php`:
```php
$host = 'localhost';
$user = 'your_db_username';    // ← Change this
$pass = 'your_db_password';    // ← Change this
$db_name = 'vendor_portal';    // ← Change this
```

### Step 3: Upload to Server
Upload the entire project folder to your web server:
- Via cPanel File Manager, or
- Via FTP client (FileZilla)

Place files in: `public_html/` or `public_html/vendor-portal/`

### Step 4: Test the System
1. Visit: `http://yourdomain.com/vendor/register.php`
2. Register a test vendor
3. Login and explore the dashboard!

---

## 📋 cPanel Deployment Steps

### Using cPanel File Manager:

1. **Login to cPanel**
   - Go to your hosting control panel

2. **Open File Manager**
   - Navigate to `public_html`

3. **Upload Files**
   - Click **Upload**
   - Select all project files
   - OR upload as ZIP and extract

4. **Set Permissions**
   - Select folders: Right-click → Change Permissions → 755
   - Select files: Right-click → Change Permissions → 644

5. **Import Database**
   - Go to **phpMyAdmin** in cPanel
   - Create new database
   - Import `database.sql`

6. **Configure `db.php`**
   - Edit file and update credentials

7. **Test**
   - Visit your domain + `/vendor/register.php`

---

## 🔗 Integration with Main Website

To trigger auto-dispatch when orders are created:

### Method 1: Direct Include
In your order creation script, add:
```php
require_once 'backend/dispatch_order.php';
$order_id = $conn->insert_id; // After inserting order
dispatch_order($order_id);
```

### Method 2: HTTP Request
```php
$order_id = $conn->insert_id;
$url = "https://yourdomain.com/backend/dispatch_order.php?order_id=$order_id";
file_get_contents($url);
```

### Method 3: AJAX Call
```javascript
fetch('backend/dispatch_order.php?order_id=' + orderId)
  .then(response => response.json())
  .then(data => console.log(data));
```

---

## 🧪 Testing the Auto-Dispatch

### Manual Test:

1. **Create a test vendor:**
   - Register at `/vendor/register.php`
   - Set city: "New York"
   - Select category: "Decoration" (ID: 1)

2. **Insert a test order:**
```sql
INSERT INTO orders (customer_name, city, created_at) 
VALUES ('Test Customer', 'New York', NOW());

INSERT INTO order_items (order_id, service_id, design_name, price) 
VALUES (LAST_INSERT_ID(), 1, 'Birthday Decoration', 5000);
```

3. **Trigger dispatch:**
   Visit: `http://yourdomain.com/backend/dispatch_order.php?order_id=1`

4. **Check vendor dashboard:**
   - Login as vendor
   - Go to "Pending Alerts"
   - You should see the new order!

---

## 📱 Mobile Responsive?

Yes! The CSS is responsive and works on:
- ✅ Desktop
- ✅ Tablets
- ✅ Mobile phones

---

## 🔐 Default Test Account

After registration, you can use your credentials.

Example for testing:
- **Email:** test@vendor.com
- **Password:** test123

*(You need to register first)*

---

## ⚠️ Common Issues

### 1. "Connection failed"
- Check `db.php` credentials
- Ensure database exists
- Verify MySQL service is running

### 2. "Headers already sent"
- Remove any spaces/text before `<?php` tags
- Check file encoding (use UTF-8 without BOM)

### 3. "Alerts not showing"
- Check JavaScript console for errors
- Verify `backend/get_alerts.php` is accessible
- Clear browser cache

### 4. "Style not loading"
- Check file paths in HTML
- Ensure `assets/style.css` is uploaded
- Verify file permissions

---

## 📞 Need Help?

1. Check the `README.md` for detailed documentation
2. Review code comments in each PHP file
3. Check browser console for JavaScript errors
4. Enable PHP error reporting in `db.php`:
   ```php
   error_reporting(E_ALL);
   ini_set('display_errors', 1);
   ```

---

**Happy Coding! 🚀**
