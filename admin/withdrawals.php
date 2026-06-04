<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';

$message = ''; $error = '';

if (isset($_POST['process_withdrawal'])) {
    $id = intval($_POST['withdrawal_id']);
    $action = $_POST['action'];
    $admin_note = trim($_POST['admin_note'] ?? '');
    
    if ($action === 'approve') {
        $stmt = $conn->prepare("SELECT * FROM withdrawal_requests WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $withdrawal = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($withdrawal) {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("UPDATE vendor_wallet SET balance = balance - ?, total_withdrawn = total_withdrawn + ? WHERE vendor_id = ?");
                $stmt->bind_param("ddi", $withdrawal['amount'], $withdrawal['amount'], $withdrawal['vendor_id']);
                $stmt->execute(); $stmt->close();
                
                $desc = "Withdrawal: " . $withdrawal['account_number'];
                $stmt = $conn->prepare("INSERT INTO wallet_transactions (vendor_id, type, amount, description, status) VALUES (?, 'withdrawal', ?, ?, 'completed')");
                $stmt->bind_param("ids", $withdrawal['vendor_id'], $withdrawal['amount'], $desc);
                $stmt->execute(); $stmt->close();
                
                $stmt = $conn->prepare("UPDATE withdrawal_requests SET status = 'approved', admin_note = ?, processed_at = NOW() WHERE id = ?");
                $stmt->bind_param("si", $admin_note, $id);
                $stmt->execute(); $stmt->close();
                
                $conn->commit();
                $message = 'Withdrawal request approved successfully.';
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Error occurred: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'reject') {
        $stmt = $conn->prepare("UPDATE withdrawal_requests SET status = 'rejected', admin_note = ?, processed_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $admin_note, $id);
        if ($stmt->execute()) { $message = 'Withdrawal request rejected and logged.'; }
        else { $error = 'Update failed.'; }
        $stmt->close();
    }
}

$withdrawals = [];
$result = $conn->query("SELECT wr.*, v.name as vendor_name, v.business_name, v.email FROM withdrawal_requests wr INNER JOIN vendors v ON wr.vendor_id = v.id ORDER BY wr.requested_at DESC");
while ($row = $result->fetch_assoc()) { $withdrawals[] = $row; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Withdrawals | Surprise Ville</title>
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

        .status-badge {
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge-pending { background: #fff7ed; color: #9a3412; }
        .badge-approved { background: #ecfdf5; color: #065f46; }
        .badge-rejected { background: #fef2f2; color: #991b1b; }

        .bank-box {
            background: white;
            padding: 1rem;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            font-size: 0.85rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }

        .btn-governance {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 1.1rem;
        }
        .btn-approve { background: var(--success); color: white; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25); }
        .btn-reject { background: var(--danger); color: white; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25); }
        .btn-governance:hover { transform: translateY(-3px) scale(1.05); }

        .modal-overlay {
            display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(12px); z-index: 9999; align-items: center; justify-content: center;
            padding: 1.5rem;
        }
        .modal-box {
            background: white; width: 100%; max-width: 450px; border-radius: 32px; padding: 2.5rem; 
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);
            animation: modalIn 0.3s ease-out;
        }
        @keyframes modalIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }

        .form-control {
            width: 100%; padding: 1.25rem; border-radius: 16px; border: 1px solid #e2e8f0; 
            font-family: inherit; margin-bottom: 1.5rem; outline: none; transition: border-color 0.2s;
        }
        .form-control:focus { border-color: var(--primary); }

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
                <i class="fa-solid fa-vault"></i>
            </div>
            <h1 style="margin: 0; font-size: 1.4rem; font-weight: 800; letter-spacing: -0.5px;">Withdrawals</h1>
        </div>
        <nav style="display: flex; align-items: center; gap: 20px;">
            <span style="font-weight: 700; color: #64748b; font-size: 0.85rem;"><i class="fa-solid fa-shield-halved"></i> Connected to Bank</span>
        </nav>
    </header>

    <div class="dashboard-container">
        <?php include 'sidebar_fragment.php'; ?>
        <main class="main-content">

            <div style="margin-bottom: 2.5rem;">
                <h2 style="margin: 0; font-weight: 800; font-size: 1.8rem; letter-spacing: -0.5px;">Manage Withdrawals</h2>
                <p style="color: #64748b; font-weight: 600; margin-top: 5px;">Approve or reject vendor withdrawal requests here</p>
            </div>

            <?php if ($message): ?>
                <div style="background: #ecfdf5; color: #065f46; padding: 1.25rem; border-radius: 20px; border: 1px solid #6ee7b7; margin-bottom: 2rem; font-weight: 700; display: flex; align-items: center; gap: 12px;">
                    <i class="fa-solid fa-circle-check" style="font-size: 1.2rem;"></i> <?= $message ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div style="background: #fef2f2; color: #991b1b; padding: 1.25rem; border-radius: 20px; border: 1px solid #fca5a5; margin-bottom: 2rem; font-weight: 700; display: flex; align-items: center; gap: 12px;">
                    <i class="fa-solid fa-triangle-exclamation" style="font-size: 1.2rem;"></i> <?= $error ?>
                </div>
            <?php endif; ?>

            <div class="premium-card">
                <div class="table-responsive">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Vendor</th>
                                <th>Amount</th>
                                <th>Bank Details</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($withdrawals)): ?>
                                <tr><td colspan="6" style="text-align: center; padding: 80px; color: #94a3b8;"><i class="fa-solid fa-vault fa-3x" style="margin-bottom:20px;"></i><br>No Pending Settlement Requests</td></tr>
                            <?php else: foreach ($withdrawals as $w): ?>
                                <tr>
                                    <td style="font-weight: 800; color: #94a3b8; font-size: 0.9rem;">#<?= $w['id'] ?></td>
                                    <td>
                                        <div style="font-weight: 800; color: var(--primary); font-size: 1rem;"><?= htmlspecialchars($w['vendor_name']) ?></div>
                                        <div style="font-size: 0.75rem; color: #64748b; font-weight: 700; margin-top: 2px;"><?= htmlspecialchars($w['business_name']) ?></div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 800; font-size: 1.2rem; color: #0f172a;">₹<?= number_format($w['amount'], 2) ?></div>
                                        <div style="font-size: 0.65rem; color: #94a3b8; font-weight: 800; margin-top: 4px;"><?= date('H:i | d M Y', strtotime($w['requested_at'])) ?></div>
                                    </td>
                                    <td>
                                        <div class="bank-box">
                                            <div style="font-weight: 800; color: var(--primary); margin-bottom: 6px; font-size: 0.85rem; display: flex; align-items: center; gap: 6px;">
                                                <i class="fa-solid fa-building-columns"></i> <?= htmlspecialchars($w['bank_name']) ?>
                                            </div>
                                            <div style="font-weight: 700; color: #1e293b; font-size: 0.8rem;">AC: <?= htmlspecialchars($w['account_number']) ?></div>
                                            <div style="font-size: 0.75rem; color: #64748b; font-weight: 600; margin-top: 2px;">IFSC: <?= htmlspecialchars($w['ifsc_code']) ?></div>
                                        </div>
                                    </td>
                                    <td><span class="status-badge badge-<?= $w['status'] ?>"><?= $w['status'] ?></span></td>
                                    <td>
                                        <?php if ($w['status'] === 'pending'): ?>
                                            <div style="display: flex; gap: 12px;">
                                                <button onclick="processPayout(<?= $w['id'] ?>, 'approve')" class="btn-governance btn-approve" title="Approve Withdrawal"><i class="fa-solid fa-check"></i></button>
                                                <button onclick="processPayout(<?= $w['id'] ?>, 'reject')" class="btn-governance btn-reject" title="Reject Request"><i class="fa-solid fa-xmark"></i></button>
                                            </div>
                                        <?php else: ?>
                                            <div style="font-size: 0.75rem; color: #94a3b8; font-weight: 800; text-transform: uppercase; letter-spacing: 1px;">Processed</div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <div id="payoutModal" class="modal-overlay">
        <div class="modal-box">
            <h3 id="modalTitle" style="margin-top:0; font-weight: 800; font-size: 1.5rem; letter-spacing: -0.5px;">Approve Withdrawal</h3>
            <p style="color: #64748b; font-weight: 600; margin-bottom: 2rem; font-size: 0.95rem;">Please check bank details before approving.</p>
            <form method="POST">
                <input type="hidden" name="withdrawal_id" id="withdrawal_id">
                <input type="hidden" name="action" id="action">
                <textarea name="admin_note" rows="3" class="form-control" placeholder="Optional notes..."></textarea>
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem;">
                    <button type="submit" name="process_withdrawal" id="confirmBtn" style="padding: 1.1rem; border-radius: 16px; border: none; color: #fff; font-weight: 800; cursor: pointer; transition: transform 0.2s;">Approve Now</button>
                    <button type="button" onclick="document.getElementById('payoutModal').style.display='none'" style="padding: 1.1rem; background: #f1f5f9; color: #475569; border: none; border-radius: 16px; font-weight: 800; cursor: pointer;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function processPayout(id, action) {
            document.getElementById('withdrawal_id').value = id;
            document.getElementById('action').value = action;
            const modal = document.getElementById('payoutModal');
            const confirmBtn = document.getElementById('confirmBtn');
            const title = document.getElementById('modalTitle');
            
            if (action === 'approve') {
                title.textContent = 'Approve Request';
                confirmBtn.style.background = 'var(--success)';
                confirmBtn.style.boxShadow = '0 10px 15px -3px rgba(16, 185, 129, 0.3)';
                confirmBtn.textContent = 'Confirm Approval';
            } else {
                title.textContent = 'Reject Request';
                confirmBtn.style.background = 'var(--danger)';
                confirmBtn.style.boxShadow = '0 10px 15px -3px rgba(239, 68, 68, 0.3)';
                confirmBtn.textContent = 'Reject Now';
            }
            modal.style.display = 'flex';
        }

        // Close modal on escape
        document.addEventListener('keydown', (e) => {
            if(e.key === 'Escape') document.getElementById('payoutModal').style.display = 'none';
        });
    </script>
</body>
</html>
