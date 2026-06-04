<?php
session_start();

// Check if vendor is logged in
if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';

$vendor_id = $_SESSION['vendor_id'];
$message = '';
$error = '';

// Get wallet balance
$stmt = $conn->prepare("SELECT balance FROM vendor_wallet WHERE vendor_id = ?");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$result = $stmt->get_result();
$wallet = $result->fetch_assoc();
$stmt->close();

if (!$wallet) {
    $error = 'Wallet not found';
} else {
    $balance = $wallet['balance'];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_withdrawal'])) {
    $amount = floatval($_POST['amount']);
    $account_holder_name = trim($_POST['account_holder_name']);
    $account_number = trim($_POST['account_number']);
    $ifsc_code = trim($_POST['ifsc_code']);
    $bank_name = trim($_POST['bank_name']);
    
    // Validation
    if ($amount < 500) {
        $error = 'Minimum withdrawal amount is ₹500';
    } elseif ($amount > $balance) {
        $error = 'Insufficient balance';
    } elseif (empty($account_holder_name) || empty($account_number) || empty($ifsc_code) || empty($bank_name)) {
        $error = 'All fields are required';
    } else {
        // Insert withdrawal request
        $stmt = $conn->prepare("INSERT INTO withdrawal_requests (vendor_id, amount, account_holder_name, account_number, ifsc_code, bank_name, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
        $stmt->bind_param("idssss", $vendor_id, $amount, $account_holder_name, $account_number, $ifsc_code, $bank_name);
        
        if ($stmt->execute()) {
            $message = 'Withdrawal request submitted successfully! Admin will review it soon.';
        } else {
            $error = 'Failed to submit withdrawal request';
        }
        $stmt->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Withdraw Funds</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <div class="header">
        <h1>Vendor Portal</h1>
        <nav>
            <span style="margin-right: 1rem;">Welcome, <?php echo htmlspecialchars($_SESSION['vendor_name']); ?></span>
            <a href="logout.php">Logout</a>
        </nav>
    </div>

    <div class="container">
        <div class="dashboard-layout">
            <aside class="sidebar">
                <ul>
                    <li><a href="dashboard.php">Dashboard</a></li>
                    <li><a href="pending-alerts.php">Pending Alerts</a></li>
                    <li><a href="my-jobs.php">My Jobs</a></li>
                    <li><a href="wallet.php">My Wallet</a></li>
                    <li><a href="profile.php">Profile</a></li>
                </ul>
            </aside>

            <main class="main-content">
                <div class="card" style="max-width: 600px; margin: 0 auto;">
                    <h2>Request Withdrawal</h2>
                    
                    <div style="background: #f0f0f0; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
                        <p style="margin: 0; font-size: 1.1rem;"><strong>Available Balance:</strong> ₹<?php echo number_format($balance ?? 0, 2); ?></p>
                    </div>
                    
                    <?php if ($message): ?>
                        <div class="alert alert-success"><?php echo $message; ?></div>
                        <a href="wallet.php" class="btn btn-primary">Back to Wallet</a>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-error"><?php echo $error; ?></div>
                    <?php endif; ?>
                    
                    <?php if (!$message): ?>
                    <form method="POST">
                        <div class="form-group">
                            <label for="amount">Withdrawal Amount *</label>
                            <input type="number" id="amount" name="amount" min="500" step="0.01" max="<?php echo $balance; ?>" required>
                            <small style="color: #666;">Minimum: ₹500, Maximum: ₹<?php echo number_format($balance ?? 0, 2); ?></small>
                        </div>
                        
                        <div class="form-group">
                            <label for="account_holder_name">Account Holder Name *</label>
                            <input type="text" id="account_holder_name" name="account_holder_name" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="account_number">Account Number *</label>
                            <input type="text" id="account_number" name="account_number" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="ifsc_code">IFSC Code *</label>
                            <input type="text" id="ifsc_code" name="ifsc_code" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="bank_name">Bank Name *</label>
                            <input type="text" id="bank_name" name="bank_name" required>
                        </div>
                        
                        <button type="submit" name="submit_withdrawal" class="btn btn-success">Submit Withdrawal Request</button>
                        <a href="wallet.php" class="btn btn-secondary">Cancel</a>
                    </form>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <script src="../assets/script.js"></script>
</body>
</html>
