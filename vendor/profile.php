<?php
// vendor/profile.php
session_start();
if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';       // vendor DB


$vendor_id = (int)$_SESSION['vendor_id'];
$vendor_name = $_SESSION['vendor_name'] ?? 'Vendor';

/* Fetch vendor basic info */
$stmt = $conn->prepare("SELECT id, name, business_name, email, phone, city, aadhaar_number, aadhaar_front, aadhaar_back, aadhaar_status FROM vendors WHERE id = ?");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();
$stmt->close();


?>
<?php
$page_title = 'Vendor Profile & KYC';
include 'header.php';
?>
<style>
    /* CARDS */
    .card {
        background: var(--bg-card);
        padding: 25px;
        border-radius: 20px;
        margin-bottom: 25px;
        box-shadow: var(--card-shadow);
        border: 1px solid var(--border-color);
        backdrop-filter: var(--glass-blur);
        -webkit-backdrop-filter: var(--glass-blur);
    }

    .card h2 {
        margin-top: 0;
        color: var(--text-main);
        border-bottom: 1px solid var(--border-color);
        padding-bottom: 10px;
        margin-bottom: 20px;
        font-size: 20px;
        font-weight: 700;
    }

    /* FORM ELEMENTS */
    .section {
        margin-bottom: 25px;
        padding: 20px;
        border: 1px solid var(--border-color);
        border-radius: 12px;
        background: transparent;
    }

    .section h3 {
        margin-top: 0;
        font-size: 16px;
        color: var(--text-main);
        border-bottom: 1px dashed var(--border-color);
        padding-bottom: 8px;
        margin-bottom: 15px;
    }

    label.block {
        display: block;
        margin-bottom: 6px;
        font-weight: 600;
        font-size: 13px;
        color: var(--text-muted);
    }

    input[type="text"],
    input[type="email"],
    input[type="file"] {
        width: 100%;
        padding: 12px;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        font-size: 14px;
        box-sizing: border-box;
        margin-bottom: 15px;
        background: rgba(0, 0, 0, 0.02);
        color: var(--text-main);
        transition: all 0.3s;
    }

    :root[data-theme="dark"] input[type="text"],
    :root[data-theme="dark"] input[type="email"],
    :root[data-theme="dark"] input[type="file"] {
        background: rgba(255, 255, 255, 0.02);
    }

    input:focus {
        border-color: var(--primary);
        outline: none;
        box-shadow: 0 0 0 3px rgba(19, 91, 236, 0.1);
    }

    /* BUTTONS */
    .btn {
        padding: 12px 20px;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        font-size: 14px;
        font-weight: 600;
        transition: all 0.2s;
        display: inline-block;
    }

    .btn:hover {
        opacity: 0.9;
        transform: translateY(-1px);
    }

    .btn-primary {
        background: var(--primary);
        color: #fff;
        box-shadow: 0 4px 12px rgba(19, 91, 236, 0.3);
    }

    .btn-secondary {
        background: #6c757d;
        color: #fff;
    }

    .select-buttons {
        margin-bottom: 15px;
        display: flex;
        gap: 10px;
    }

    .select-buttons .btn {
        flex: 1;
        text-align: center;
    }

    /* STATUS BADGES */
    .status-pending {
        color: #ff9800;
        font-weight: 700;
    }

    .status-approved {
        color: #28a745;
        font-weight: 700;
    }

    .status-rejected {
        color: #dc3545;
        font-weight: 700;
    }

    /* CHECKBOX LISTS */
    .checkbox-list {
        border: 1px solid var(--border-color);
        border-radius: 8px;
        max-height: 300px;
        overflow-y: auto;
    }

    .checkbox-list label {
        display: block;
        padding: 10px 15px;
        border-bottom: 1px solid var(--border-color);
        cursor: pointer;
        font-size: 14px;
        color: var(--text-main);
        transition: background 0.2s;
    }

    .checkbox-list label:hover {
        background: rgba(0, 0, 0, 0.02);
    }

    :root[data-theme="dark"] .checkbox-list label:hover {
        background: rgba(255, 255, 255, 0.05);
    }

    .checkbox-list input {
        margin-right: 10px;
        transform: scale(1.2);
    }

    /* ADDONS GRID */
    .grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
        gap: 15px;
        margin-top: 15px;
    }

    .addon-card {
        border: 1px solid var(--border-color);
        padding: 10px;
        border-radius: 8px;
        text-align: center;
        background: transparent;
        transition: all 0.2s;
        color: var(--text-main);
    }

    .addon-card:hover {
        box-shadow: var(--card-shadow);
        transform: translateY(-2px);
    }

    .addon-card img {
        width: 100%;
        height: 100px;
        object-fit: cover;
        border-radius: 6px;
        margin-bottom: 8px;
        background: #f9f9f9;
    }

    .addon-card label {
        font-size: 13px;
        font-weight: 600;
        display: block;
        cursor: pointer;
    }

    .hidden {
        display: none;
    }

    /* MEDIA QUERIES */
    @media (max-width: 900px) {

        /* Addon Grid Mobile */
        .grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }
    }

    @media (max-width: 600px) {
        .card {
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 12px;
        }
        .section {
            padding: 15px;
            margin-bottom: 15px;
        }
    }

    @media (max-width: 480px) {
        .grid {
            grid-template-columns: 1fr;
        }
    }
</style>
<div class="card">
    <h2>Personal Info & KYC</h2>

    <form id="infoForm" action="profile-update.php" method="POST" enctype="multipart/form-data">

        <div class="section">
            <h3>Basic Information</h3>
            <label class="block">Name</label>
            <input type="text" name="name" value="<?= htmlspecialchars($vendor['name']) ?>" required>

            <label class="block">Business Name</label>
            <input type="text" name="business_name" value="<?= htmlspecialchars($vendor['business_name']) ?>">

            <label class="block">Email</label>
            <input type="email" name="email" value="<?= htmlspecialchars($vendor['email']) ?>" required>

            <label class="block">Phone</label>
            <input type="text" name="phone" value="<?= htmlspecialchars($vendor['phone']) ?>" required>

            <label class="block">City</label>
            <input type="text" name="city" value="<?= htmlspecialchars($vendor['city']) ?>">
        </div>

        <div class="section">
            <h3>KYC — Aadhaar Verification</h3>

            <label class="block">Aadhaar Number</label>
            <input type="text" name="aadhaar_number" value="<?= htmlspecialchars($vendor['aadhaar_number']) ?>">

            <label class="block">Aadhaar Front Image</label>
            <?php if (!empty($vendor['aadhaar_front'])): ?>
                <div style="margin-bottom:5px; font-size:12px;">Existing: <a href="<?= $vendor['aadhaar_front'] ?>" target="_blank" style="color:#007bff">View Image</a></div>
            <?php endif; ?>
            <input type="file" name="aadhaar_front">

            <label class="block">Aadhaar Back Image</label>
            <?php if (!empty($vendor['aadhaar_back'])): ?>
                <div style="margin-bottom:5px; font-size:12px;">Existing: <a href="<?= $vendor['aadhaar_back'] ?>" target="_blank" style="color:#007bff">View Image</a></div>
            <?php endif; ?>
            <input type="file" name="aadhaar_back">

            <div style="margin-top:10px; font-size:14px;">
                <strong>Status:</strong>
                <?php
                $s = $vendor['aadhaar_status'];
                if ($s === 'approved') echo '<span class="status-approved">Verified ✅</span>';
                elseif ($s === 'rejected') echo '<span class="status-rejected">Rejected ❌</span>';
                else echo '<span class="status-pending">Pending ⏳</span>';
                ?>
            </div>
        </div>

        <button type="submit" class="btn btn-primary" style="background:#28a745; width:100%; margin-top:20px;">Save Profile ✅</button>
    </form>
</div>

<?php include 'footer.php'; ?>