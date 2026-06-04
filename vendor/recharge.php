<?php
// vendor/recharge.php
session_start();
if (!isset($_SESSION['vendor_id'])) {
    header('Location: login.php');
    exit;
}
require_once '../db.php';
require_once 'header.php';

$vendor_id = $_SESSION['vendor_id'];
$vendor_name = $_SESSION['vendor_name'] ?? 'Vendor';

// Get current balance
$bal = 0;
$q = $conn->query("SELECT balance FROM vendor_wallet WHERE vendor_id = $vendor_id");
if ($r = $q->fetch_assoc()) $bal = floatval($r['balance']);
?>

<div class="container">
    <div class="card" style="max-width: 500px; margin: 40px auto; padding: 40px; border-radius: 32px;">
        <div style="text-align: center; margin-bottom: 30px;">
            <div style="width: 64px; height: 64px; background: rgba(19, 91, 236, 0.1); color: var(--primary); border-radius: 20px; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
                <span class="material-symbols-outlined" style="font-size: 32px;">account_balance_wallet</span>
            </div>
            <h2 style="font-weight: 800; margin-bottom: 8px;">Recharge Wallet</h2>
            <p style="color: var(--text-muted); font-size: 14px;">Add funds to your wallet to continue accepting jobs.</p>
        </div>

        <div style="background: var(--bg-body); border-radius: 20px; padding: 20px; margin-bottom: 30px; border: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
            <span style="font-weight: 600; color: var(--text-muted);">Current Balance</span>
            <span style="font-weight: 800; font-size: 20px; color: <?= $bal < 0 ? '#ef4444' : 'var(--text-main)' ?>;">
                ₹<?= number_format($bal, 2) ?>
            </span>
        </div>
        <form id="rechargeForm" action="https://surpriseville.co.in/vendor-recharge.php" method="GET">
            <div style="margin-bottom: 24px;">
                <label style="display: block; font-weight: 700; margin-bottom: 10px; font-size: 14px; color: var(--text-main);">Recharge Amount (₹)</label>
                <input type="number" name="amount" id="rechargeAmount" class="form-control" style="width: 100%; padding: 16px; border-radius: 12px; border: 1px solid var(--border-color); font-size: 18px; font-weight: 700;" placeholder="Enter amount (e.g. 500)" required min="10">
            </div>

            <input type="hidden" name="vendor_id" value="<?= $vendor_id ?>">
            <?php 
                // We'll calculate the token via JS or PHP? 
                // Better PHP here to keep secret hidden from client JS if possible, 
                // but since it's a redirect, we can just pre-calculate it.
            ?>
            <input type="hidden" name="token" id="rechargeToken" value="">

            <button type="submit" id="payBtn" class="btn" style="width: 100%; padding: 16px; background: var(--primary); color: white; border: none; border-radius: 16px; font-weight: 700; font-size: 16px; box-shadow: 0 10px 20px rgba(19, 91, 236, 0.2); cursor: pointer;">
                Proceed to Pay
            </button>
        </form>

        <div style="margin-top: 24px; text-align: center;">
            <p style="font-size: 12px; color: var(--text-muted);">
                <span class="material-symbols-outlined" style="font-size: 14px; vertical-align: middle;">lock</span>
                Secure payment via Surprise Ville Gateway
            </p>
        </div>
    </div>
</div>

<script>
    document.getElementById('rechargeForm').addEventListener('submit', async function(e) {
        // Prevent default to fetch token first if we wanted to be super dynamic, 
        // but for simplicity, we'll use a small AJAX call to get the token or just calculate it if we have the secret.
        // PHP approach is safer. Let's do a quick fetch to get a signed URL/token.
        e.preventDefault();
        
        const amt = document.getElementById('rechargeAmount').value;
        const btn = document.getElementById('payBtn');
        btn.disabled = true;
        btn.innerText = "Redirecting...";

        try {
            const res = await fetch('ajax/generate_recharge_token.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ amount: amt })
            });
            const data = await res.json();
            
            if(data.success) {
                window.location.href = `https://surpriseville.co.in/vendor-recharge.php?vendor_id=<?= $vendor_id ?>&amount=${amt}&token=${data.token}`;
            } else {
                alert("Security Error: " + data.message);
                btn.disabled = false;
                btn.innerText = "Proceed to Pay";
            }
        } catch(err) {
            alert("Connection Error. Please try again.");
            btn.disabled = false;
            btn.innerText = "Proceed to Pay";
        }
    });
</script>

<?php include 'footer.php'; ?>
; ?>