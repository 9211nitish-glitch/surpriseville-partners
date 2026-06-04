# Vendor Portal System - API Reference

## Base URL
```
http://yourdomain.com/
```

---

## Authentication

### Vendor Authentication
All vendor endpoints require session authentication.

**Session Variables:**
- `vendor_logged_in` - Boolean
- `vendor_id` - Integer
- `vendor_name` - String
- `vendor_email` - String
- `vendor_business_name` - String
- `vendor_city` - String

### Admin Authentication
All admin endpoints require session authentication.

**Session Variables:**
- `admin_logged_in` - Boolean
- `admin_id` - Integer
- `admin_username` - String
- `admin_email` - String
- `admin_role` - String (admin/superadmin)

---

## Vendor Endpoints

### 1. Vendor Registration
**Endpoint:** `POST /backend/vendor_register.php`

**Parameters:**
```json
{
  "name": "string (required)",
  "business_name": "string (required)",
  "email": "string (required, unique)",
  "phone": "string (required)",
  "password": "string (required, min 6 chars)",
  "city": "string (required)",
  "categories[]": "array (required, category IDs)"
}
```

**Response:**
```json
{
  "success": true|false,
  "message": "string"
}
```

---

### 2. Vendor Login
**Endpoint:** `POST /backend/vendor_login.php`

**Parameters:**
```json
{
  "email": "string (required)",
  "password": "string (required)"
}
```

**Response:**
```json
{
  "success": true|false,
  "message": "string",
  "redirect": "/vendor/dashboard.php"
}
```

---

### 3. Get Pending Alerts
**Endpoint:** `GET /backend/get_alerts.php`

**Authentication:** Required (vendor session)

**Response:**
```json
{
  "success": true|false,
  "alerts": [
    {
      "notification_id": 1,
      "order_id": 123,
      "service_id": 1,
      "design_name": "Birthday Decoration",
      "includes": "Balloons, Cake Table",
      "price": 5000.00,
      "city": "Mumbai",
      "sent_at": "2025-01-15 10:30:00",
      "created_at": "2025-01-15 10:00:00"
    }
  ],
  "count": 1
}
```

---

### 4. Accept Job
**Endpoint:** `POST /backend/vendor_accept_job.php`

**Authentication:** Required (vendor session)

**Parameters:**
```json
{
  "order_id": 123
}
```

**Response:**
```json
{
  "success": true|false,
  "message": "Job accepted successfully! ₹5000.00 credited to your wallet."
}
```

**Side Effects:**
- Updates `orders.assigned_vendor_id`
- Updates `order_vendor_notifications` status to 'accepted'
- Marks other vendors as 'missed'
- Credits vendor wallet
- Creates transaction record

---

### 5. Get Categories
**Endpoint:** `GET /backend/get_categories.php`

**Authentication:** Not required

**Response:**
```json
{
  "success": true,
  "categories": [
    {
      "id": 1,
      "name": "Decoration",
      "icon": "🎨",
      "slug": "decoration"
    }
  ]
}
```

---

## Admin Endpoints

### 1. Admin Login
**Endpoint:** `POST /backend/admin_login.php`

**Parameters:**
```json
{
  "username": "string (required)",
  "password": "string (required)"
}
```

**Response:**
```json
{
  "success": true|false,
  "message": "string",
  "redirect": "/admin/dashboard.php"
}
```

---

## Order Dispatch

### Dispatch Order to Vendors
**Endpoint:** `GET|POST /backend/dispatch_order.php`

**Parameters:**
```
order_id: integer (required)
```

**Function Usage:**
```php
require_once 'backend/dispatch_order.php';
$result = dispatch_order($order_id);
```

**Response:**
```json
{
  "success": true|false,
  "message": "5 vendors notified"
}
```

**Logic:**
1. Fetches order details from `orders` and `order_items`
2. Finds matching vendors:
   - Same city as order
   - Has matching category (service_id)
   - Status = 'active'
   - Limit 5 vendors
3. Creates notifications in `order_vendor_notifications`

---

## Database Schema

### Tables

#### vendors
```sql
id, name, business_name, email, phone, password, city, status, created_at, updated_at
```

#### vendor_categories
```sql
id, vendor_id, category_id
```

#### vendor_wallet
```sql
id, vendor_id, balance, total_earned, total_withdrawn, created_at, updated_at
```

#### wallet_transactions
```sql
id, vendor_id, order_id, type, amount, description, status, created_at
```

#### withdrawal_requests
```sql
id, vendor_id, amount, account_holder_name, account_number, ifsc_code, bank_name, status, admin_note, requested_at, processed_at
```

#### order_vendor_notifications
```sql
id, order_id, vendor_id, status, sent_at, responded_at
```

#### admin_users
```sql
id, username, email, password, role, status, created_at
```

#### categories
```sql
id, name, slug, description, icon, status, created_at, updated_at
```

#### orders (modified)
```sql
..., assigned_vendor_id (new)
```

---

## Status Values

### Vendor Status
- `active` - Can receive jobs
- `inactive` - Cannot receive jobs
- `pending` - Awaiting approval

### Notification Status
- `pending` - Job offered, awaiting response
- `accepted` - Vendor accepted job
- `missed` - Another vendor accepted first

### Transaction Type
- `credit` - Money added to wallet
- `debit` - Money deducted from wallet
- `withdrawal` - Withdrawal request

### Transaction Status
- `pending` - Being processed
- `completed` - Successfully processed
- `failed` - Failed to process

### Withdrawal Status
- `pending` - Awaiting admin review
- `approved` - Approved and processed
- `rejected` - Rejected by admin
- `completed` - Payment sent

---

## Error Codes

### Common Errors

**401 Unauthorized:**
```json
{
  "success": false,
  "message": "Please login first"
}
```

**400 Bad Request:**
```json
{
  "success": false,
  "message": "Invalid order ID"
}
```

**409 Conflict:**
```json
{
  "success": false,
  "message": "This job has already been accepted by another vendor"
}
```

**500 Internal Server Error:**
```json
{
  "success": false,
  "message": "Database error: ..."
}
```

---

## Rate Limiting
Currently not implemented. Recommended for production.

---

## Webhooks
Currently not implemented. Can be added for:
- Order creation notifications
- Job acceptance notifications
- Withdrawal status updates

---

## Security

### Password Hashing
- Algorithm: bcrypt (PASSWORD_DEFAULT)
- Function: `password_hash()` / `password_verify()`

### SQL Injection Prevention
- All queries use prepared statements with `bind_param()`

### XSS Prevention
- All output uses `htmlspecialchars()`

### Session Security
- HTTP-only cookies (recommended)
- Regenerate session ID on login
- Clear session on logout

---

## Testing

### Sample cURL Requests

**Vendor Registration:**
```bash
curl -X POST http://yourdomain.com/backend/vendor_register.php \
  -d "name=Test Vendor" \
  -d "business_name=Test Business" \
  -d "email=test@example.com" \
  -d "phone=1234567890" \
  -d "password=password123" \
  -d "city=Mumbai" \
  -d "categories[]=1" \
  -d "categories[]=2"
```

**Get Categories:**
```bash
curl http://yourdomain.com/backend/get_categories.php
```

**Dispatch Order:**
```bash
curl -X POST http://yourdomain.com/backend/dispatch_order.php \
  -d "order_id=123"
```

---

## Version
**Current Version:** 1.0.0

**Last Updated:** 2025-01-15

---

## Support
For API issues or questions, review the source code or contact the development team.
