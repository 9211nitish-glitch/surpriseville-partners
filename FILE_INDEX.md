# Vendor Portal - File Index

## 📂 Complete File Listing

### Root Files
- `db.php` - Database connection configuration
- `database.sql` - SQL schema for database setup
- `README.md` - Complete documentation
- `QUICK_START.md` - Quick deployment guide

---

### 📁 Backend Scripts (`/backend/`)

| File | Purpose | Input | Output |
|------|---------|-------|--------|
| `vendor_register.php` | Handle vendor registration | POST: name, email, password, city, categories | JSON: success/error |
| `vendor_login.php` | Handle vendor login | POST: email, password | JSON: success + redirect |
| `dispatch_order.php` | Auto-dispatch orders to vendors | GET/POST: order_id | JSON: success/count |
| `vendor_accept_job.php` | Process job acceptance | POST: order_id | JSON: success/error |
| `get_alerts.php` | Fetch pending alerts (AJAX) | None (uses session) | JSON: alerts array |

---

### 📁 Vendor Pages (`/vendor/`)

| File | Purpose | Login Required | Features |
|------|---------|----------------|----------|
| `register.php` | Vendor registration form | No | Multi-category selection, AJAX submission |
| `login.php` | Vendor login form | No | Session creation, redirect to dashboard |
| `dashboard.php` | Main vendor dashboard | Yes | Stats overview, quick actions |
| `pending-alerts.php` | View pending job alerts | Yes | Auto-refresh every 10s, limited job info |
| `job-details.php` | View job details | Yes | Smart info disclosure (before/after accept) |
| `my-jobs.php` | View accepted jobs | Yes | Full job list with client details |
| `profile.php` | View vendor profile | Yes | Vendor info + service categories |
| `logout.php` | Logout and destroy session | Yes | Session cleanup |

---

### 📁 Assets (`/assets/`)

| File | Purpose | Description |
|------|---------|-------------|
| `style.css` | Main stylesheet | Clean light theme, responsive design |
| `script.js` | JavaScript functions | AJAX auto-refresh, job acceptance logic |

---

## 🔄 Data Flow

### Registration Flow
```
register.php → vendor_register.php → Database (vendors + vendor_categories)
```

### Login Flow
```
login.php → vendor_login.php → Session creation → dashboard.php
```

### Order Dispatch Flow
```
New Order Created → dispatch_order.php → Find matching vendors → 
Create notifications → order_vendor_notifications table
```

### Job Acceptance Flow
```
pending-alerts.php → job-details.php → Accept button → 
vendor_accept_job.php → Update orders.assigned_vendor_id → 
Mark notification as accepted → Mark others as missed
```

### Alert Refresh Flow
```
pending-alerts.php → AJAX (every 10s) → get_alerts.php → 
Fetch pending notifications → Update UI
```

---

## 🗄️ Database Tables Reference

| Table | Purpose | Key Fields |
|-------|---------|------------|
| `vendors` | Store vendor accounts | id, email, password, city, status |
| `vendor_categories` | Link vendors to categories | vendor_id, category_id |
| `order_vendor_notifications` | Track job offers | order_id, vendor_id, status |
| `orders` | Main orders table (existing) | id, assigned_vendor_id (new) |
| `order_items` | Order line items (existing) | order_id, service_id, price |
| `order_addons` | Order addons (existing) | order_id, addon_name, price |

---

## 🎨 UI Components (CSS Classes)

### Layout
- `.header` - Top navigation bar
- `.container` - Main content wrapper
- `.dashboard-layout` - Sidebar + content layout
- `.sidebar` - Left navigation menu
- `.main-content` - Main content area

### Cards & Forms
- `.card` - White card container
- `.form-group` - Form field wrapper
- `.job-card` - Job listing card
- `.job-info` - Job info grid
- `.job-info-item` - Single info field

### Buttons
- `.btn` - Base button
- `.btn-primary` - Primary action (blue)
- `.btn-success` - Success action (green)
- `.btn-danger` - Danger action (red)
- `.btn-secondary` - Secondary action (grey)

### Alerts
- `.alert` - Alert container
- `.alert-success` - Success message (green)
- `.alert-error` - Error message (red)
- `.alert-warning` - Warning message (yellow)

### Badges
- `.badge` - Badge base
- `.badge-pending` - Pending status (yellow)
- `.badge-accepted` - Accepted status (green)
- `.badge-missed` - Missed status (red)

### Tables
- `.table` - Data table
- `.stats-grid` - Stats cards grid
- `.stat-card` - Single stat card

### Utilities
- `.spinner` - Loading spinner

---

## 🔧 JavaScript Functions

| Function | Purpose | Location |
|----------|---------|----------|
| `startAlertRefresh()` | Start 10s interval for alerts | script.js |
| `refreshAlerts()` | Fetch and update alerts | script.js |
| `updateAlertsList(alerts)` | Update DOM with alerts | script.js |
| `updateAlertCount(count)` | Update alert badge count | script.js |
| `acceptJob(orderId)` | Accept a job offer | script.js |
| `formatDateTime(datetime)` | Format date/time string | script.js |
| `showLoading()` | Show loading spinner | script.js |
| `hideLoading()` | Hide loading spinner | script.js |

---

## 📝 Session Variables

When a vendor logs in, these session variables are set:

```php
$_SESSION['vendor_logged_in'] = true;
$_SESSION['vendor_id'] = <vendor_id>;
$_SESSION['vendor_name'] = <name>;
$_SESSION['vendor_email'] = <email>;
$_SESSION['vendor_business_name'] = <business_name>;
$_SESSION['vendor_city'] = <city>;
```

---

## 🔒 Security Measures

| Feature | Implementation |
|---------|----------------|
| Password Hashing | `password_hash()` with default algorithm |
| Password Verification | `password_verify()` |
| SQL Injection Prevention | Prepared statements with `bind_param()` |
| XSS Prevention | `htmlspecialchars()` on all output |
| Session Security | PHP native sessions |
| Input Validation | Server-side validation in backend scripts |
| Concurrency Control | Transaction with `FOR UPDATE` lock |

---

## 📊 Status Values

### Vendor Status (`vendors.status`)
- `active` - Vendor is active and can receive jobs
- `inactive` - Vendor account is inactive
- `pending` - Vendor registration pending approval

### Notification Status (`order_vendor_notifications.status`)
- `pending` - Job offer sent, awaiting response
- `accepted` - Vendor accepted the job
- `missed` - Another vendor accepted first

---

**This index provides a complete reference for navigating and understanding the codebase.**
