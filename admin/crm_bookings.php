<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

require_once "../db.php";

// Fetch Stats
$statsRes = $conn->query("SELECT status, COUNT(*) as count FROM crm_bookings GROUP BY status");
$stats = ['pending' => 0, 'assigned' => 0, 'completed' => 0];
while ($sRow = $statsRes->fetch_assoc()) {
    $stats[$sRow['status']] = $sRow['count'];
}

// 1. Fetch CRM Bookings
$sql = "SELECT b.*, v.name as vendor_name FROM crm_bookings b LEFT JOIN vendors v ON b.assigned_vendor_id = v.id ORDER BY b.created_at DESC";
$result = $conn->query($sql);
$bookings = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $bookings[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM Bookings | Partners Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4f46e5;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --glass: rgba(255, 255, 255, 0.7);
            --glass-border: rgba(255, 255, 255, 0.4);
            --shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.1);
            --dark-bg: #0f172a;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            font-family: 'Outfit', sans-serif;
            color: #1e293b;
            min-height: 100vh;
        }

        .header {
            background: var(--glass);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--glass-border);
            padding: 1.25rem 2.5rem;
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .container {
            display: flex;
            gap: 2rem;
            padding: 2.5rem;
            max-width: 1400px;
            margin: 0 auto;
        }

        .main-content { flex: 1; min-width: 0; }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }

        .stat-card {
            background: var(--glass);
            backdrop-filter: blur(12px);
            padding: 1.5rem;
            border-radius: 24px;
            border: 1px solid var(--glass-border);
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
        }

        .stat-card:hover { transform: translateY(-5px); }

        .icon-box {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            background: white;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }

        .page-header {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            padding: 2.5rem;
            border-radius: 32px;
            margin-bottom: 2rem;
            box-shadow: 0 20px 25px -5px rgba(79, 70, 229, 0.2);
            position: relative;
            overflow: hidden;
        }

        .page-header::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 400px;
            height: 400px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }

        .card {
            background: var(--glass);
            backdrop-filter: blur(12px);
            border-radius: 24px;
            border: 1px solid var(--glass-border);
            padding: 0;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        th {
            text-align: left;
            padding: 1rem;
            color: #64748b;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 2px solid #f1f5f9;
        }

        td {
            padding: 1.25rem 1rem;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .booking-row:hover {
            background: rgba(255, 255, 255, 0.5);
        }

        .client-info { display: flex; flex-direction: column; gap: 4px; }
        .client-name { font-weight: 700; color: #0f172a; }
        .client-phone { font-size: 0.85rem; color: #64748b; }

        .event-info { display: flex; flex-direction: column; gap: 4px; }
        .event-date { font-weight: 600; color: #4f46e5; }
        .event-location { font-size: 0.8rem; color: #64748b; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        .badge {
            padding: 6px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
        }

        .status-pending { background: #fff7ed; color: #c2410c; }
        .status-assigned { background: #ecfdf5; color: #065f46; }
        .status-completed { background: #eff6ff; color: #1d4ed8; }
        .status-cancelled { background: #fef2f2; color: #991b1b; }

        .btn-allocate {
            background: var(--primary);
            color: white;
            padding: 10px 20px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.85rem;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-allocate:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
        }

        @media (max-width: 1024px) {
            .container { flex-direction: column; padding: 1rem; }
        }
    </style>
</head>
<body>

    <header class="header">
        <div style="display: flex; align-items: center; gap: 15px;">
            <div style="background: var(--primary); color: white; width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                <i class="fa-solid fa-sync"></i>
            </div>
            <h1 style="margin: 0; font-size: 1.4rem; font-weight: 800; letter-spacing: -0.5px;">CRM Sync Control</h1>
        </div>
        <nav style="display: flex; align-items: center; gap: 25px;">
            <a href="logout.php" style="color: var(--danger); font-weight: 800; text-decoration: none; font-size: 0.9rem;">
                <i class="fa-solid fa-power-off"></i> Logout
            </a>
        </nav>
    </header>

    <div class="container">
        <?php include 'sidebar_fragment.php'; ?>
        
        <main class="main-content">
            <div class="page-header">
                <div style="font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 3px; opacity: 0.7; margin-bottom: 10px;">Direct Integration</div>
                <h2 style="margin: 0; font-weight: 800; font-size: 2rem;">CRM Bookings</h2>
                <p style="margin: 10px 0 0; opacity: 0.8; font-weight: 500;">Confirmed bookings from crm.btnevents.in awaiting allocation</p>
            </div>

            <div class="stat-grid">
                <div class="stat-card">
                    <div class="icon-box" style="background: #fff7ed; color: #f97316;"><i class="fa-solid fa-clock"></i></div>
                    <div>
                        <div style="font-weight: 800; font-size: 1.3rem;"><?= $stats['pending'] ?></div>
                        <div style="font-size: 0.65rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Awaiting Allocation</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="icon-box" style="background: #eff6ff; color: #3b82f6;"><i class="fa-solid fa-user-check"></i></div>
                    <div>
                        <div style="font-weight: 800; font-size: 1.3rem;"><?= $stats['assigned'] ?></div>
                        <div style="font-size: 0.65rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Allocated to Vendors</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="icon-box" style="background: #f0fdf4; color: #22c55e;"><i class="fa-solid fa-check-double"></i></div>
                    <div>
                        <div style="font-weight: 800; font-size: 1.3rem;"><?= $stats['completed'] ?></div>
                        <div style="font-size: 0.65rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Execution Done</div>
                    </div>
                </div>
            </div>

            <div class="card">
                <table>
                    <thead>
                        <tr>
                            <th style="padding-left: 2rem;">ID</th>
                            <th>Client Details</th>
                            <th>Event Info</th>
                            <th>Decoration</th>
                            <th>Vendor Details</th>
                            <th>Status</th>
                            <th style="padding-right: 2rem; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($bookings)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 5rem; color: #94a3b8;">
                                    <i class="fa-solid fa-inbox fa-3x" style="margin-bottom: 1rem; display: block; opacity: 0.3;"></i>
                                    No CRM bookings found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($bookings as $b): ?>
                                <tr class="booking-row">
                                    <td style="font-weight: 800; color: #94a3b8; padding-left: 2rem;">#<?= $b['id'] ?></td>
                                    <td>
                                        <div class="client-info">
                                            <span class="client-name"><?= htmlspecialchars($b['client_name']) ?></span>
                                            <span class="client-phone"><?= htmlspecialchars($b['client_phone']) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="event-info">
                                            <span class="event-date"><?= date('d M Y, h:i A', strtotime($b['event_date'])) ?></span>
                                            <span class="event-location" title="<?= htmlspecialchars($b['location']) ?>"><?= htmlspecialchars($b['location']) ?></span>
                                        </div>
                                    </td>
                                    <td style="font-weight: 600; color: #475569;"><?= htmlspecialchars($b['decoration_type']) ?></td>
                                    <td>
                                        <?php if ($b['vendor_name']): ?>
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <div style="width: 30px; height: 30px; background: #f1f5f9; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--primary); font-weight: 800; font-size: 0.7rem;">
                                                    <?= strtoupper(substr($b['vendor_name'], 0, 1)) ?>
                                                </div>
                                                <div style="font-weight: 700; color: #1e293b; font-size: 0.85rem;"><?= htmlspecialchars($b['vendor_name']) ?></div>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-style: italic; font-size: 0.8rem;">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge status-<?= $b['status'] ?>"><?= $b['status'] ?></span>
                                    </td>
                                    <td style="padding-right: 2rem; text-align: right;">
                                        <?php if ($b['status'] == 'pending'): ?>
                                            <a href="edit_crm_booking.php?id=<?= $b['id'] ?>" class="btn-allocate">
                                                <i class="fa-solid fa-screwdriver-wrench"></i> Manage
                                            </a>
                                        <?php elseif ($b['status'] == 'tasked'): ?>
                                            <span style="font-size: 0.85rem; color: var(--success); font-weight: 700;">Broadcasted</span>
                                        <?php else: ?>
                                            <a href="tracking.php?type=crm_booking&track_id=<?= $b['id'] ?>" class="btn-allocate" style="background: #f1f5f9; color: #64748b;">
                                                <i class="fa-solid fa-eye"></i> Details
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

</body>
</html>
