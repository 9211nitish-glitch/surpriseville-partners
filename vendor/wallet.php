<?php
// vendor/wallet.php
session_start();

// Check if vendor is logged in
if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';

$vendor_id = $_SESSION['vendor_id'];
$vendor_name = $_SESSION['vendor_name'] ?? 'Vendor';
$message = $_GET['msg'] ?? '';
$error = '';
if (isset($_GET['status']) && $_GET['status'] === 'error') {
    $error = $_GET['msg'] ?? 'Recharge failed.';
    $message = '';
}

// 1. Get or create wallet
$stmt = $conn->prepare("SELECT * FROM vendor_wallet WHERE vendor_id = ?");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Create wallet if doesn't exist
    $stmt2 = $conn->prepare("INSERT INTO vendor_wallet (vendor_id, balance, total_earned, total_withdrawn) VALUES (?, 0, 0, 0)");
    $stmt2->bind_param("i", $vendor_id);
    $stmt2->execute();
    $stmt2->close();

    $stmt->execute(); // Re-fetch
    $result = $stmt->get_result();
}

$wallet = $result->fetch_assoc();
$stmt->close();

// Handle form submission (Withdrawal)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_withdrawal'])) {
    $amount = floatval($_POST['amount']);
    $account_holder_name = trim($_POST['account_holder_name']);
    $account_number = trim($_POST['account_number']);
    $ifsc_code = trim($_POST['ifsc_code']);
    $bank_name = trim($_POST['bank_name']);

    // Validation
    if ($amount < 500) {
        $error = 'Minimum withdrawal amount is ₹500';
    } elseif ($amount > $wallet['balance']) {
        $error = 'Insufficient balance';
    } elseif (empty($account_holder_name) || empty($account_number) || empty($ifsc_code) || empty($bank_name)) {
        $error = 'All fields are required';
    } else {
        // Insert withdrawal request
        $stmt = $conn->prepare("INSERT INTO withdrawal_requests (vendor_id, amount, account_holder_name, account_number, ifsc_code, bank_name, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
        $stmt->bind_param("idssss", $vendor_id, $amount, $account_holder_name, $account_number, $ifsc_code, $bank_name);

        if ($stmt->execute()) {
            $message = 'Request submitted! Admin will review shortly.';
            // Refresh wallet balance display immediately (Visual only, distinct from DB logic if needed)
            $wallet['balance'] -= $amount;
        } else {
            $error = 'Failed to submit request';
        }
        $stmt->close();
    }
}

// 2. Get recent transactions
$stmt = $conn->prepare("SELECT * FROM wallet_transactions WHERE vendor_id = ? ORDER BY created_at DESC LIMIT 50");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$result = $stmt->get_result();

$transactions = [];
while ($row = $result->fetch_assoc()) {
    $transactions[] = $row;
}
$stmt->close();

// 3. Get pending withdrawals
$stmt = $conn->prepare("SELECT * FROM withdrawal_requests WHERE vendor_id = ? ORDER BY requested_at DESC");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$result = $stmt->get_result();

$withdrawals = [];
while ($row = $result->fetch_assoc()) {
    $withdrawals[] = $row;
}
$stmt->close();
?>
<?php
$page_title = 'My Wallet';
include 'header.php';
?>
<style>
    /* ---------- FINTECH STATS GRID ---------- */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .stat-card {
        padding: 30px;
        border-radius: 20px;
        color: white;
        position: relative;
        overflow: hidden;
        box-shadow: var(--card-shadow);
        transition: transform 0.3s;
    }

    .stat-card:hover {
        transform: translateY(-5px);
    }

    .stat-card .relative {
        position: relative;
        z-index: 10;
    }

    .bg-balance {
        background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
        box-shadow: 0 10px 25px rgba(30, 64, 175, 0.2);
    }

    .bg-earned {
        background: linear-gradient(135deg, #065f46 0%, #10b981 100%);
        box-shadow: 0 10px 25px rgba(6, 95, 70, 0.2);
    }

    .bg-withdrawn {
        background: linear-gradient(135deg, #9a3412 0%, #f97316 100%);
        box-shadow: 0 10px 25px rgba(154, 52, 18, 0.2);
        color: white !important;
    }

    .bg-withdrawn p {
        color: rgba(255, 255, 255, 0.8) !important;
    }

    .bg-withdrawn h3 {
        color: white !important;
    }

    .stat-card h3 {
        margin: 10px 0 0 0;
        font-size: 36px;
        font-weight: 800;
        letter-spacing: -1px;
    }

    .stat-card p {
        margin: 0;
        font-size: 14px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        opacity: 0.9;
    }

    .stat-badge {
        margin-top: 15px;
        display: inline-flex;
        align-items: center;
        padding: 5px 10px;
        background: rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
    }

    /* ---------- GENERAL CARDS ---------- */
    .card {
        background: var(--bg-card);
        backdrop-filter: var(--glass-blur);
        -webkit-backdrop-filter: var(--glass-blur);
        padding: 25px;
        border-radius: 20px;
        border: 1px solid var(--border-color);
        box-shadow: var(--card-shadow);
        margin-bottom: 25px;
    }

    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }

    .card h2,
    .card h4 {
        margin: 0;
        font-size: 20px;
        font-weight: 700;
        color: var(--text-main);
    }

    /* ---------- WITHDRAW FORM ---------- */
    .form-group {
        margin-bottom: 18px;
    }

    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        font-size: 14px;
        color: var(--text-muted);
    }

    .form-control {
        width: 100%;
        padding: 14px;
        border: 1px solid var(--border-color);
        background: rgba(0, 0, 0, 0.02);
        color: var(--text-main);
        border-radius: 12px;
        font-size: 15px;
        box-sizing: border-box;
        transition: all 0.3s;
    }

    :root[data-theme="dark"] .form-control {
        background: rgba(255, 255, 255, 0.02);
    }

    .form-control:focus {
        border-color: var(--primary);
        outline: none;
        box-shadow: 0 0 0 3px rgba(19, 91, 236, 0.1);
    }

    .bank-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
    }

    .btn {
        padding: 16px 20px;
        border: none;
        border-radius: 12px;
        font-weight: 700;
        font-size: 15px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        width: 100%;
        box-sizing: border-box;
        transition: all 0.3s;
    }

    .btn-primary {
        background: var(--primary);
        color: white;
        box-shadow: 0 4px 15px rgba(19, 91, 236, 0.3);
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(19, 91, 236, 0.4);
    }

    .btn-disabled {
        background: var(--border-color);
        color: var(--text-muted);
        cursor: not-allowed;
    }

    /* ALERTS */
    .alert {
        padding: 15px;
        border-radius: 10px;
        font-size: 14px;
        margin-bottom: 20px;
        font-weight: 600;
    }

    .alert-success {
        background: rgba(40, 167, 69, 0.1);
        color: #28a745;
        border: 1px solid rgba(40, 167, 69, 0.2);
    }

    .alert-error {
        background: rgba(220, 53, 69, 0.1);
        color: #dc3545;
        border: 1px solid rgba(220, 53, 69, 0.2);
    }

    /* ---------- LIST ITEMS (Transactions) ---------- */
    .tx-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .tx-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 15px;
        background: rgba(0, 0, 0, 0.02);
        border-radius: 12px;
        border: 1px solid transparent;
        transition: border 0.3s;
    }

    :root[data-theme="dark"] .tx-item {
        background: rgba(255, 255, 255, 0.02);
    }

    .tx-item:hover {
        border-color: var(--border-color);
    }

    .tx-left {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .tx-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        font-weight: bold;
    }

    .icon-credit {
        background: rgba(16, 185, 129, 0.1);
        color: #10b981;
    }

    .icon-debit {
        background: rgba(239, 68, 68, 0.1);
        color: #ef4444;
    }

    .icon-cash {
        background: rgba(245, 158, 11, 0.1);
        color: #f59e0b;
    }

    .icon-neutral {
        background: var(--border-color);
        color: var(--text-muted);
    }

    .tx-info p {
        margin: 0;
    }

    .tx-title {
        font-weight: 700;
        color: var(--text-main);
        font-size: 15px;
        margin-bottom: 4px !important;
    }

    .tx-meta {
        font-size: 13px;
        color: var(--text-muted);
    }

    .tx-amount {
        font-size: 18px;
        font-weight: 800;
    }

    .text-credit {
        color: #10b981;
    }

    .text-debit {
        color: #ef4444;
    }

    .text-cash {
        color: #f59e0b;
    }

    /* ---------- TABLES (Withdrawals) ---------- */
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .table {
        width: 100%;
        border-collapse: collapse;
        min-width: 600px;
    }

    .table th {
        padding: 15px;
        text-align: left;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: var(--text-muted);
        border-bottom: 1px solid var(--border-color);
        font-weight: 700;
    }

    .table td {
        padding: 20px 15px;
        border-bottom: 1px solid rgba(0, 0, 0, 0.03);
        font-size: 14px;
        color: var(--text-main);
        font-weight: 500;
    }

    :root[data-theme="dark"] .table td {
        border-bottom: 1px solid rgba(255, 255, 255, 0.03);
    }

    .badge {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .badge-completed {
        background: #10b981;
        color: white;
    }

    .badge-pending {
        border: 1px solid #f59e0b;
        color: #f59e0b;
    }

    .badge-rejected {
        background: var(--border-color);
        color: var(--text-muted);
    }

    /* MOBILE RESPONSIVE */
    .overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(4px);
        z-index: 998;
        opacity: 0;
        transition: opacity 0.3s;
    }

    @media (max-width: 900px) {
        .container {
            padding: 15px;
        }

        .menu-btn {
            display: block;
        }

        .sidebar-wrapper {
            position: fixed;
            top: 0;
            left: 0;
            height: 100%;
            width: 280px;
            background: var(--bg-sidebar);
            z-index: 999;
            transform: translateX(-100%);
            box-shadow: 2px 0 20px rgba(0, 0, 0, 0.2);
            overflow-y: auto;
            padding: 20px 0;
        }

        .sidebar-wrapper.active {
            transform: translateX(0);
        }

        .overlay.active {
            display: block;
            opacity: 1;
        }

        .dashboard-layout {
            flex-direction: column;
            margin-top: 10px;
        }

        .main-content {
            width: 100%;
        }

        .bank-grid {
            grid-template-columns: 1fr;
            gap: 0;
        }

        .stats-grid {
            grid-template-columns: 1fr;
            gap: 15px;
        }

        .main-wallet-grid {
            grid-template-columns: 1fr !important;
            gap: 15px !important;
        }

        .card { padding: 15px !important; }

        .tx-item {
            flex-direction: column;
            align-items: flex-start;
            gap: 12px;
        }

        .tx-amount {
            width: 100%;
            text-align: right;
            border-top: 1px dashed var(--border-color);
            padding-top: 8px;
        }

        .table-responsive {
            border: none;
        }

        .table {
            min-width: 100%;
        }

        .table thead { display: none; } /* Hide table headers on mobile */
        .table tr {
            display: block;
            margin-bottom: 15px;
            background: rgba(0,0,0,0.02);
            border-radius: 12px;
            padding: 10px;
        }

        :root[data-theme="dark"] .table tr {
            background: rgba(255,255,255,0.02);
        }

        .table td {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 5px !important;
            border: none !important;
        }

        .table td::before {
            content: attr(data-label);
            font-weight: 700;
            font-size: 12px;
            color: var(--text-muted);
            text-transform: uppercase;
        }
    }
</style>
</style>

<div class="stats-grid">
    <div class="stat-card bg-balance">
        <div class="relative">
            <p>Available Balance</p>
            <h3>₹<?php echo number_format($wallet['balance'], 2); ?></h3>
            <div class="stat-badge">Ready to withdraw</div>
        </div>
    </div>
    <div class="stat-card bg-earned">
        <div class="relative">
            <p>Lifetime Earnings</p>
            <h3>₹<?php echo number_format($wallet['total_earned'], 2); ?></h3>
            <div class="stat-badge text-xs opacity-90">All-time</div>
        </div>
    </div>
    <div class="stat-card bg-withdrawn">
        <div class="relative">
            <p>Total Withdrawn</p>
            <h3>₹<?php echo number_format($wallet['total_withdrawn'], 2); ?></h3>
        </div>
    </div>
</div>

<div class="main-wallet-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:30px;">

    <!-- LEFT COL: WITHDRAW FORM & HISTORY -->
    <div style="display:flex; flex-direction:column; gap:30px;">

        <!-- WITHDRAW FORM -->
        <div class="card">
            <h4 class="card-header" style="margin-bottom:15px; font-size:18px;">Withdraw Funds</h4>
            <p style="color: var(--text-muted); margin-top:0; margin-bottom: 20px; font-size:13px;">Minimum withdrawal amount: ₹500</p>

            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo $message; ?></div>
                <a href="wallet.php" style="text-decoration:underline; font-size:14px; margin-bottom:15px; display:inline-block;">Refresh Page</a>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>

            <?php if (!$message): ?>
                <?php if ($wallet['balance'] >= 500): ?>
                    <form method="POST">
                        <div class="form-group">
                            <label>Amount (₹)</label>
                            <input type="number" name="amount" class="form-control" min="500" step="0.01" max="<?php echo $wallet['balance']; ?>" placeholder="0.00" required>
                        </div>
                        <div class="form-group">
                            <label>Account Holder Name</label>
                            <input type="text" name="account_holder_name" class="form-control" placeholder="Full Legal Name" required>
                        </div>
                        <div class="bank-grid">
                            <div class="form-group">
                                <label>Bank Name</label>
                                <input type="text" name="bank_name" class="form-control" placeholder="E.g. HDFC" required>
                            </div>
                            <div class="form-group">
                                <label>IFSC Code</label>
                                <input type="text" name="ifsc_code" class="form-control" placeholder="IFSC Code" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Account Number</label>
                            <input type="text" name="account_number" class="form-control" placeholder="Account Number" required>
                        </div>
                        <button type="submit" name="submit_withdrawal" class="btn btn-primary" style="margin-top:10px;">Submit Payout Request</button>
                        <p style="text-align:center; font-size:12px; color:var(--text-muted); margin-top:15px;">Processing time: 1-3 business days.</p>
                    </form>
                <?php else: ?>
                    <button class="btn btn-disabled" disabled>Insufficient Balance (Min ₹500)</button>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- WITHDRAWAL HISTORY -->
        <?php if (!empty($withdrawals)): ?>
            <div class="card">
                <div class="card-header">
                    <h4 style="margin:0;">Withdrawal Status</h4>
                    <a href="ajax/export_csv.php?type=withdrawals" style="font-size:12px; color:var(--primary); text-decoration:none;"><i class="fa-solid fa-file-export"></i> Export</a>
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($withdrawals as $w): ?>
                                <tr>
                                    <td data-label="Date">
                                        <?= date('M d, Y', strtotime($w['requested_at'])) ?><br>
                                        <span style="font-size:11px; color:var(--text-muted);">#WDR-<?= $w['id'] ?></span>
                                    </td>
                                    <td data-label="Amount" style="font-weight:bold;">₹<?= number_format($w['amount'], 2) ?></td>
                                    <td data-label="Status"><span class="badge badge-<?= $w['status'] ?>"><?= ucfirst($w['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <!-- RIGHT COL: TRANSACTIONS LIST -->
    <div class="card" style="margin-bottom:0;">
        <div class="card-header">
            <h4 style="margin:0;">Recent Transactions</h4>
            <a href="ajax/export_csv.php?type=transactions" style="font-size:12px; color:var(--primary); text-decoration:none;"><i class="fa-solid fa-file-export"></i> Export</a>
        </div>
        <?php if (empty($transactions)): ?>
            <p style="color:var(--text-muted); padding:10px; text-align:center;">No transactions yet.</p>
        <?php else: ?>
            <div class="tx-list">
                <?php foreach ($transactions as $t):
                    $isCredit = ($t['type'] == 'credit');
                    $isCash = ($t['type'] == 'cash');
                    $isDebit = ($t['type'] == 'debit');
                    
                    // Cash collected in hand represents a reduction/settlement from the wallet balance, so it visually acts as a debit (negative)
                    $isNegative = $isDebit || $isCash;
                    
                    $iconClass = $isCredit ? 'icon-credit' : ($isCash ? 'icon-debit' : ($isDebit ? 'icon-debit' : 'icon-neutral'));
                    $textClass = $isCredit ? 'text-credit' : ($isNegative ? 'text-debit' : 'text-neutral');
                    $sign = $isNegative ? '-' : '+';
                    $iconChar = $isNegative ? '↓' : '↑';
                ?>
                    <div class="tx-item">
                        <div class="tx-left">
                            <div class="tx-icon <?= $iconClass ?>"><?= $iconChar ?></div>
                            <div class="tx-info">
                                <p class="tx-title"><?= htmlspecialchars($t['description']) ?></p>
                                <p class="tx-meta"><?= date('M d, Y', strtotime($t['created_at'])) ?> • <?= ucfirst($t['type']) ?></p>
                            </div>
                        </div>
                        <div class="tx-amount <?= $textClass ?>">
                            <?= $sign ?>₹<?= number_format($t['amount'], 2) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

</main>
<?php include 'footer.php'; ?>
<?php
if (isset($conn)) $conn->close();
?>