<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';

$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to   = isset($_GET['date_to'])   ? $_GET['date_to']   : '';
$vendor_id = isset($_GET['vendor_id']) ? intval($_GET['vendor_id']) : 0;

if (!$date_from && !$date_to) {
    $date_from = date('Y-m-01');
    $date_to   = date('Y-m-d');
}

$vendorOptions = [];
$resV = $conn->query("SELECT id, name, business_name FROM vendors ORDER BY name ASC");
while ($row = $resV->fetch_assoc()) {
    $vendorOptions[] = $row;
}

$wallet_snapshot = null;
if ($vendor_id > 0) {
    $ws = $conn->prepare("SELECT * FROM vendor_wallet WHERE vendor_id = ?");
    $ws->bind_param("i", $vendor_id);
    $ws->execute();
    $wallet_snapshot = $ws->get_result()->fetch_assoc();
    $ws->close();
}

$params = [];
$types  = '';
$where  = "WHERE wt.status = 'completed'";

if ($date_from) {
    $where .= " AND DATE(wt.created_at) >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if ($date_to) {
    $where .= " AND DATE(wt.created_at) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

if ($vendor_id > 0) {
    $where .= " AND wt.vendor_id = ?";
    $params[] = $vendor_id;
    $types .= 'i';
}

$sql = "
    SELECT 
        DATE(wt.created_at) AS earning_date,
        wt.vendor_id,
        v.name AS vendor_name,
        v.business_name,
        SUM(CASE WHEN wt.type IN ('credit', 'cash') THEN wt.amount ELSE 0 END) AS total_earned,
        SUM(CASE WHEN wt.type IN ('debit', 'withdrawal') THEN wt.amount ELSE 0 END) AS total_withdrawn,
        COUNT(CASE WHEN wt.type IN ('credit', 'cash') THEN 1 END) AS jobs_completed
    FROM wallet_transactions wt
    INNER JOIN vendors v ON wt.vendor_id = v.id
    $where
    GROUP BY DATE(wt.created_at), wt.vendor_id
    ORDER BY earning_date DESC, vendor_name ASC
";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
$period_earned = 0;
$period_withdrawn = 0;

while ($r = $res->fetch_assoc()) {
    $r['net'] = $r['total_earned'] - $r['total_withdrawn'];
    $rows[] = $r;
    $period_earned += $r['total_earned'];
    $period_withdrawn += $r['total_withdrawn'];
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Earnings Report | Surprise Ville</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --success: #10b981;
            --danger: #ef4444;
            --border: #e2e8f0;
            --dark: #1e293b;
        }

        body {
            background-color: #f1f5f9;
            font-family: 'Inter', sans-serif;
        }

        .main-content {
            padding: 30px;
        }

        .fiscal-banner {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #fff;
            padding: 35px;
            border-radius: 28px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: #fff;
            padding: 25px;
            border-radius: 24px;
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 20px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02);
        }

        .icon-box {
            width: 54px;
            height: 54px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .filter-protocol {
            background: #fff;
            padding: 25px;
            border-radius: 24px;
            border: 1px solid var(--border);
            margin-bottom: 30px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)) auto;
            gap: 20px;
            align-items: flex-end;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 0.75rem;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
        }

        .protocol-input {
            width: 100%;
            padding: 12px 18px;
            border-radius: 12px;
            border: 1px solid var(--border);
            font-family: inherit;
            font-weight: 700;
            background: #f8fafc;
        }

        .btn-protocol {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 14px 30px;
            border-radius: 14px;
            font-weight: 800;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.2s;
        }

        .btn-protocol:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(67, 97, 238, 0.25);
        }

        .report-card {
            background: #fff;
            border-radius: 28px;
            border: 1px solid var(--border);
            overflow: hidden;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
        }

        .modern-table {
            width: 100%;
            border-collapse: collapse;
        }

        .modern-table th {
            background: #f8fafc;
            padding: 18px 25px;
            text-align: left;
            font-size: 0.7rem;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            border-bottom: 2px solid var(--border);
        }

        .modern-table td {
            padding: 20px 25px;
            border-bottom: 1px solid var(--border);
        }
    </style>
</head>

<body>

    <div class="header" style="background: #fff; border-bottom: 1px solid var(--border); padding: 15px 30px;">
        <h1 style="margin: 0; font-size: 1.5rem; font-weight: 800;">Earnings Report</h1>
        <nav>
            <span style="font-weight: 700; color: #64748b;"><i class="fa-solid fa-chart-pie"></i> Check your earnings and payments here</span>
        </nav>
    </div>

    <div class="container">
        <div class="dashboard-layout">
            <?php include 'sidebar_fragment.php'; ?>
            <main class="main-content">

                <?php if ($wallet_snapshot): ?>
                    <div class="fiscal-banner">
                        <div>
                            <div style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 2px; opacity: 0.7;">Vendor Info</div>
                            <h2 style="margin: 5px 0; font-weight: 800; font-size: 1.8rem;"><?= htmlspecialchars($wallet_snapshot['business_name'] ?? 'Vendor') ?></h2>
                            <div style="font-weight: 600; opacity: 0.8;"><?= date('d M Y', strtotime($date_from)) ?> — <?= date('d M Y', strtotime($date_to)) ?></div>
                        </div>
                        <div style="display: flex; gap: 40px;">
                            <div style="text-align: right;">
                                <div style="font-size: 0.65rem; font-weight: 800; opacity: 0.6; text-transform: uppercase;">Current Balance</div>
                                <div style="font-size: 1.8rem; font-weight: 800;">₹<?= number_format($wallet_snapshot['balance'], 2) ?></div>
                            </div>
                            <div style="text-align: right;">
                                <div style="font-size: 0.65rem; font-weight: 800; opacity: 0.6; text-transform: uppercase;">Total Withdrawn</div>
                                <div style="font-size: 1.8rem; font-weight: 800;">₹<?= number_format($wallet_snapshot['total_withdrawn'], 2) ?></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="GET" class="filter-protocol">
                    <div class="form-group">
                        <label>Start Date</label>
                        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="protocol-input">
                    </div>
                    <div class="form-group">
                        <label>End Date</label>
                        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="protocol-input">
                    </div>
                    <div class="form-group">
                        <label>Select Vendor</label>
                        <select name="vendor_id" class="protocol-input">
                            <option value="0">All Vendors</option>
                            <?php foreach ($vendorOptions as $v): ?>
                                <option value="<?= $v['id'] ?>" <?= ($vendor_id == $v['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($v['name'] . ' (' . $v['business_name'] . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn-protocol"><i class="fa-solid fa-bolt"></i> View Report</button>
                </form>

                <div class="stat-grid">
                    <div class="stat-card">
                        <div class="icon-box" style="background: #f0fdf4; color: var(--success);"><i class="fa-solid fa-arrow-trend-up"></i></div>
                        <div>
                            <div style="font-size: 1.5rem; font-weight: 800;">₹<?= number_format($period_earned, 2) ?></div>
                            <div style="font-size: 0.7rem; color: #64748b; font-weight: 800; text-transform: uppercase;">Total Earned</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="icon-box" style="background: #fff1f2; color: var(--danger);"><i class="fa-solid fa-arrow-trend-down"></i></div>
                        <div>
                            <div style="font-size: 1.5rem; font-weight: 800;">₹<?= number_format($period_withdrawn, 2) ?></div>
                            <div style="font-size: 0.7rem; color: #64748b; font-weight: 800; text-transform: uppercase;">Total Withdrawn</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="icon-box" style="background: #eff6ff; color: var(--primary);"><i class="fa-solid fa-scale-balanced"></i></div>
                        <div>
                            <div style="font-size: 1.5rem; font-weight: 800;">₹<?= number_format($period_earned - $period_withdrawn, 2) ?></div>
                            <div style="font-size: 0.7rem; color: #64748b; font-weight: 800; text-transform: uppercase;">Profit</div>
                        </div>
                    </div>
                </div>

                <div class="report-card">
                    <div style="overflow-x: auto;">
                        <table class="modern-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Vendor</th>
                                    <th>Earned</th>
                                    <th>Withdrawn</th>
                                    <th>Balance</th>
                                    <th>Tasks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($rows)): ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 100px; color: #94a3b8;"><i class="fa-solid fa-file-invoice-dollar fa-3x" style="margin-bottom:20px;"></i><br>No data found for this period</td>
                                    </tr>
                                    <?php else: foreach ($rows as $r): ?>
                                        <tr>
                                            <td style="font-weight: 800; color: #64748b;"><?= date('d M, Y', strtotime($r['earning_date'])) ?></td>
                                            <td>
                                                <div style="font-weight: 800; color: var(--dark);"><?= htmlspecialchars($r['vendor_name']) ?></div>
                                                <div style="font-size: 0.75rem; color: #64748b; font-weight: 700;"><?= htmlspecialchars($r['business_name']) ?></div>
                                            </td>
                                            <td style="color: var(--success); font-weight: 800;">₹<?= number_format($r['total_earned'], 2) ?></td>
                                            <td style="color: var(--danger); font-weight: 800;">₹<?= number_format($r['total_withdrawn'], 2) ?></td>
                                            <td style="font-weight: 800; color: var(--dark);">₹<?= number_format($r['net'], 2) ?></td>
                                            <td>
                                                <span style="background: #f1f5f9; color: #1e293b; padding: 6px 14px; border-radius: 10px; font-weight: 800; font-size: 0.7rem;">
                                                    <?= (int)$r['jobs_completed'] ?> TASKS
                                                </span>
                                            </td>
                                        </tr>
                                <?php endforeach;
                                endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>
        </div>
    </div>
</body>

</html>