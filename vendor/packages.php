<?php
// vendor/packages.php
session_start();

if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';
require_once '../db_main.php';

$vendor_id   = (int)$_SESSION['vendor_id'];
$vendor_name = $_SESSION['vendor_name'] ?? 'Vendor';

$success_msg = "";
$error_msg   = "";

// 1. Wallet balance
$q = $conn->prepare("SELECT balance FROM vendor_wallet WHERE vendor_id = ?");
$q->bind_param("i", $vendor_id);
$q->execute();
$r_bal = $q->get_result();
if ($r_bal->num_rows === 0) {
    $ins_w = $conn->prepare("INSERT INTO vendor_wallet (vendor_id, balance, total_earned, total_withdrawn) VALUES (?, 0, 0, 0)");
    $ins_w->bind_param("i", $vendor_id);
    $ins_w->execute();
    $ins_w->close();
    $bal = 0.00;
} else {
    $bal = floatval($r_bal->fetch_assoc()['balance']);
}
$q->close();

// 2. Vendor info (type, kyc, price range from admin)
$vendor_type    = 'decoration';
$aadhaar_status = 'pending';
$pkg_min        = null;
$pkg_max        = null;

// Ensure columns exist
$conn->query("ALTER TABLE vendors ADD COLUMN IF NOT EXISTS pkg_min_price DECIMAL(10,2) DEFAULT NULL");
$conn->query("ALTER TABLE vendors ADD COLUMN IF NOT EXISTS pkg_max_price DECIMAL(10,2) DEFAULT NULL");

$v_stmt = $conn->prepare("SELECT vendor_type, aadhaar_status, pkg_min_price, pkg_max_price FROM vendors WHERE id = ?");
if ($v_stmt) {
    $v_stmt->bind_param("i", $vendor_id);
    $v_stmt->execute();
    if ($v_res = $v_stmt->get_result()->fetch_assoc()) {
        $vendor_type    = $v_res['vendor_type']    ?? 'decoration';
        $aadhaar_status = $v_res['aadhaar_status'] ?? 'pending';
        $pkg_min        = $v_res['pkg_min_price'];
        $pkg_max        = $v_res['pkg_max_price'];
    }
    $v_stmt->close();
}

// 3. Category helpers
function isActivityCategory($catId, $name, $dbType = null) {
    if ($dbType !== null) return ($dbType === 'addon');
    $activityIds = [7, 8, 9, 10, 15, 20];
    if (in_array(intval($catId), $activityIds)) return true;
    $decorIds    = [1, 4, 5, 6, 16, 17, 18, 19];
    if (in_array(intval($catId), $decorIds)) return false;
    $kw = ['activity','catering','photography','videography','dj','entry','music','sound','mascot','magician','clown','artist','show'];
    foreach ($kw as $k) if (strpos(strtolower($name), $k) !== false) return true;
    return false;
}

$cat_names = []; $cat_types = [];
$cat_res = $mainConn->query("SELECT id, name, type FROM categories");
if ($cat_res) while ($r = $cat_res->fetch_assoc()) { $cat_names[$r['id']] = $r['name']; $cat_types[$r['id']] = $r['type'] ?? 'decoration'; }

$subcat_names = [];
$subcat_res = $mainConn->query("SELECT id, name FROM subcategories");
if ($subcat_res) while ($r = $subcat_res->fetch_assoc()) $subcat_names[$r['id']] = $r['name'];

// Package categories
$pkg_cats = []; $pkg_raw_cats = [];
$pc_res = $conn->query("SELECT * FROM package_categories");
if ($pc_res) {
    while ($row = $pc_res->fetch_assoc()) {
        $pkg_id = $row['package_id']; $cat_id = $row['category_id']; $subcat_id = $row['subcategory_id'];
        $pkg_raw_cats[$pkg_id][] = ['category_id' => $cat_id, 'subcategory_id' => $subcat_id];
        $cat_name = $cat_names[$cat_id] ?? "Category #$cat_id";
        $pkg_cats[$pkg_id][] = $subcat_id ? "$cat_name (" . ($subcat_names[$subcat_id] ?? "#$subcat_id") . ")" : $cat_name;
    }
}

// Already purchased packages (one-time lock)
$purchased_pkg_ids = [];
$pp_res = $conn->prepare("SELECT DISTINCT package_id FROM package_purchases WHERE vendor_id = ?");
if ($pp_res) {
    $pp_res->bind_param("i", $vendor_id);
    $pp_res->execute();
    $pp_result = $pp_res->get_result();
    while ($row = $pp_result->fetch_assoc()) $purchased_pkg_ids[] = intval($row['package_id']);
    $pp_res->close();
}

// 4. Handle purchase
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buy_package'])) {
    $package_id = (int)$_POST['package_id'];

    // Block re-purchase
    if (in_array($package_id, $purchased_pkg_ids)) {
        $error_msg = "You have already purchased this package. Each package can only be purchased once.";
    } else {
        $p_stmt = $conn->prepare("SELECT * FROM packages WHERE id = ? AND status = 'active' LIMIT 1");
        $p_stmt->bind_param("i", $package_id);
        $p_stmt->execute();
        $pkg = $p_stmt->get_result()->fetch_assoc();
        $p_stmt->close();

        if (strtolower($aadhaar_status) !== 'approved') {
            $error_msg = "Your KYC verification is pending. Please complete KYC on the Profile page first.";
        } elseif (!$pkg) {
            $error_msg = "Invalid or inactive package selected.";
        } else {
            $price = floatval($pkg['price']); $name = $pkg['name'];
            $credits = (int)$pkg['task_credits']; $validity = (int)$pkg['validity_days'];
            $conn->begin_transaction();
            try {
                $w_stmt = $conn->prepare("SELECT balance FROM vendor_wallet WHERE vendor_id = ? FOR UPDATE");
                $w_stmt->bind_param("i", $vendor_id); $w_stmt->execute();
                $current_balance = floatval($w_stmt->get_result()->fetch_assoc()['balance'] ?? 0);
                $w_stmt->close();
                if ($current_balance < $price) throw new Exception("Insufficient balance. Please recharge your wallet.");

                $up_w = $conn->prepare("UPDATE vendor_wallet SET balance = balance - ? WHERE vendor_id = ?");
                $up_w->bind_param("di", $price, $vendor_id); $up_w->execute(); $up_w->close();

                $desc = "Purchase of Package: " . $name;
                $ins_tx = $conn->prepare("INSERT INTO wallet_transactions (vendor_id, amount, type, description, status) VALUES (?, ?, 'debit', ?, 'completed')");
                $ins_tx->bind_param("ids", $vendor_id, $price, $desc); $ins_tx->execute(); $ins_tx->close();

                $sub_chk = $conn->prepare("SELECT id FROM vendor_subscriptions WHERE vendor_id = ? AND status = 'active' LIMIT 1");
                $sub_chk->bind_param("i", $vendor_id); $sub_chk->execute();
                if ($sub_chk_row = $sub_chk->get_result()->fetch_assoc()) {
                    $up_sub = $conn->prepare("UPDATE vendor_subscriptions SET package_id=?,credits_total=?,credits_remaining=?,starts_at=NOW(),expires_at='2099-12-31 23:59:59',status='active' WHERE id=?");
                    $up_sub->bind_param("iiii", $package_id, $credits, $credits, $sub_chk_row['id']); $up_sub->execute(); $up_sub->close();
                } else {
                    $ins_sub = $conn->prepare("INSERT INTO vendor_subscriptions (vendor_id,package_id,credits_total,credits_remaining,starts_at,expires_at,status) VALUES (?,?,?,?,NOW(),'2099-12-31 23:59:59','active')");
                    $ins_sub->bind_param("iiii", $vendor_id, $package_id, $credits, $credits); $ins_sub->execute(); $ins_sub->close();
                }
                $sub_chk->close();

                $ins_pur = $conn->prepare("INSERT INTO package_purchases (vendor_id, package_id, amount_paid, payment_method) VALUES (?, ?, ?, 'wallet')");
                $ins_pur->bind_param("iid", $vendor_id, $package_id, $price); $ins_pur->execute(); $ins_pur->close();

                $conn->commit();
                $success_msg = "🎉 Successfully purchased: " . htmlspecialchars($name);
                $bal = $current_balance - $price;
                $purchased_pkg_ids[] = $package_id; // Update local list
            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = $e->getMessage();
            }
        }
    }
}

// 5. Active subscription
$sub_stmt = $conn->prepare("SELECT vs.*, p.name as package_name, p.description as package_desc FROM vendor_subscriptions vs JOIN packages p ON vs.package_id = p.id WHERE vs.vendor_id = ? AND vs.status = 'active' ORDER BY vs.id DESC LIMIT 1");
$sub_stmt->bind_param("i", $vendor_id);
$sub_stmt->execute();
$active_sub = $sub_stmt->get_result()->fetch_assoc();
$sub_stmt->close();

// 6. Packages list — filter by category AND admin price range
$all_pkgs_res = $conn->query("SELECT * FROM packages WHERE status = 'active' ORDER BY price ASC");
$packages = [];
if ($all_pkgs_res) {
    while ($row = $all_pkgs_res->fetch_assoc()) {
        $pkg_id = $row['id'];
        $price  = floatval($row['price']);

        // Admin price range filter
        if ($pkg_min !== null && $price < floatval($pkg_min)) continue;
        if ($pkg_max !== null && $price > floatval($pkg_max)) continue;

        $raw_cats = $pkg_raw_cats[$pkg_id] ?? [];
        if (empty($raw_cats)) {
            $packages[] = $row;
        } else {
            $match = false;
            foreach ($raw_cats as $item) {
                $c_id = $item['category_id'];
                $is_act = isActivityCategory($c_id, $cat_names[$c_id] ?? '', $cat_types[$c_id] ?? null);
                if (($vendor_type === 'activity' && $is_act) || ($vendor_type === 'decoration' && !$is_act)) { $match = true; break; }
            }
            if ($match) $packages[] = $row;
        }
    }
}

$page_title = "My Packages";
include 'header.php';
?>

<style>
/* ── Package Page ── */
.pkg-page { max-width: 1160px; margin: 0 auto; padding: 28px 20px; }

/* Wallet Bar */
.wallet-bar {
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;
    background: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%);
    border-radius: 22px; padding: 24px 30px; margin-bottom: 32px;
    box-shadow: 0 10px 30px rgba(67,97,238,0.3);
}
.wallet-bar .bal-label { font-size: 12px; font-weight: 700; color: rgba(255,255,255,0.7); text-transform: uppercase; letter-spacing: 1px; }
.wallet-bar .bal-amount { font-size: 36px; font-weight: 900; color: #fff; line-height: 1; margin-top: 4px; }
.wallet-bar .recharge-btn {
    display: inline-flex; align-items: center; gap: 8px;
    background: rgba(255,255,255,0.18); color: #fff;
    border: 1.5px solid rgba(255,255,255,0.35); border-radius: 12px;
    padding: 12px 22px; font-weight: 700; font-size: 14px; text-decoration: none;
    backdrop-filter: blur(8px); transition: all 0.25s;
}
.wallet-bar .recharge-btn:hover { background: rgba(255,255,255,0.28); transform: translateY(-2px); }

/* Active Sub Card */
.active-sub-card {
    background: var(--bg-card); border: 1px solid var(--border-color);
    border-radius: 22px; padding: 28px 30px; margin-bottom: 36px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.04);
}
.active-sub-card h3 { margin: 0 0 22px; font-size: 18px; font-weight: 800; display: flex; align-items: center; gap: 10px; }

/* Credits bar */
.credits-bar-wrap { background: rgba(16,185,129,0.08); border: 1px solid rgba(16,185,129,0.18); border-radius: 16px; padding: 20px; }
.credits-track { background: rgba(0,0,0,0.08); height: 10px; border-radius: 6px; margin-top: 12px; overflow: hidden; }
.credits-fill { background: linear-gradient(90deg, #10b981, #059669); height: 100%; border-radius: 6px; transition: width 0.6s ease; }

/* Section Title */
.section-title { font-size: 20px; font-weight: 800; color: var(--text-main); margin-bottom: 24px; display:flex; align-items:center; gap:10px; }

/* Package Grid */
.pkg-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 26px; }

/* Package Card */
.pkg-card {
    background: var(--bg-card); border: 2px solid var(--border-color);
    border-radius: 24px; padding: 28px; display: flex; flex-direction: column;
    position: relative; overflow: hidden; transition: all 0.35s cubic-bezier(0.165, 0.84, 0.44, 1);
}
.pkg-card::before {
    content: ''; position: absolute; top: -60px; right: -60px;
    width: 160px; height: 160px; border-radius: 50%;
    background: radial-gradient(circle, rgba(67,97,238,0.12) 0%, transparent 70%);
    pointer-events: none;
}
.pkg-card:hover { transform: translateY(-8px); border-color: rgba(67,97,238,0.4); box-shadow: 0 20px 50px rgba(67,97,238,0.14); }

/* Purchased card */
.pkg-card.purchased { border-color: rgba(16,185,129,0.3); background: linear-gradient(135deg, rgba(16,185,129,0.03), var(--bg-card)); }
.pkg-card.purchased::before { background: radial-gradient(circle, rgba(16,185,129,0.12) 0%, transparent 70%); }

/* Card header */
.pkg-card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 18px; padding-bottom: 18px; border-bottom: 1.5px dashed var(--border-color); }
.pkg-name { font-size: 18px; font-weight: 800; color: var(--text-main); margin: 4px 0 2px; }
.pkg-tier { font-size: 10px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.6px; }
.pkg-price { font-size: 26px; font-weight: 900; color: #4361ee; }
.pkg-price-sub { font-size: 10px; color: var(--text-muted); font-weight: 600; text-align: right; }

/* Stats chips */
.pkg-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 20px; }
.pkg-stat-chip { padding: 12px 14px; border-radius: 14px; display: flex; flex-direction: column; gap: 3px; }
.pkg-stat-chip .chip-label { font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
.pkg-stat-chip .chip-val { font-size: 15px; font-weight: 800; display: flex; align-items: center; gap: 5px; }
.chip-green { background: rgba(16,185,129,0.07); border: 1px solid rgba(16,185,129,0.15); }
.chip-green .chip-label, .chip-green .chip-val { color: #10b981; }
.chip-blue  { background: rgba(67,97,238,0.07); border: 1px solid rgba(67,97,238,0.15); }
.chip-blue  .chip-label, .chip-blue  .chip-val { color: #4361ee; }

/* Description */
.pkg-desc { font-size: 13px; color: var(--text-muted); line-height: 1.65; margin-bottom: 20px; flex: 1; }

/* Category tags */
.pkg-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 20px; }
.pkg-tag { font-size: 10px; font-weight: 700; padding: 4px 9px; border-radius: 6px; background: #eef2ff; color: #4361ee; }

/* Buy button */
.btn-buy {
    width: 100%; padding: 15px; border-radius: 14px; border: none; cursor: pointer;
    font-size: 15px; font-weight: 800; display: flex; align-items: center; justify-content: center; gap: 9px;
    transition: all 0.25s; background: linear-gradient(135deg, #4361ee, #3730a3);
    color: #fff; box-shadow: 0 6px 20px rgba(67,97,238,0.3);
}
.btn-buy:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(67,97,238,0.4); }
.btn-buy:active { transform: translateY(0); }

/* Already purchased state */
.btn-purchased {
    width: 100%; padding: 15px; border-radius: 14px; border: none;
    font-size: 14px; font-weight: 800; display: flex; align-items: center; justify-content: center; gap: 9px;
    background: rgba(16,185,129,0.1); color: #10b981; cursor: default;
    border: 1.5px solid rgba(16,185,129,0.3);
}
.purchased-badge {
    position: absolute; top: 18px; right: 18px;
    background: linear-gradient(135deg, #10b981, #059669); color: #fff;
    font-size: 9px; font-weight: 800; padding: 4px 10px; border-radius: 20px;
    text-transform: uppercase; letter-spacing: 0.5px;
}

/* KYC locked */
.btn-kyc-lock {
    width: 100%; padding: 15px; border-radius: 14px; border: none; cursor: pointer;
    font-size: 14px; font-weight: 800; display: flex; align-items: center; justify-content: center; gap: 9px;
    background: #f1f5f9; color: #64748b;
}

/* Alert styles */
.alert-danger { background: rgba(239,68,68,0.08); color: #dc2626; border: 1px solid rgba(239,68,68,0.2); padding: 18px 22px; border-radius: 16px; margin-bottom: 24px; font-weight: 600; }
.alert-success { background: rgba(16,185,129,0.08); color: #059669; border: 1px solid rgba(16,185,129,0.2); padding: 18px 22px; border-radius: 16px; margin-bottom: 24px; font-weight: 600; display:flex; align-items:center; gap:10px; }

/* Range info banner */
.range-banner {
    background: linear-gradient(135deg, rgba(139,92,246,0.08), rgba(67,97,238,0.05));
    border: 1px solid rgba(139,92,246,0.2); border-radius: 14px; padding: 14px 20px;
    margin-bottom: 24px; display: flex; align-items: center; gap: 12px; font-size: 13px; font-weight: 700;
}

/* Active sub not-found */
.empty-sub { text-align: center; padding: 30px 0; }
.empty-sub span { font-size: 48px; display: block; margin-bottom: 12px; }
</style>

<div class="pkg-page">

    <!-- Wallet Bar -->
    <div class="wallet-bar">
        <div>
            <div class="bal-label">Wallet Balance</div>
            <div class="bal-amount">₹<?= number_format($bal, 2) ?></div>
        </div>
        <a href="recharge.php" class="recharge-btn">
            <span class="material-symbols-outlined" style="font-size:20px;">add_circle</span> Recharge Wallet
        </a>
    </div>

    <!-- Alerts -->
    <?php if (strtolower($aadhaar_status) !== 'approved'): ?>
        <div class="alert-danger">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">
                <span class="material-symbols-outlined">gpp_maybe</span>
                <span style="font-size:16px; font-weight:800;">KYC Verification Required</span>
            </div>
            <p style="margin:0 0 10px 34px; font-size:14px; font-weight:500; opacity:0.85;">
                KYC status: <strong><?= ucfirst(htmlspecialchars($aadhaar_status ?: 'Pending')) ?></strong> — complete verification to purchase packages.
            </p>
            <a href="profile.php" style="margin-left:34px; color:#dc2626; font-weight:800; font-size:14px; text-decoration:underline;">Complete KYC Now →</a>
        </div>
    <?php endif; ?>

    <?php if (!empty($success_msg)): ?>
        <div class="alert-success">
            <span class="material-symbols-outlined">check_circle</span>
            <span><?= htmlspecialchars($success_msg) ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_msg)): ?>
        <div class="alert-danger">
            <div style="display:flex; align-items:center; gap:10px;">
                <span class="material-symbols-outlined">error</span>
                <span><?= htmlspecialchars($error_msg) ?></span>
            </div>
            <?php if (strpos($error_msg, 'balance') !== false || strpos($error_msg, 'recharge') !== false): ?>
                <div style="margin-top:8px; margin-left:34px;">
                    <a href="recharge.php" style="color:#dc2626; font-weight:800; font-size:13px; text-decoration:underline;">Go to Recharge →</a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Active Subscription -->
    <div class="active-sub-card">
        <h3>
            <span class="material-symbols-outlined" style="color:#4361ee; font-size:22px;">workspace_premium</span>
            Active Subscription
        </h3>
        <?php if ($active_sub): ?>
            <?php $used = (int)$active_sub['credits_total'] - (int)$active_sub['credits_remaining']; ?>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px,1fr)); gap:20px;">
                <div>
                    <div style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:5px;">Active Package</div>
                    <div style="font-size:20px; font-weight:800; color:#4361ee;"><?= htmlspecialchars($active_sub['package_name']) ?></div>
                    <div style="font-size:12px; color:var(--text-muted); margin-top:4px;"><?= htmlspecialchars($active_sub['package_desc']) ?></div>
                </div>
                <div class="credits-bar-wrap">
                    <div style="display:flex; justify-content:space-between; align-items:flex-end;">
                        <div>
                            <div style="font-size:11px; font-weight:700; color:#10b981; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:3px;">Credits Remaining</div>
                            <div style="font-size:26px; font-weight:900; color:#10b981; line-height:1;">
                                <?= (int)$active_sub['credits_remaining'] ?>
                                <span style="font-size:13px; font-weight:600; color:var(--text-muted);">/ <?= (int)$active_sub['credits_total'] ?></span>
                            </div>
                        </div>
                        <div style="font-size:11px; color:var(--text-muted); font-weight:600;"><?= $used ?> used</div>
                    </div>
                    <?php $pct = $active_sub['credits_total'] > 0 ? ($active_sub['credits_remaining'] / $active_sub['credits_total']) * 100 : 0; ?>
                    <div class="credits-track"><div class="credits-fill" style="width:<?= round($pct) ?>%;"></div></div>
                </div>
            </div>
        <?php else: ?>
            <div class="empty-sub">
                <span>📦</span>
                <p style="font-size:15px; color:var(--text-muted); font-weight:600; margin:0;">No active subscription. Purchase a package below to start receiving orders.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Section Heading -->
    <div class="section-title">
        <span class="material-symbols-outlined" style="color:#4361ee;">grid_view</span>
        Available Packages
        <?php if ($pkg_min !== null || $pkg_max !== null): ?>
            <span style="font-size:12px; font-weight:700; background:#eef2ff; color:#4361ee; padding:4px 12px; border-radius:20px; margin-left:4px;">
                ₹<?= number_format($pkg_min ?? 0, 0) ?> – ₹<?= number_format($pkg_max ?? 99999, 0) ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if ($pkg_min !== null || $pkg_max !== null): ?>
        <div class="range-banner">
            <span class="material-symbols-outlined" style="color:#8b5cf6; font-size:20px;">filter_list</span>
            <span style="color:#1e293b;">Showing packages in your assigned range: <strong>₹<?= number_format($pkg_min ?? 0, 2) ?> – ₹<?= number_format($pkg_max ?? 99999, 2) ?></strong></span>
        </div>
    <?php endif; ?>

    <!-- Packages Grid -->
    <div class="pkg-grid">
        <?php if (empty($packages)): ?>
            <div style="grid-column:1/-1; text-align:center; padding:60px 20px; background:var(--bg-card); border-radius:22px; border:1px solid var(--border-color);">
                <span class="material-symbols-outlined" style="font-size:52px; color:var(--text-muted); display:block; margin-bottom:14px;">inventory_2</span>
                <p style="font-size:16px; color:var(--text-muted); font-weight:600; margin:0;">No packages available in your assigned range.</p>
                <p style="font-size:13px; color:var(--text-muted); margin:8px 0 0;">Contact admin to adjust your package visibility settings.</p>
            </div>
        <?php else: ?>
            <?php foreach ($packages as $pkg):
                $pkg_id    = $pkg['id'];
                $has_cats  = !empty($pkg_cats[$pkg_id]);
                $is_purchased = in_array($pkg_id, $purchased_pkg_ids);
            ?>
                <div class="pkg-card <?= $is_purchased ? 'purchased' : '' ?>">
                    <?php if ($is_purchased): ?>
                        <div class="purchased-badge">✓ Purchased</div>
                    <?php endif; ?>

                    <!-- Header -->
                    <div class="pkg-card-header">
                        <div>
                            <div style="display:flex; align-items:center; gap:8px; margin-bottom:2px;">
                                <span class="material-symbols-outlined" style="color:#4361ee; font-size:22px;">package_2</span>
                                <div class="pkg-name"><?= htmlspecialchars($pkg['name']) ?></div>
                            </div>
                            <div class="pkg-tier">Subscription Tier</div>
                        </div>
                        <div style="text-align:right;">
                            <div class="pkg-price">₹<?= number_format($pkg['price'], 2) ?></div>
                            <div class="pkg-price-sub">One-time</div>
                        </div>
                    </div>

                    <!-- Description -->
                    <p class="pkg-desc"><?= htmlspecialchars($pkg['description']) ?></p>

                    <!-- Stats -->
                    <div class="pkg-stats">
                        <div class="pkg-stat-chip chip-green">
                            <span class="chip-label">Allocated Jobs</span>
                            <span class="chip-val">
                                <span class="material-symbols-outlined" style="font-size:17px;">task_alt</span>
                                <?= (int)$pkg['task_credits'] ?> Credits
                            </span>
                        </div>
                        <div class="pkg-stat-chip chip-blue">
                            <span class="chip-label">Validity</span>
                            <span class="chip-val">
                                <span class="material-symbols-outlined" style="font-size:17px;">schedule</span>
                                <?= (int)$pkg['validity_days'] ?> Days
                            </span>
                        </div>
                    </div>

                    <!-- CTA -->
                    <?php if ($is_purchased): ?>
                        <button class="btn-purchased" disabled>
                            <span class="material-symbols-outlined" style="font-size:20px;">check_circle</span>
                            Already Purchased
                        </button>
                    <?php elseif (strtolower($aadhaar_status) === 'approved'): ?>
                        <form method="POST" onsubmit="return confirm('Purchase <?= htmlspecialchars(addslashes($pkg['name'])) ?> for ₹<?= number_format($pkg['price'], 2) ?> from your wallet?');">
                            <input type="hidden" name="package_id" value="<?= $pkg_id ?>">
                            <button type="submit" name="buy_package" class="btn-buy">
                                <span class="material-symbols-outlined" style="font-size:20px;">shopping_bag</span>
                                Buy for ₹<?= number_format($pkg['price'], 0) ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <button class="btn-kyc-lock" onclick="alert('Please complete your KYC verification on the Profile page first.'); window.location.href='profile.php';">
                            <span class="material-symbols-outlined" style="font-size:20px;">lock</span>
                            Complete KYC to Purchase
                        </button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<?php
include 'footer.php';
if (isset($conn)) $conn->close();
?>
