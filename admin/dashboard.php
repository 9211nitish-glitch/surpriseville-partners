<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

require_once "../db.php";
require_once "../db_main.php";

try {
    $q1 = $conn->query("SELECT COUNT(*) AS count FROM vendors");
    $total_vendors = $q1->fetch_assoc()['count'];

    $qOrderMain = $mainConn->query("SELECT COUNT(*) AS count FROM orders WHERE service_id IS NOT NULL");
    $mainOrderCount = $qOrderMain->fetch_assoc()['count'];

    $qOrderManual = $conn->query("SELECT COUNT(*) AS count FROM manual_tasks");
    $manualOrderCount = $qOrderManual->fetch_assoc()['count'];

    $total_orders = $mainOrderCount + $manualOrderCount;

    $q3 = $conn->query("SELECT COUNT(*) AS count FROM withdrawal_requests WHERE status='pending'");
    $pending_withdrawals = $q3->fetch_assoc()['count'];

    $q4 = $mainConn->query("SELECT COUNT(*) AS count FROM categories");
    $total_categories = $q4->fetch_assoc()['count'];

    $q5 = $conn->query("SELECT COUNT(*) AS count FROM manual_tasks WHERE status='completed'");
    $pending_gigs = $q5->fetch_assoc()['count'];
} catch (Exception $e) {
    die("Intelligence Failure: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Surprise Ville</title>
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

    .intel-banner {
        background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%);
        color: #fff;
        padding: 3rem;
        border-radius: 32px;
        margin-bottom: 2.5rem;
        position: relative;
        overflow: hidden;
        box-shadow: 0 25px 50px -12px rgba(30, 27, 75, 0.25);
        animation: fadeInDown 0.6s ease-out;
    }

    .intel-banner::after {
        content: "\f0e4";
        font-family: "Font Awesome 6 Free";
        font-weight: 900;
        position: absolute;
        right: -20px;
        bottom: -20px;
        font-size: 15rem;
        opacity: 0.05;
        transform: rotate(-15deg);
    }

    .stats-matrix {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2.5rem;
    }

    .stat-node {
        background: var(--glass);
        backdrop-filter: blur(12px);
        padding: 1.75rem;
        border-radius: 24px;
        border: 1px solid var(--glass-border);
        display: flex;
        align-items: center;
        gap: 1.25rem;
        text-decoration: none;
        color: inherit;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: var(--shadow);
    }

    .stat-node:hover {
        transform: translateY(-8px);
        border-color: var(--primary);
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    }

    .icon-vault {
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

    .node-value {
        font-size: 1.75rem;
        font-weight: 800;
        margin: 2px 0;
        display: block;
        color: #0f172a;
    }

    .node-label {
        font-size: 0.7rem;
        font-weight: 800;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 1.5px;
    }

    .operational-grid {
        display: grid;
        grid-template-columns: 1.5fr 1fr;
        gap: 2rem;
    }

    .control-panel {
        background: var(--glass);
        backdrop-filter: blur(12px);
        border-radius: 32px;
        padding: 2rem;
        border: 1px solid var(--glass-border);
        box-shadow: var(--shadow);
    }

    .action-button {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 1.25rem;
        border-radius: 18px;
        background: white;
        text-decoration: none;
        color: #1e293b;
        font-weight: 700;
        margin-bottom: 1rem;
        transition: all 0.2s;
        border: 1px solid #e2e8f0;
    }

    .action-button:hover {
        background: #f8fafc;
        border-color: var(--primary);
        transform: translateX(8px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }

    .action-icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
    }

    @keyframes fadeInDown {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .quick-actions-grid {
        grid-template-columns: 1fr 1fr;
    }

    @media (max-width: 600px) {
        .quick-actions-grid {
            grid-template-columns: 1fr !important;
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

    @media (max-width: 1024px) {
        .dashboard-container { 
            flex-direction: column; 
            padding: 1.5rem; 
            gap: 1rem;
        }
        .header {
            padding: 1rem;
        }
        .sidebar-toggle {
            display: flex !important;
        }
        .stats-matrix {
            grid-template-columns: 1fr 1fr;
        }
        .operational-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 640px) {
        .stats-matrix {
            grid-template-columns: 1fr;
        }
        .header h1 {
            font-size: 1.1rem;
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
                <i class="fa-solid fa-bolt"></i>
            </div>
            <img src="../surpriseville-logo.png" alt="Surprise Ville" style="max-height: 35px; max-width: 150px; object-fit: contain;">
        </div>
        <nav style="display: flex; align-items: center; gap: 25px;">
            <div style="display: flex; align-items: center; gap: 10px; background: white; padding: 8px 16px; border-radius: 12px; border: 1px solid #e2e8f0;">
                <i class="fa-solid fa-shield-halved" style="color: var(--primary);"></i>
                <span style="font-weight: 700; font-size: 0.85rem; color: #475569;">Admin Root</span>
            </div>
            <a href="logout.php" style="color: var(--danger); font-weight: 800; text-decoration: none; font-size: 0.9rem; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-power-off"></i> Logout
            </a>
        </nav>
    </header>

    <div class="dashboard-container">
            <?php include 'sidebar_fragment.php'; ?>
            <main class="main-content">

                <div class="intel-banner">
                    <div style="font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 3px; opacity: 0.6; margin-bottom: 10px;">Admin Panel</div>
                    <h2 style="margin: 0; font-weight: 800; font-size: 2.5rem; letter-spacing: -1px;">Dashboard</h2>
                    <p style="margin: 10px 0 0; font-weight: 600; opacity: 0.8; font-size: 1.1rem;">Manage your orders and vendors easily here</p>
                </div>

                <div class="stats-matrix">
                    <a href="vendors.php" class="stat-node">
                        <div class="icon-vault" style="color: #3b82f6; background: #eff6ff;"><i class="fa-solid fa-users-gear"></i></div>
                        <div>
                            <span class="node-label">Total Vendors</span>
                            <span class="node-value animate-value" id="stat-vendors"><?= $total_vendors ?></span>
                        </div>
                    </a>
                    <a href="orders.php" class="stat-node">
                        <div class="icon-vault" style="color: #10b981; background: #ecfdf5;"><i class="fa-solid fa-rocket"></i></div>
                        <div>
                            <span class="node-label">Total Orders</span>
                            <span class="node-value animate-value" id="stat-orders"><?= $total_orders ?></span>
                        </div>
                    </a>
                    <a href="withdrawals.php" class="stat-node">
                        <div class="icon-vault" style="color: #f59e0b; background: #fffbeb;"><i class="fa-solid fa-vault"></i></div>
                        <div>
                            <span class="node-label">Withdrawal Requests</span>
                            <span class="node-value animate-value" id="stat-withdrawals"><?= $pending_withdrawals ?></span>
                        </div>
                    </a>
                    <a href="manage_gigs.php" class="stat-node" style="border-left: 4px solid var(--warning);">
                        <div class="icon-vault" style="color: #d97706; background: #fffbeb;"><i class="fa-solid fa-clipboard-check"></i></div>
                        <div>
                            <span class="node-label">Tasks to Approve</span>
                            <span class="node-value animate-value" id="stat-gigs"><?= $pending_gigs ?></span>
                        </div>
                    </a>
                </div>

                <div class="operational-grid">
                    <div class="control-panel">
                        <h3 style="margin: 0 0 25px; font-weight: 800; font-size: 1.3rem; display: flex; align-items: center; gap: 12px;">
                            <i class="fa-solid fa-bolt" style="color: var(--primary);"></i> Quick Actions
                        </h3>
                        <div class="quick-actions-grid" style="display: grid; gap: 20px;">
                            <a href="manage_gigs.php" class="action-button">
                                <div class="action-icon" style="background: #eff6ff; color: #3b82f6;"><i class="fa-solid fa-plus-circle"></i></div>
                                <div>Create New Task</div>
                            </a>
                            <a href="earnings_report.php" class="action-button">
                                <div class="action-icon" style="background: #f0fdf4; color: #10b981;"><i class="fa-solid fa-chart-line"></i></div>
                                <div>Earnings Report</div>
                            </a>
                            <a href="categories.php" class="action-button">
                                <div class="action-icon" style="background: #fdf2f8; color: #db2777;"><i class="fa-solid fa-tags"></i></div>
                                <div>Manage Categories</div>
                            </a>
                            <a href="vendors.php" class="action-button">
                                <div class="action-icon" style="background: #f1f5f9; color: #475569;"><i class="fa-solid fa-user-plus"></i></div>
                                <div>Add Vendor</div>
                            </a>
                        </div>
                    </div>

                    <div class="control-panel" style="background: #0f172a; color: #fff; border: none;">
                        <h3 style="margin: 0 0 20px; font-weight: 800; font-size: 1.1rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px;">Recent Activity</h3>
                        <div id="telemetry-log" style="font-family: 'Courier New', monospace; font-size: 0.8rem; line-height: 1.8; color: #10b981;">
                            <div>[SYSTEM] System Status: ONLINE</div>
                            <div>[SYSTEM] Updating metrics...</div>
                            <div style="color: #64748b;">[WAIT] Refreshing in 15s...</div>
                        </div>
                    </div>
                </div>
            </main>
        </div>

    <script>
        function updateStats() {
            fetch('ajax/get_dashboard_stats.php')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        updateVal('stat-vendors', data.total_vendors);
                        updateVal('stat-orders', data.total_orders);
                        updateVal('stat-withdrawals', data.pending_withdrawals);
                        updateVal('stat-gigs', data.pending_gigs);

                        const log = document.getElementById('telemetry-log');
                        const time = new Date().toLocaleTimeString();
                        log.innerHTML = `<div>[${time}] UPDATED SUCCESSFULLY</div>` + log.innerHTML;
                        if (log.children.length > 5) log.removeChild(log.lastChild);
                    }
                });
        }

        function updateVal(id, val) {
            const el = document.getElementById(id);
            if (parseInt(el.innerText) !== val) {
                el.innerText = val;
                el.style.color = 'var(--success)';
                setTimeout(() => el.style.color = '', 1000);
            }
        }

        setInterval(updateStats, 15000);
    </script>
</body>

</html>