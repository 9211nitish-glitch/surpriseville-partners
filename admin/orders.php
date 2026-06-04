<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';
require_once '../db_main.php';

$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$whereClause = "WHERE o.service_id IS NOT NULL";
if ($filter === 'pending') {
    $whereClause .= " AND (o.assigned_vendor_id IS NULL OR o.assigned_vendor_id = 0) AND o.status NOT IN ('completed', 'cancelled')";
} elseif ($filter === 'assigned') {
    $whereClause .= " AND o.assigned_vendor_id > 0 AND o.status != 'completed' AND o.status != 'cancelled'";
} elseif ($filter === 'completed') {
    $whereClause .= " AND o.status = 'completed'";
}

$orders = [];
$query = "
    SELECT o.*, 
           (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count,
           (SELECT payment_proof FROM order_vendor_assignments WHERE order_id = o.id AND payment_proof != '' LIMIT 1) as has_payment_proof
    FROM orders o 
    $whereClause 
    ORDER BY o.created_at DESC
";
$result = $mainConn->query($query);
if ($result) { while ($row = $result->fetch_assoc()) { $orders[] = $row; } }

$allOrdersForStats = [];
$statsRes = $mainConn->query("SELECT status, assigned_vendor_id FROM orders WHERE service_id IS NOT NULL");
if ($statsRes) { while ($r = $statsRes->fetch_assoc()) $allOrdersForStats[] = $r; }

$orderStats = ['pending' => 0, 'assigned' => 0, 'completed' => 0, 'cancelled' => 0, 'total' => count($allOrdersForStats)];
foreach($allOrdersForStats as $o) {
    $st = strtolower($o['status']);
    if($st === 'completed') $orderStats['completed']++;
    elseif($st === 'cancelled') $orderStats['cancelled']++;
    elseif($o['assigned_vendor_id'] > 0) $orderStats['assigned']++;
    else $orderStats['pending']++;
}

$vendor_details = [];
if (!empty($orders)) {
    $vendor_ids = array_unique(array_filter(array_column($orders, 'assigned_vendor_id')));
    if (!empty($vendor_ids)) {
        $ids_str = implode(',', array_map('intval', $vendor_ids));
        $v_res = $conn->query("SELECT id, name, business_name, phone FROM vendors WHERE id IN ($ids_str)");
        if ($v_res) { while ($v = $v_res->fetch_assoc()) { $vendor_details[$v['id']] = $v; } }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Orders | Surprise Ville</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --glass: rgba(255, 255, 255, 0.7);
            --glass-border: rgba(255, 255, 255, 0.4);
            --shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.1);
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

        .dashboard-container {
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
            gap: 1.25rem;
            box-shadow: var(--shadow);
            text-decoration: none;
            color: inherit;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .stat-card:hover { transform: translateY(-5px); border-color: var(--primary); }
        .stat-card.active { background: white; border-color: var(--primary); box-shadow: 0 20px 25px -5px rgba(79, 70, 229, 0.1); }

        .icon-box {
            width: 50px;
            height: 50px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            background: white;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }

        .premium-card {
            background: var(--glass);
            backdrop-filter: blur(12px);
            border-radius: 32px;
            border: 1px solid var(--glass-border);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 2.5rem;
        }

        .table-responsive { overflow-x: auto; }
        .modern-table { width: 100%; border-collapse: collapse; }
        .modern-table th {
            background: rgba(248, 250, 252, 0.5);
            padding: 1.25rem 1.5rem;
            text-align: left;
            font-size: 0.7rem;
            text-transform: uppercase;
            font-weight: 800;
            color: #64748b;
            letter-spacing: 1px;
            border-bottom: 1px solid var(--glass-border);
        }

        .modern-table td {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(226, 232, 240, 0.5);
            vertical-align: middle;
        }

        .modern-table tr:hover { background: rgba(255, 255, 255, 0.4); }

        .customer-pill { display: flex; align-items: center; gap: 12px; }
        .customer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: white;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }

        .badge {
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-assigned { background: #dbeafe; color: #1e40af; }
        .badge-completed { background: #dcfce7; color: #166534; }
        .badge-cancelled { background: #fee2e2; color: #991b1b; }

        .btn-action {
            padding: 10px 18px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 800;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
            border: none;
        }
        .btn-success { background: var(--success); color: white; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25); }
        .btn-outline { background: white; border: 1px solid #e2e8f0; color: #64748b; }
        .btn-outline:hover { border-color: var(--primary); color: var(--primary); }

        .sidebar-toggle {
            display: flex;
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 10px;
            align-items: center;
            justify-content: center;
            border: 1px solid #e2e8f0;
            color: var(--primary);
            cursor: pointer;
            font-size: 1.2rem;
            transition: all 0.2s;
        }

        @media (max-width: 1024px) {
            .dashboard-container { flex-direction: column; padding: 1.5rem; gap: 1rem; }
            .header { padding: 1rem 1.5rem; }
        }

        @media (max-width: 480px) {
            .header {
                flex-direction: column;
                align-items: center !important;
                gap: 1rem !important;
            }
            .header nav {
                flex-direction: column !important;
                gap: 10px !important;
            }
        }
    </style>
</head>
<body>

    <header class="header">
        <div style="display: flex; align-items: center; gap: 15px;">
            <div class="sidebar-toggle" onclick="toggleSidebar()">
                <i class="fa-solid fa-bars"></i>
            </div>
            <div style="background: var(--primary); color: white; width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                <i class="fa-solid fa-truck-fast"></i>
            </div>
            <h1 style="margin: 0; font-size: 1.4rem; font-weight: 800; letter-spacing: -0.5px;">Online Orders</h1>
        </div>
        <nav style="display: flex; align-items: center; gap: 20px;">
            <span style="font-weight: 700; color: #64748b; font-size: 0.85rem;"><i class="fa-solid fa-server"></i> Connected</span>
        </nav>
    </header>

    <div class="dashboard-container">
        <?php include 'sidebar_fragment.php'; ?>
        <main class="main-content">

            <div class="stat-grid">
                <a href="?filter=pending" class="stat-card <?= $filter === 'pending' ? 'active' : '' ?>">
                    <div class="icon-box" style="color: #f97316;"><i class="fa-solid fa-bell"></i></div>
                    <div>
                        <div style="font-weight: 800; font-size: 1.5rem;"><?= $orderStats['pending'] ?></div>
                        <div style="font-size: 0.65rem; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;">New Orders</div>
                    </div>
                </a>
                <a href="?filter=assigned" class="stat-card <?= $filter === 'assigned' ? 'active' : '' ?>">
                    <div class="icon-box" style="color: #3b82f6;"><i class="fa-solid fa-truck-ramp-box"></i></div>
                    <div>
                        <div style="font-weight: 800; font-size: 1.5rem;"><?= $orderStats['assigned'] ?></div>
                        <div style="font-size: 0.65rem; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;">Assigned</div>
                    </div>
                </a>
                <a href="?filter=completed" class="stat-card <?= $filter === 'completed' ? 'active' : '' ?>">
                    <div class="icon-box" style="color: #166534;"><i class="fa-solid fa-check-double"></i></div>
                    <div>
                        <div style="font-weight: 800; font-size: 1.5rem;"><?= $orderStats['completed'] ?></div>
                        <div style="font-size: 0.65rem; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;">Completed</div>
                    </div>
                </a>
                <a href="?filter=all" class="stat-card <?= $filter === 'all' ? 'active' : '' ?>">
                    <div class="icon-box" style="color: #64748b;"><i class="fa-solid fa-database"></i></div>
                    <div>
                        <div style="font-weight: 800; font-size: 1.5rem;"><?= $orderStats['total'] ?></div>
                        <div style="font-size: 0.65rem; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;">Total Orders</div>
                    </div>
                </a>
            </div>

            <div class="premium-card">
                <div class="table-responsive">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Customer Details</th>
                                <th>Location</th>
                                <th>Price</th>
                                <th>Vendor</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($orders)): ?>
                                <tr><td colspan="7" style="text-align:center; padding:80px; color:#94a3b8;"><i class="fa-solid fa-box-open fa-3x" style="margin-bottom:20px;"></i><br>No orders found</td></tr>
                            <?php else: foreach($orders as $o): 
                                $v = $o['assigned_vendor_id'] ? ($vendor_details[$o['assigned_vendor_id']] ?? null) : null;
                                
                                $dbStatus = strtolower($o['status'] ?: 'pending');
                                // Override display status if no vendor is assigned but status is 'assigned' or 'in_progress'
                                if (($dbStatus === 'assigned' || $dbStatus === 'in_progress') && !$v) {
                                    $st = 'pending';
                                } else {
                                    $st = $dbStatus;
                                }
                            ?>
                            <tr onclick="window.location='order_tracking.php?order_id=<?= $o['id'] ?>'" style="cursor: pointer;">
                                <td style="font-weight: 800; color: #64748b; font-size: 0.95rem;">
                                    #<?= $o['id'] ?><br>
                                    <small style="font-weight:700; color: #94a3b8; font-size: 0.7rem;"><?= date('H:i | d M', strtotime($o['created_at'])) ?></small>
                                </td>
                                <td>
                                    <div class="customer-pill">
                                        <div class="customer-avatar"><?= strtoupper(substr($o['customer_name'] ?? $o['name'] ?? 'U', 0, 1)) ?></div>
                                        <div>
                                            <div style="font-weight: 800; color: #0f172a; font-size: 0.95rem;"><?= htmlspecialchars($o['customer_name'] ?? $o['name'] ?? 'N/A') ?></div>
                                            <div style="font-size: 0.75rem; color: #64748b; font-weight: 600;"><?= $o['customer_phone'] ?? $o['phone'] ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-weight: 800; color: var(--danger); font-size: 0.85rem;"><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($o['city']) ?></div>
                                    <div style="font-size: 0.7rem; color: #64748b; font-weight: 700;"><?= $o['pincode'] ?></div>
                                </td>
                                <td>
                                    <div style="font-weight: 800; color: var(--success); font-size: 1rem;">₹<?= number_format($o['total_amount'], 2) ?></div>
                                    <div style="display: flex; align-items: center; gap: 5px;">
                                        <div style="font-size: 0.65rem; color: #64748b; font-weight: 800;"><?= $o['item_count'] ?> ITEMS</div>
                                        <?php if(!empty($o['has_payment_proof'])): ?>
                                            <i class="fa-solid fa-file-invoice-dollar" style="color: var(--primary); font-size: 0.8rem;" title="Payment Proof Uploaded"></i>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if($v): ?>
                                        <div style="font-weight: 700; color: #1e293b; font-size: 0.9rem;"><?= htmlspecialchars($v['business_name'] ?: $v['name']) ?></div>
                                        <div style="font-size: 0.7rem; color: #64748b; font-weight: 600;"><i class="fa-solid fa-phone" style="font-size: 0.6rem;"></i> <?= $v['phone'] ?></div>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-style: italic; font-size: 0.8rem;">Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge badge-<?= $st ?>"><?= $st ?></span></td>
                                <td onclick="event.stopPropagation()">
                                    <div style="display: flex; flex-direction: column; gap: 8px;">
                                        <?php if(!$o['assigned_vendor_id'] && !in_array($st, ['completed','cancelled'])): ?>
                                            <a href="allocate_order.php?order_id=<?= $o['id'] ?>&type=shop_order" class="btn-action btn-success"><i class="fa-solid fa-user-plus"></i> Allocate</a>
                                        <?php endif; ?>
                                        <a href="order_tracking.php?order_id=<?= $o['id'] ?>" class="btn-action btn-outline"><i class="fa-solid fa-eye"></i> Details</a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

</body>
</html>
