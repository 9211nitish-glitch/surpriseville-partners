<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';

$logs = [];
$query = "
    SELECT 
        l.*, 
        v.business_name as vendor_name, 
        a.username as admin_name
    FROM manual_allocation_logs l
    LEFT JOIN vendors v ON l.vendor_id = v.id
    LEFT JOIN admin_users a ON l.admin_id = a.id
    ORDER BY l.created_at DESC
";
$res = $conn->query($query);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $logs[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Action Logs | Surprise Ville</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --success: #10b981;
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

        .audit-card {
            background: var(--glass);
            backdrop-filter: blur(12px);
            border-radius: 32px;
            border: 1px solid var(--glass-border);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 2.5rem;
        }

        .table-responsive { overflow-x: auto; }
        .modern-table { width: 100%; border-collapse: collapse; min-width: 1000px; }
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
            font-size: 0.9rem;
        }

        .modern-table tr:hover { background: rgba(255, 255, 255, 0.4); }

        .price-drift { font-weight: 800; font-size: 0.75rem; padding: 6px 12px; border-radius: 10px; display: inline-block; }
        .drift-positive { background: #dcfce7; color: #166534; }
        .drift-negative { background: #fee2e2; color: #991b1b; }
        .drift-neutral { background: #f1f5f9; color: #475569; }

        .type-pill {
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .pill-shop_order { background: #e0f2fe; color: #0369a1; }
        .pill-gig { background: #fef3c7; color: #92400e; }

        .admin-tag {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: white;
            padding: 8px 16px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 700;
            color: #475569;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }

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
    </style>
</head>
<body>

    <header class="header">
        <div style="display: flex; align-items: center; gap: 15px;">
            <div class="sidebar-toggle" onclick="toggleSidebar()">
                <i class="fa-solid fa-bars"></i>
            </div>
            <div style="background: var(--primary); color: white; width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                <i class="fa-solid fa-scroll"></i>
            </div>
            <h1 style="margin: 0; font-size: 1.4rem; font-weight: 800; letter-spacing: -0.5px;">Action Logs</h1>
        </div>
        <nav style="display: flex; align-items: center; gap: 20px;">
            <span style="font-weight: 700; color: #64748b; font-size: 0.85rem;"><i class="fa-solid fa-shield-check"></i> Tracking your changes</span>
        </nav>
    </header>

    <div class="dashboard-container">
        <?php include 'sidebar_fragment.php'; ?>
        <main class="main-content">

            <div style="margin-bottom: 2.5rem;">
                <h2 style="margin: 0; font-weight: 800; font-size: 1.8rem; letter-spacing: -0.5px;">Recent Admin Actions</h2>
                <p style="color: #64748b; font-weight: 600; margin-top: 5px;">See who assigned what and if the price was changed.</p>
            </div>

            <div class="audit-card">
                <div class="table-responsive">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Done By</th>
                                <th>Action Type</th>
                                <th>Reference</th>
                                <th>Vendor</th>
                                <th>Price Change</th>
                                <th>Difference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr><td colspan="7" style="text-align: center; padding: 100px; color: #94a3b8;"><i class="fa-solid fa-file-shield fa-3x" style="margin-bottom:20px;"></i><br>No logs found</td></tr>
                            <?php else: foreach ($logs as $log): 
                                $diff = $log['adjusted_price'] - $log['original_price'];
                                $drift_class = $diff > 0 ? 'drift-positive' : ($diff < 0 ? 'drift-negative' : 'drift-neutral');
                            ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 800; color: #1e293b;"><?= date('d M, Y', strtotime($log['created_at'])) ?></div>
                                        <div style="font-size: 0.75rem; color: #94a3b8; font-weight: 800; letter-spacing: 0.5px;"><?= date('h:i A', strtotime($log['created_at'])) ?></div>
                                    </td>
                                    <td>
                                        <div class="admin-tag">
                                            <i class="fa-solid fa-user-shield" style="font-size: 0.75rem; color: var(--primary);"></i>
                                            <?= htmlspecialchars($log['admin_name'] ?: 'SYSTEM') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="type-pill pill-<?= $log['allocation_type'] ?>">
                                            <?= str_replace('_', ' ', $log['allocation_type']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="font-weight: 800; color: var(--primary); font-size: 1rem;">#<?= $log['order_id'] ?: $log['task_id'] ?></div>
                                        <div style="font-size: 0.65rem; color: #94a3b8; font-weight: 800; text-transform: uppercase;">ID</div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 800; color: #0f172a;"><?= htmlspecialchars($log['vendor_name']) ?></div>
                                        <div style="font-size: 0.75rem; color: #64748b; font-weight: 700;">Vendor ID: SV-<?= str_pad($log['vendor_id'], 4, '0', STR_PAD_LEFT) ?></div>
                                    </td>
                                    <td>
                                        <div style="display: flex; flex-direction: column; gap: 4px;">
                                            <div style="font-size: 0.7rem; color: #94a3b8; font-weight: 800;">OLD PRICE: ₹<?= number_format($log['original_price'], 2) ?></div>
                                            <div style="font-weight: 800; color: #0f172a;">NEW PRICE: ₹<?= number_format($log['adjusted_price'], 2) ?></div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="price-drift <?= $drift_class ?>">
                                            <?= $diff > 0 ? '+' : '' ?><?= number_format($diff, 2) ?>
                                        </span>
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
