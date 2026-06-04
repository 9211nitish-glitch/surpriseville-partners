<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';

if (isset($_POST['change_status'])) {
    $vendor_id = intval($_POST['vendor_id']);
    $new_status = $_POST['status'];
    $stmt = $conn->prepare("UPDATE vendors SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $vendor_id);
    $stmt->execute();
    $stmt->close();
    header('Location: vendors.php');
    exit;
}

$vendors = [];
$query = "SELECT 
            v.*,
            (
                (SELECT COUNT(*) FROM order_vendor_notifications WHERE vendor_id = v.id AND status = 'accepted') 
                + 
                (SELECT COUNT(*) FROM manual_tasks WHERE assigned_vendor_id = v.id AND status IN ('completed', 'verified'))
            ) as total_jobs,
            (
                (SELECT COUNT(*) FROM order_vendor_notifications WHERE vendor_id = v.id AND status = 'pending') 
                + 
                (SELECT COUNT(*) FROM task_alerts ta 
                 JOIN manual_tasks mt ON mt.id = ta.task_id 
                 WHERE ta.vendor_id = v.id AND ta.status = 'pending' AND mt.status = 'open')
            ) as pending_alerts,
            COALESCE(vw.balance, 0) as wallet_balance,
            COALESCE(vw.total_earned, 0) as total_earned
          FROM vendors v
          LEFT JOIN vendor_wallet vw ON v.id = vw.vendor_id
          ORDER BY v.created_at DESC";

$result = $conn->query($query);
while ($row = $result->fetch_assoc()) {
    $vendors[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendors | Surprise Ville</title>
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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

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

        .main-content {
            flex: 1;
            min-width: 0;
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }

        .stat-card {
            background: var(--glass);
            backdrop-filter: blur(12px);
            padding: 1.75rem;
            border-radius: 24px;
            border: 1px solid var(--glass-border);
            display: flex;
            align-items: center;
            gap: 1.25rem;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .icon-box {
            width: 60px;
            height: 60px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
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

        .search-bar {
            background: white;
            padding: 1rem 1.5rem;
            border-radius: 18px;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }

        .search-bar input {
            border: none;
            outline: none;
            width: 100%;
            font-family: inherit;
            font-size: 0.95rem;
            font-weight: 500;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .modern-table {
            width: 100%;
            border-collapse: collapse;
        }

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

        .modern-table tr:hover {
            background: rgba(255, 255, 255, 0.4);
        }

        .avatar {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: white;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .badge {
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-active {
            background: #dcfce7;
            color: #166534;
        }

        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .action-btn {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.2s;
            border: 1px solid #e2e8f0;
            background: white;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            border-color: var(--primary);
            color: var(--primary);
        }

        /* Sidebar & Toggle */
        .sidebar {
            width: 280px;
            background: var(--glass);
            backdrop-filter: blur(12px);
            border-radius: 24px;
            padding: 1.5rem;
            height: fit-content;
            border: 1px solid var(--glass-border);
            box-shadow: var(--shadow);
            position: sticky;
            top: 100px;
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1), padding 0.3s ease, left 0.3s ease;
            flex-shrink: 0;
        }

        .sidebar.collapsed {
            width: 80px !important;
            padding: 1.5rem 0.75rem;
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
            .dashboard-container {
                flex-direction: column;
                padding: 1.5rem;
                gap: 1rem;
            }

            .header {
                padding: 1rem 1.5rem;
            }

            .sidebar {
                position: fixed !important;
                left: -300px !important;
                top: 0 !important;
                bottom: 0 !important;
                z-index: 1000 !important;
                border-radius: 0 24px 24px 0 !important;
                height: 100vh !important;
                width: 280px !important;
                background: white !important;
            }

            .sidebar.active {
                left: 0 !important;
                box-shadow: 0 0 0 1000px rgba(15, 23, 42, 0.5) !important;
            }
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
                <i class="fa-solid fa-users-gear"></i>
            </div>
            <h1 style="margin: 0; font-size: 1.4rem; font-weight: 800; letter-spacing: -0.5px;">Vendors</h1>
        </div>
        <nav style="display: flex; align-items: center; gap: 20px;">
            <span style="font-weight: 700; color: var(--primary); font-size: 0.9rem;"><i class="fa-solid fa-shield-halved"></i> Admin Panel</span>
        </nav>
    </header>

    <div class="dashboard-container">
        <?php include 'sidebar_fragment.php'; ?>
        <main class="main-content">

            <div class="stat-grid">
                <div class="stat-card">
                    <div class="icon-box" style="color: #4f46e5;"><i class="fa-solid fa-users"></i></div>
                    <div>
                        <div style="font-size: 1.5rem; font-weight: 800;"><?= count($vendors) ?></div>
                        <div style="font-size: 0.65rem; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;">Total Vendors</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="icon-box" style="color: #10b981;"><i class="fa-solid fa-wallet"></i></div>
                    <div>
                        <div style="font-size: 1.5rem; font-weight: 800;">₹<?= number_format(array_sum(array_column($vendors, 'wallet_balance'))) ?></div>
                        <div style="font-size: 0.65rem; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;">Vendor Balances</div>
                    </div>
                </div>
            </div>

            <div class="search-bar">
                <i class="fa-solid fa-magnifying-glass" style="color: #94a3b8;"></i>
                <input type="text" id="vendorSearch" placeholder="Filter partners by name, business, or operational zone...">
            </div>

            <div class="premium-card">
                <div class="table-responsive">
                    <table class="modern-table" id="vTable">
                        <thead>
                            <tr>
                                <th>Vendor Info</th>
                                <th>City</th>
                                <th>Work History</th>
                                <th>Wallet Balance</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vendors as $v): ?>
                                <tr onclick="window.location='vendor_details.php?id=<?= $v['id'] ?>'" style="cursor: pointer;">
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 15px;">
                                            <div class="avatar"><?= strtoupper(substr($v['business_name'], 0, 1)) ?></div>
                                            <div>
                                                <div style="font-weight: 800; color: #0f172a; font-size: 0.95rem;"><?= htmlspecialchars($v['business_name']) ?></div>
                                                <div style="font-size: 0.75rem; color: #64748b; font-weight: 600;"><?= htmlspecialchars($v['phone']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 700; color: var(--primary); font-size: 0.85rem;"><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($v['city']) ?></div>
                                        <div style="font-size: 0.7rem; color: #94a3b8; font-weight: 600;"><?= htmlspecialchars($v['email']) ?></div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 800; font-size: 0.9rem;"><?= $v['total_jobs'] ?> <span style="font-size: 0.65rem; color: #64748b;">JOBS</span></div>
                                        <?php if ($v['pending_alerts'] > 0): ?>
                                            <div style="color: var(--danger); font-size: 0.65rem; font-weight: 800;"><i class="fa-solid fa-bolt"></i> <?= $v['pending_alerts'] ?> PENDING</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-weight: 800; color: var(--success); font-size: 0.95rem;">₹<?= number_format($v['wallet_balance'], 2) ?></div>
                                        <div style="font-size: 0.65rem; color: #64748b; font-weight: 700;">WALLET BALANCE</div>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?= $v['status'] ?>"><?= ucfirst($v['status']) ?></span>
                                    </td>
                                    <td onclick="event.stopPropagation()">
                                        <div style="display: flex; gap: 10px; align-items: center;">
                                            <a href="track_vendor.php?id=<?= $v['id'] ?>" class="action-btn" title="Track Vendor" style="color: #db2777;"><i class="fa-solid fa-satellite"></i></a>
                                            <form method="POST" style="margin:0;">
                                                <input type="hidden" name="vendor_id" value="<?= $v['id'] ?>">
                                                <input type="hidden" name="change_status" value="1">
                                                <select name="status" onchange="this.form.submit()" style="padding: 8px 12px; border-radius: 12px; border: 1px solid #e2e8f0; font-size: 0.75rem; font-weight: 700; background: white; cursor: pointer; outline: none; transition: border-color 0.2s;">
                                                    <option value="active" <?= $v['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                                    <option value="inactive" <?= $v['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                                    <option value="pending" <?= $v['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                                </select>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <script>
        document.getElementById('vendorSearch').addEventListener('input', function(e) {
            const term = e.target.value.toLowerCase();
            document.querySelectorAll('#vTable tbody tr').forEach(row => {
                row.style.display = row.innerText.toLowerCase().includes(term) ? '' : 'none';
            });
        });
    </script>
</body>

</html>