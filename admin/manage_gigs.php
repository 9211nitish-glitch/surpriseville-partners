<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';
require_once '../db_main.php';
require_once '../backend/whatsapp_helper.php';

/* ================= APPROVE TASK ================= */
if (isset($_POST['approve_task'])) {
    $tid = (int)$_POST['task_id'];
    $q = $conn->query("
        SELECT mt.*, tc.payment_mode 
        FROM manual_tasks mt
        JOIN task_completions tc ON mt.id = tc.task_id
        WHERE mt.id = $tid
    ");
    $task = $q->fetch_assoc();

    if ($task && $task['status'] === 'completed') {
        $vid  = (int)$task['assigned_vendor_id'];
        $vRole = 'external';
        $vQ = $conn->query("SELECT role FROM vendors WHERE id=$vid LIMIT 1");
        if ($vRow = $vQ->fetch_assoc()) {
            $vRole = strtolower(trim($vRow['role']));
        }

        $vPrice = isset($task['vendor_price']) ? floatval($task['vendor_price']) : 0;
        $ivPrice = isset($task['internal_vendor_price']) ? floatval($task['internal_vendor_price']) : 0;

        if ($vRole === 'internal') {
            $amt = $vPrice > 0 ? $vPrice : 0;
        } else {
            $amt = $ivPrice > 0 ? $ivPrice : $vPrice;
        }

        $mode = $task['payment_mode'];

        $conn->query("UPDATE manual_tasks SET status='verified' WHERE id=$tid");
        $conn->query("UPDATE task_completions SET admin_approved=1 WHERE task_id=$tid");

        sendOrderStatusNotification($conn, $tid, 'completed', true);

        $chk = $conn->query("SELECT id FROM vendor_wallet WHERE vendor_id=$vid");
        if ($chk->num_rows === 0) {
            $conn->query("INSERT INTO vendor_wallet (vendor_id, balance, total_earned) VALUES ($vid,0,0)");
        }

        if ($mode === 'online') {
            $conn->query("UPDATE vendor_wallet SET balance=balance+$amt, total_earned=total_earned+$amt WHERE vendor_id=$vid");
            $type = 'credit';
            $desc = "Earnings for Gig Task #$tid (Online)";
        } else {
            $conn->query("UPDATE vendor_wallet SET total_earned=total_earned+$amt WHERE vendor_id=$vid");
            $type = 'cash';
            $desc = "Received in Cash for Gig #$tid";
        }

        $stmt = $conn->prepare("
            INSERT INTO wallet_transactions (vendor_id, type, amount, description, status)
            VALUES (?, ?, ?, ?, 'completed')
        ");
        $stmt->bind_param("isds", $vid, $type, $amt, $desc);
        $stmt->execute();

        echo "<script>alert('Task Verified Successfully'); location.href='manage_gigs.php';</script>";
        exit;
    }
}

/* ================= FETCH TASKS WITH PAGINATION ================= */
$limit = 10;
$page  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$countQuery = $conn->query("SELECT COUNT(*) as total FROM manual_tasks");
$totalRows = $countQuery->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $limit);

// Fetch all categories from main DB
$categories = [];
$catQuery = $mainConn->query("SELECT id, name FROM categories");
if ($catQuery) {
    while ($catRow = $catQuery->fetch_assoc()) {
        $categories[$catRow['id']] = $catRow['name'];
    }
}

// Fetch all subcategories from main DB
$subcategories = [];
$subcatQuery = $mainConn->query("SELECT id, name FROM subcategories");
if ($subcatQuery) {
    while ($subcatRow = $subcatQuery->fetch_assoc()) {
        $subcategories[$subcatRow['id']] = $subcatRow['name'];
    }
}

$sql = "
SELECT mt.*, v.name vendor_name, v.phone vendor_phone, v.role vendor_role,
       tc.proof_media, tc.payment_mode, tc.payment_screenshot
FROM manual_tasks mt
LEFT JOIN vendors v ON v.id = mt.assigned_vendor_id
LEFT JOIN task_completions tc ON tc.task_id = mt.id
ORDER BY mt.created_at DESC
LIMIT $limit OFFSET $offset
";
$res = $conn->query($sql);

$statsRes = $conn->query("SELECT status, COUNT(*) as count FROM manual_tasks GROUP BY status");
$stats = ['open' => 0, 'assigned' => 0, 'completed' => 0, 'verified' => 0];
while ($sRow = $statsRes->fetch_assoc()) {
    $stats[$sRow['status']] = $sRow['count'];
}
?>
<!DOCTYPE html>
<html lang="en">
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

    .stat-card:hover {
        transform: translateY(-5px);
    }

    .icon-box {
        width: 50px;
        height: 50px;
        border-radius: 14px;
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
        border-radius: 28px;
        border: 1px solid var(--glass-border);
        box-shadow: var(--shadow);
        overflow: hidden;
        width: 100%;
    }

    .table-responsive {
        width: 100%;
        overflow-x: auto;
    }

    .modern-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1100px;
    }

    .modern-table th {
        background: rgba(248, 250, 252, 0.5);
        padding: 1.25rem 1rem;
        text-align: left;
        font-size: 0.75rem;
        text-transform: uppercase;
        font-weight: 800;
        color: #64748b;
        letter-spacing: 1px;
        border-bottom: 1px solid var(--glass-border);
    }

    .modern-table td {
        padding: 1.25rem 1rem;
        border-bottom: 1px solid rgba(226, 232, 240, 0.5);
        vertical-align: middle;
        font-size: 0.9rem;
        background: rgba(255, 255, 255, 0.3);
    }

    .modern-table tr:hover td {
        background: rgba(255, 255, 255, 0.6);
    }

    .status-badge {
        padding: 6px 12px;
        border-radius: 30px;
        font-size: 0.7rem;
        font-weight: 800;
        text-transform: uppercase;
        display: inline-block;
        letter-spacing: 0.5px;
    }

    .status-open {
        background: #fef3c7;
        color: #92400e;
    }

    .status-assigned {
        background: #dbeafe;
        color: #1e40af;
    }

    .status-completed {
        background: #dcfce7;
        color: #166534;
    }

    .status-verified {
        background: #f1f5f9;
        color: #475569;
    }

    .payment-badge {
        font-size: 0.65rem;
        font-weight: 800;
        padding: 4px 8px;
        border-radius: 6px;
        text-transform: uppercase;
    }

    .mode-online {
        background: #e0f2fe;
        color: #0369a1;
    }

    .mode-cash {
        background: #ffedd5;
        color: #9a3412;
    }

    .btn-action {
        padding: 8px 14px;
        border-radius: 10px;
        font-size: 0.8rem;
        font-weight: 700;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        transition: all 0.2s;
        border: none;
        cursor: pointer;
        white-space: nowrap;
    }

    .btn-primary {
        background: var(--primary);
        color: #fff;
        box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.2);
    }

    .btn-success {
        background: var(--success);
        color: #fff;
        box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.2);
    }

    .btn-outline {
        background: white;
        border: 1px solid #e2e8f0;
        color: #64748b;
    }

    .btn-action:hover {
        transform: translateY(-2px);
        opacity: 0.9;
    }

    .proof-btn {
        background: white;
        color: var(--primary);
        padding: 6px 10px;
        border-radius: 8px;
        font-size: 0.7rem;
        font-weight: 800;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 5px;
        border: 1px solid #e2e8f0;
        transition: all 0.2s;
    }

    .proof-btn:hover {
        background: #f8fafc;
        border-color: var(--primary);
    }

    /* Modal Protocol */
    .modal {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.8);
        z-index: 9999;
        backdrop-filter: blur(12px);
        align-items: center;
        justify-content: center;
        padding: 2rem;
    }

    .modal-content {
        max-width: 90%;
        max-height: 90vh;
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(20px);
        padding: 2.5rem;
        border-radius: 32px;
        position: relative;
        animation: zoomIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        overflow-y: auto;
        box-shadow: 0 50px 100px -20px rgba(0, 0, 0, 0.5);
        border: 1px solid rgba(255, 255, 255, 0.5);
    }

    #mediaContainer {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 1.5rem;
    }

    .media-item {
        width: 100%;
        height: auto;
        border-radius: 20px;
        box-shadow: 0 10px 20px -5px rgba(0, 0, 0, 0.1);
        transition: transform 0.3s ease;
    }

    .media-item:hover {
        transform: scale(1.02);
    }

    .close-modal-btn {
        position: absolute;
        top: 1.5rem;
        right: 1.5rem;
        width: 40px;
        height: 40px;
        background: #f1f5f9;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: #64748b;
        transition: all 0.2s;
        z-index: 10;
    }

    .close-modal-btn:hover {
        background: #e2e8f0;
        color: var(--danger);
    }

    @keyframes zoomIn {
        from {
            opacity: 0;
            transform: scale(0.95);
        }

        to {
            opacity: 1;
            transform: scale(1);
        }
    }

    /* Pagination Protocol */
    .pagination-container {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 10px;
        padding: 1.5rem;
        background: rgba(248, 250, 252, 0.4);
        border-top: 1px solid var(--glass-border);
    }

    .pagination-link {
        width: 38px;
        height: 38px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        color: #64748b;
        text-decoration: none;
        font-weight: 700;
        font-size: 0.9rem;
        transition: all 0.2s;
    }

    .pagination-link:hover {
        border-color: var(--primary);
        color: var(--primary);
        transform: translateY(-2px);
    }

    .pagination-link.active {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
        box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
    }

    .pagination-link.disabled {
        opacity: 0.5;
        pointer-events: none;
        background: #f1f5f9;
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

    .sidebar-toggle:hover {
        background: #f8fafc;
        transform: scale(1.05);
    }

    @media (max-width: 1024px) {
        .dashboard-container {
            flex-direction: column;
            padding: 1rem;
            gap: 1rem;
        }

        .header {
            padding: 1rem;
        }

        .sidebar-toggle {
            display: flex !important;
        }

        .stat-grid {
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
    }

    @media (max-width: 640px) {
        .stat-grid {
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
                <i class="fa-solid fa-list-check"></i>
            </div>
            <h1 style="margin: 0; font-size: 1.4rem; font-weight: 800; letter-spacing: -0.5px;">Offline Orders</h1>
        </div>
        <nav style="display: flex; align-items: center; gap: 20px;">
            <a href="create_manual_task.php" style="background: var(--primary); color: #fff; padding: 12px 24px; border-radius: 14px; text-decoration: none; font-weight: 800; font-size: 0.9rem; box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.3);">
                <i class="fa-solid fa-plus"></i> Create New Task
            </a>
        </nav>
    </header>

    <div class="dashboard-container">
        <?php include 'sidebar_fragment.php'; ?>

        <main class="main-content">

            <div class="stat-grid">
                <div class="stat-card">
                    <div class="icon-box" style="background: #fff7ed; color: #f97316;"><i class="fa-solid fa-folder-open"></i></div>
                    <div>
                        <div style="font-weight: 800; font-size: 1.3rem;"><?= $stats['open'] ?></div>
                        <div style="font-size: 0.65rem; color: #64748b; font-weight: 700; text-transform: uppercase;">New Tasks</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="icon-box" style="background: #eff6ff; color: #3b82f6;"><i class="fa-solid fa-user-check"></i></div>
                    <div>
                        <div style="font-weight: 800; font-size: 1.3rem;"><?= $stats['assigned'] ?></div>
                        <div style="font-size: 0.65rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Assigned</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="icon-box" style="background: #f0fdf4; color: #22c55e;"><i class="fa-solid fa-clipboard-check"></i></div>
                    <div>
                        <div style="font-weight: 800; font-size: 1.3rem;"><?= $stats['completed'] ?></div>
                        <div style="font-size: 0.65rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Completed</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="icon-box" style="background: #f8fafc; color: #64748b;"><i class="fa-solid fa-shield-check"></i></div>
                    <div>
                        <div style="font-weight: 800; font-size: 1.3rem;"><?= $stats['verified'] ?></div>
                        <div style="font-size: 0.65rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Verified</div>
                    </div>
                </div>
            </div>

            <div class="premium-card">
                <div class="table-responsive">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Category</th>
                                <th>Order Type</th>
                                <th>Service/Addon</th>
                                <th>Schedule</th>
                                <th>Vendor</th>
                                <th>Status</th>
                                <th>Payment</th>
                                <th>Vendor Price</th>
                                <th>Internal Price</th>
                                <th>Proofs</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($res->num_rows): while ($row = $res->fetch_assoc()):
                                    $proofs = json_decode($row['proof_media'] ?? '[]', true);
                            ?>
                                    <tr>
                                        <td style="font-weight: 800; color: #64748b;">#<?= $row['id'] ?></td>
                                        <td>
                                            <div style="font-size: 0.75rem; font-weight: 700; color: #475569; display: flex; flex-direction: column; gap: 4px;">
                                                <div>
                                                    <i class="fa-solid fa-tag" style="color: var(--primary); opacity: 0.5; margin-right: 2px;"></i>
                                                    <?= htmlspecialchars($categories[$row['category_id']] ?? 'N/A') ?>
                                                </div>
                                                <?php if (!empty($row['subcategory_id']) && isset($subcategories[$row['subcategory_id']])): ?>
                                                    <div style="font-size: 0.7rem; color: #64748b; font-weight: 600; padding-left: 14px;">
                                                        <i class="fa-solid fa-turn-up fa-rotate-90" style="opacity: 0.5; font-size: 0.65rem;"></i>
                                                        <?= htmlspecialchars($subcategories[$row['subcategory_id']]) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-size: 0.75rem; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 6px; background: rgba(79, 70, 229, 0.05); padding: 4px 8px; border-radius: 6px; border: 1px solid rgba(79, 70, 229, 0.1);">
                                                <i class="fa-solid fa-briefcase" style="opacity: 0.5;"></i>
                                                <?= htmlspecialchars($row['order_type'] ?: 'N/A') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight: 800; color: #0f172a; font-size: 0.95rem;"><?= htmlspecialchars($row['service_title'] ?: 'General Task') ?></div>
                                            <div style="font-size: 0.75rem; color: var(--danger); font-weight: 700; margin-top: 4px; display: flex; align-items: center; gap: 4px;">
                                                <i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($row['locality'] ?? 'N/A') ?>
                                            </div>
                                            <div style="font-size: 0.7rem; color: #64748b; margin-top: 2px; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($row['full_address'] ?? '') ?>">
                                                <?= htmlspecialchars($row['full_address'] ?? '') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php
                                            $reach_dt = !empty($row['reach_datetime']) ? strtotime($row['reach_datetime']) : null;
                                            $remarks = $row['remarks'] ?? '';
                                            $ready_time = 'N/A';
                                            if (preg_match('/Ready:\s*([^\n\r]*)/i', $remarks, $matches)) {
                                                $ready_time = trim($matches[1]);
                                            }
                                            ?>
                                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                                <div style="font-weight: 700; font-size: 0.85rem; color: #1e293b;">
                                                    <i class="fa-regular fa-calendar" style="margin-right: 4px; color: var(--primary);"></i>
                                                    <?= $reach_dt ? date('d M, Y', $reach_dt) : 'TBD' ?>
                                                </div>
                                                <div style="font-size: 0.7rem; font-weight: 600; color: #64748b;">
                                                    <i class="fa-regular fa-clock" style="margin-right: 4px;"></i>
                                                    Reach: <?= $reach_dt ? date('h:i A', $reach_dt) : 'N/A' ?>
                                                </div>
                                                <div style="font-size: 0.7rem; font-weight: 600; color: #94a3b8;">
                                                    <i class="fa-regular fa-clock" style="margin-right: 4px;"></i>
                                                    Ready: <?= htmlspecialchars($ready_time) ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($row['vendor_name']): ?>
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <div style="width: 32px; height: 32px; background: #f1f5f9; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--primary); font-weight: 800; font-size: 0.7rem;">
                                                        <?= strtoupper(substr($row['vendor_name'], 0, 1)) ?>
                                                    </div>
                                                    <div>
                                                        <div style="font-weight: 700; color: #1e293b;"><?= htmlspecialchars($row['vendor_name']) ?></div>
                                                        <div style="font-size: 0.7rem; color: #64748b;"><i class="fa-solid fa-phone" style="font-size: 0.6rem;"></i> <?= $row['vendor_phone'] ?></div>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <div style="display: flex; align-items: center; gap: 8px; color: #94a3b8;">
                                                    <i class="fa-solid fa-user-slash"></i>
                                                    <span style="font-style: italic; font-size: 0.8rem;">Unassigned</span>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?= strtolower($row['status']) ?>"><?= strtoupper($row['status']) ?></span>
                                        </td>
                                        <td>
                                            <?php if ($row['payment_mode']): ?>
                                                <span class="payment-badge mode-<?= strtolower($row['payment_mode']) ?>"><?= $row['payment_mode'] ?></span>
                                            <?php else: ?>
                                                <span style="color: #cbd5e1;">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="font-weight: 800; color: var(--dark);">₹<?= number_format($row['vendor_price']) ?></div>
                                        </td>
                                        <td>
                                            <div style="font-weight: 800; color: var(--primary);">₹<?= number_format($row['internal_vendor_price']) ?></div>
                                        </td>
                                        <td>
                                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                                <?php if ($row['payment_screenshot']): ?>
                                                    <div class="proof-btn" onclick="window.open('../uploads/proofs/<?= $row['payment_screenshot'] ?>')"><i class="fa-solid fa-receipt"></i> BILL</div>
                                                <?php endif; ?>
                                                <?php if (!empty($proofs)): ?>
                                                    <div class="proof-btn" onclick='openModal(<?= json_encode($proofs) ?>)'><i class="fa-solid fa-images"></i> IMAGES (<?= count($proofs) ?>)</div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                                <?php if ($row['status'] === 'completed'): ?>
                                                    <form method="POST" onsubmit="return confirm('Approve this task?');">
                                                        <input type="hidden" name="task_id" value="<?= $row['id'] ?>">
                                                        <button type="submit" name="approve_task" class="btn-action btn-success"><i class="fa-solid fa-check-double"></i> Approve</button>
                                                    </form>
                                                <?php elseif (($row['status'] === 'open' || !$row['assigned_vendor_id']) && !in_array($row['status'], ['verified', 'cancelled'])): ?>
                                                    <a href="allocate_order.php?task_id=<?= $row['id'] ?>&type=gig" class="btn-action btn-primary"><i class="fa-solid fa-user-plus"></i> Allocate</a>
                                                <?php endif; ?>
                                                <a href="tracking.php?type=gig&track_id=<?= $row['id'] ?>" class="btn-action btn-outline"><i class="fa-solid fa-eye"></i> Details</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile;
                            else: ?>
                                <tr>
                                    <td colspan="10" style="text-align:center; padding:50px; color:#94a3b8;"><i class="fa-solid fa-inbox fa-3x"></i><br>No tasks found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="pagination-container">
                        <a href="?page=<?= max(1, $page - 1) ?>" class="pagination-link <?= ($page <= 1) ? 'disabled' : '' ?>">
                            <i class="fa-solid fa-chevron-left"></i>
                        </a>

                        <?php
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);

                        for ($i = $start; $i <= $end; $i++):
                        ?>
                            <a href="?page=<?= $i ?>" class="pagination-link <?= ($i == $page) ? 'active' : '' ?>"><?= $i ?></a>
                        <?php endfor; ?>

                        <a href="?page=<?= min($totalPages, $page + 1) ?>" class="pagination-link <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                            <i class="fa-solid fa-chevron-right"></i>
                        </a>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>
    </div>

    <div id="visualModal" class="modal" onclick="this.style.display='none'">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="close-modal-btn" onclick="document.getElementById('visualModal').style.display='none'">
                <i class="fa-solid fa-xmark"></i>
            </div>
            <h2 style="margin-bottom: 2rem; font-weight: 800; font-size: 1.5rem;">Task Proof Images</h2>
            <div id="mediaContainer"></div>
        </div>
    </div>

    <script>
        function openModal(proofs) {
            if (!Array.isArray(proofs) || proofs.length === 0) return;
            const container = document.getElementById('mediaContainer');
            container.innerHTML = '';
            proofs.forEach(p => {
                const isVid = p.match(/\.(mp4|webm|ogg|mov)$/i);
                const el = document.createElement(isVid ? 'video' : 'img');
                el.src = '../uploads/proofs/' + p;
                el.className = 'media-item';
                if (isVid) {
                    el.controls = true;
                    el.muted = true;
                }
                container.appendChild(el);
            });
            document.getElementById('visualModal').style.display = 'flex';
        }
    </script>
</body>

</html>