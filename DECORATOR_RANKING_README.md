# Decorator Ranking & Video Review System
## Complete Implementation Guide

### 📋 Overview

This system adds a comprehensive decorator ranking, video review, and unified order management capability to the Surpriseville vendor portal. Features include:

- **Ranking System**: 5-point rating system with medals (Gold/Silver/Bronze)
- **Video Reviews**: Before/after and vendor selfie uploads with admin approval
- **Public Portfolio**: Top 10 decorators page with video galleries
- **Order Unification**: Combined offline + online order management
- **Admin Dashboard**: Full management interface for rankings, videos, and orders

---

### 🗄️ Database Schema

#### New Tables Created:

1. **decorator_rankings** - Aggregated ranking data
2. **decorator_ratings** - Individual rating entries
3. **decorator_videos** - Video submissions (before/after/selfie)
4. **decorator_video_portfolio** - Approved videos for public display
5. **order_broadcast_history** - Tracks order broadcasts and reposts
6. **ranking_audit_log** - Audit trail for all rating changes

#### Modified Tables:

- **orders** - Added columns:
  - `order_source` (online/offline)
  - `broadcast_status` (draft/broadcast/assigned)
  - `posted_by_admin_id`

---

### 🚀 Installation Steps

#### 1. Create Database Tables

Run the decorator ranking schema SQL file:

```bash
mysql -u your_user -p your_database < decorator_ranking_schema.sql
```

Or manually execute the SQL in your database admin panel.

#### 2. Migrate Existing Vendors

Run the migration script:

```bash
mysql -u your_user -p your_database < migrate_vendors_to_ranking.sql
```

This initializes all existing vendors in the ranking system with 0 points.

#### 3. Create Directories

```bash
mkdir -p uploads/decorator-videos
chmod 755 uploads/decorator-videos
```

#### 4. Verify Installation

- Admin can access: `/admin/decorator_rankings.php`
- Admin can manage videos: `/admin/decorator_videos.php`
- Public page: `/public_top_decorators.php`
- Vendor portfolio: `/vendor/portfolio.php?vendor_id=1`

---

### 📊 Scoring System

#### Points Breakdown:

| Metric | Points | Description |
|--------|--------|-------------|
| Client Satisfaction | 0-2 | How satisfied clients are with the decorator |
| Video Review | 0-1 | Submission of before/after & selfie videos |
| Grooming/Style | 0-1 | Professional appearance and dressing style |
| Completion Time | 0-1 | Timely completion of decorations |
| **Total** | **0-5** | Maximum points per rating |

#### Medal Tiers:

- 🥇 **Gold**: Top 10 decorators
- 🥈 **Silver**: Rank 11-20 (or 20% of total)
- 🥉 **Bronze**: Rank 21-50 (or 50% of total)

---

### 👨‍💼 Admin Features

#### 1. Decorator Rankings Management
**Path**: `/admin/decorator_rankings.php`

- View all decorators ranked by total points
- See points breakdown (satisfaction, video, grooming, time)
- View medal assignments
- Click "Assign Points" to manually rate a decorator
- Track rating count and approved videos

#### 2. Video Approval
**Path**: `/admin/decorator_videos.php`

- Review pending video submissions
- Preview before/after/selfie videos
- Approve with optional notes
- Reject with reason
- Auto-add approved videos to vendor portfolio

#### 3. Order Management
**Path**: `/admin/orders.php` (enhanced)

- View unified online + offline orders
- Create new offline orders
- Broadcast orders to multiple vendors
- Repost failed orders
- Track broadcast history

#### 4. API Endpoints

**Assign Points**:
```bash
POST /admin/api/assign_decorator_points.php

{
  "vendor_id": 1,
  "order_id": 123,
  "client_satisfaction": 2,
  "video_review": 1,
  "grooming": 0.5,
  "completion_time": 1,
  "comments": "Excellent work"
}
```

**Upload Video**:
```bash
POST /admin/api/upload_video.php

FormData:
- order_id: 123
- vendor_id: 1
- video_type: 'before|after|selfie'
- video: [file]
```

**Manage Orders**:
```bash
POST /admin/api/manage_orders.php

// Create offline order
{
  "action": "create_offline",
  "event_name": "Birthday Party",
  "event_date": "2025-02-15",
  "location": "Mumbai",
  "budget": 5000,
  "notes": "Theme: Gold & Black"
}

// Broadcast to vendors
{
  "action": "broadcast",
  "order_id": 456,
  "vendor_ids": [1, 2, 3, 4, 5],
  "broadcast_type": "manual"
}

// Repost order
{
  "action": "repost",
  "order_id": 456,
  "vendor_ids": [6, 7, 8]
}
```

---

### 👥 Vendor Features

#### 1. Video Upload
Vendors can upload videos for completed jobs:
- Before decoration video
- After decoration video
- Vendor selfie (appearing in final work)

**Upload Endpoint**: `/admin/api/upload_video.php`

#### 2. Portfolio Page
**Path**: `/vendor/portfolio.php?vendor_id=1`

Each vendor has a public portfolio showing:
- Overall rating and ranking
- Points breakdown
- Approved videos gallery
- Recent rating history

#### 3. Dashboard Updates
Vendors see their:
- Current ranking position
- Total points
- Medal status (if earned)
- Video count

---

### 🎬 Video System

#### Supported Formats:
- MP4
- WebM
- MOV
- AVI
- MKV

#### Size Limit: 500MB

#### Upload Path:
```
/uploads/decorator-videos/vendor-{vendor_id}/
```

#### Naming Convention:
```
order-{type}-{timestamp}-{random}.{ext}
Example: order-after-1708933245-a1b2c3d4.mp4
```

#### Approval Workflow:
1. Vendor uploads video
2. Video enters "pending" status
3. Admin reviews and approves/rejects
4. If approved, added to vendor's portfolio
5. Visible on public portfolio page

---

### 🌐 Public Pages

#### Top Decorators Page
**Path**: `/public_top_decorators.php`

Displays:
- Top 10 ranked decorators
- Medal badges (Gold/Silver/Bronze)
- Points breakdown
- Links to vendor portfolios
- Video counts

#### Vendor Portfolio Page
**Path**: `/vendor/portfolio.php?vendor_id={id}`

Shows individual decorator:
- Full ranking and points
- Medal status
- Before & After video gallery
- Recent ratings
- Customer testimonials

---

### 📈 Reporting & Analytics

#### Dashboard Stats (Admin):
- Total decorators
- Gold/Silver/Bronze medal counts
- Pending videos
- Video approval rate
- Order statistics

#### Vendor Stats:
- Ranking position
- Total points
- Rating count
- Video submissions
- Medal tier

---

### 🔒 Security Features

✅ **Authentication**: Session-based admin/vendor auth
✅ **File Validation**: Mime-type checking on uploads
✅ **Size Limits**: Max 500MB per video
✅ **SQL Injection Prevention**: Prepared statements
✅ **XSS Protection**: HTML escaping
✅ **Audit Trail**: All point assignments logged

---

### 🧪 Testing Checklist

- [ ] Database tables created successfully
- [ ] Existing vendors initialized in rankings
- [ ] Admin can access ranking management
- [ ] Can assign points to decorators
- [ ] Points calculation is correct
- [ ] Medals assigned based on ranking
- [ ] Can upload videos (before/after/selfie)
- [ ] Video format validation works
- [ ] Admin can approve/reject videos
- [ ] Approved videos appear in portfolio
- [ ] Top 10 decorators page displays correctly
- [ ] Vendor portfolio shows all info
- [ ] Can create offline orders
- [ ] Can broadcast orders to vendors
- [ ] Can repost orders
- [ ] Unified order view works
- [ ] All audit logs created

---

### 🐛 Troubleshooting

#### Videos Not Uploading
- Check directory permissions: `chmod 755 uploads/decorator-videos`
- Verify file size < 500MB
- Check supported formats (MP4, WebM, MOV, AVI, MKV)

#### Ranking Not Updating
- Ensure decorators initialized in database
- Check admin ID in session
- Verify points within limits (0-2, 0-1, 0-1, 0-1)

#### Orders Not Broadcasting
- Confirm vendor IDs exist
- Check order_id is valid
- Verify admin authentication

---

### 📝 Database Queries

#### Get Top 10 Decorators:
```sql
SELECT * FROM decorator_rankings
WHERE vendor_id IN (SELECT id FROM vendors WHERE status = 'active')
ORDER BY total_points DESC
LIMIT 10;
```

#### Get Recent Ratings for Vendor:
```sql
SELECT * FROM decorator_ratings
WHERE vendor_id = 1
ORDER BY created_at DESC
LIMIT 10;
```

#### Get Pending Videos:
```sql
SELECT dv.*, v.name, v.business_name
FROM decorator_videos dv
JOIN vendors v ON dv.vendor_id = v.id
WHERE dv.video_status = 'pending'
ORDER BY dv.uploaded_at ASC;
```

#### Get Audit Trail:
```sql
SELECT * FROM ranking_audit_log
WHERE vendor_id = 1
ORDER BY created_at DESC;
```

---

### 📞 Support

For issues or questions:
1. Check the troubleshooting section
2. Review database tables and structure
3. Check audit logs for errors
4. Verify all files are in correct locations

---

### 📋 Version History

**v1.0.0** - Initial Release
- Decorator ranking system
- Video review functionality
- Public top decorators page
- Order unification & broadcast
- Admin management interfaces

---

**System Ready for Production** ✅
