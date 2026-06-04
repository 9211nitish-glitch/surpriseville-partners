<?php
// vendor/services.php
session_start();
if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';        // $conn => vendor DB (mysqli)
require_once '../db_main.php';   // $mainConn => main/shop DB (mysqli)

$vendor_id = (int)$_SESSION['vendor_id'];

// Fetch categories and subcategories from main DB
$cats = [];
$res = $mainConn->query("SELECT id, name FROM categories WHERE status='active' ORDER BY name");
while ($r = $res->fetch_assoc()) $cats[] = $r;

$subs = [];
$res = $mainConn->query("SELECT id, name, category_id FROM subcategories ORDER BY name");
while ($r = $res->fetch_assoc()) $subs[] = $r;

// Load vendor's saved selections to show current state
$selectedCats = ['category_id' => null, 'subcategory_id' => null];
$stmt = $conn->prepare("SELECT category_id, subcategory_id FROM vendor_selected_categories WHERE vendor_id = ?");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $selectedCats['category_id'] = $row['category_id'];
    $selectedCats['subcategory_id'] = $row['subcategory_id'];
}
$stmt->close();

$selectedServices = [];
$stmt = $conn->prepare("SELECT service_id FROM vendor_selected_services WHERE vendor_id = ?");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $selectedServices[] = (int)$r['service_id'];
$stmt->close();

$selectedAddons = [];
$stmt = $conn->prepare("SELECT service_id, addon_id FROM vendor_selected_addons WHERE vendor_id = ?");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $selectedAddons[(int)$r['service_id']][] = (int)$r['addon_id'];
}
$stmt->close();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>My Services - Vendor Panel</title>
<link rel="stylesheet" href="../assets/style.css">
<style>
.block{border:1px solid #eee;padding:14px;border-radius:8px;margin-bottom:12px}
.service-row{padding:8px;border-bottom:1px solid #f1f1f1}
.service-row:last-child{border-bottom:none}
.addons-wrap{margin-left:18px;font-size:0.95rem}
.save-btn{background:#28a745;color:#fff;padding:10px 14px;border:none;border-radius:6px;cursor:pointer}
.note{font-size:0.9rem;color:#555}
</style>
</head>
<body>
<div class="wrap" style="max-width:1000px;margin:30px auto">
    <h2>Configure Your Services & Addons</h2>
    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="alert success"><?= htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div>
    <?php endif; ?>
    <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="alert error"><?= htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
    <?php endif; ?>

    <form id="vendor-config-form" method="POST" action="save_vendor_services.php">
        <div class="block">
            <h3>Category (optional)</h3>
            <select name="category_id" id="categorySelect">
                <option value="">-- All Categories (default) --</option>
                <?php foreach($cats as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= ($selectedCats['category_id']==$c['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="note">If you leave category blank, you'll be visible for all categories (admin may prefer manual assignment).</p>
        </div>

        <div class="block">
            <h3>Subcategory (optional)</h3>
            <select name="subcategory_id" id="subcategorySelect">
                <option value="">-- All Subcategories --</option>
                <?php foreach($subs as $s): ?>
                    <option data-cat="<?= $s['category_id'] ?>" value="<?= $s['id'] ?>" <?= ($selectedCats['subcategory_id']==$s['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="block">
            <h3>Services (select one or more)</h3>
            <div id="serviceList">Loading services...</div>
        </div>

        <div style="text-align:right">
            <button type="submit" class="save-btn">Save Changes</button>
        </div>
    </form>
</div>

<script>
const selectedServices = <?= json_encode($selectedServices) ?>;
const selectedAddons = <?= json_encode($selectedAddons) ?>;
const initCategory = <?= json_encode($selectedCats['category_id'] ?? '') ?>;
const initSubcategory = <?= json_encode($selectedCats['subcategory_id'] ?? '') ?>;

document.getElementById('categorySelect').value = initCategory;
document.getElementById('subcategorySelect').value = initSubcategory;

function fetchServices() {
    const cid = document.getElementById('categorySelect').value;
    const sid = document.getElementById('subcategorySelect').value;
    const params = new URLSearchParams({ category_id: cid, subcategory_id: sid });
    fetch('../ajax/ajax_get_services.php?' + params.toString())
        .then(r => r.json())
        .then(data => renderServices(data))
        .catch(err => { console.error(err); document.getElementById('serviceList').innerText = "Failed to load services."; });
}

function renderServices(services) {
    if (!services.length) {
        document.getElementById('serviceList').innerHTML = '<em>No services for this selection.</em>';
        return;
    }
    let html = '';
    services.forEach(s => {
        const checked = selectedServices.includes(s.id) ? 'checked' : '';
        const price = s.base_price ?? s.price ?? 0;
        html += `<div class="service-row" data-service-id="${s.id}">
            <label><input type="checkbox" name="services[]" value="${s.id}" ${checked}> <strong>${escapeHtml(s.name)}</strong> — ₹ ${price}</label>
            <div class="addons-wrap" id="addons-for-${s.id}">Loading addons...</div>
        </div>`;
    });
    document.getElementById('serviceList').innerHTML = html;
    services.forEach(s => fetchAddonsForService(s.id));
}

function fetchAddonsForService(serviceId) {
    fetch('../ajax/ajax_get_service_addons.php?service_id=' + encodeURIComponent(serviceId))
        .then(r => r.json())
        .then(addons => {
            const wrap = document.getElementById('addons-for-' + serviceId);
            if (!wrap) return;
            if (!addons.length) { wrap.innerHTML = '<small>No addons for this service.</small>'; return; }
            let html = '';
            addons.forEach(a => {
                const sel = Array.isArray(selectedAddons[serviceId]) && selectedAddons[serviceId].includes(a.id) ? 'checked' : '';
                const price = a.price ? ' — ₹' + a.price : '';
                html += `<label style="display:block"><input type="checkbox" name="addons[${serviceId}][]" value="${a.id}" ${sel}> ${escapeHtml(a.name)} ${price}</label>`;
            });
            wrap.innerHTML = html;
        })
        .catch(err => {
            console.error(err);
            const wrap = document.getElementById('addons-for-' + serviceId);
            if (wrap) wrap.innerHTML = '<small>Failed to load addons.</small>';
        });
}

function escapeHtml(s){ return String(s).replace(/[&<>"]/g, c=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c])); }

document.getElementById('categorySelect').addEventListener('change', () => {
    const cid = document.getElementById('categorySelect').value;
    Array.from(document.querySelectorAll('#subcategorySelect option')).forEach(opt => {
        if (!opt.dataset.cat) return;
        opt.style.display = (!cid || opt.dataset.cat === cid) ? '' : 'none';
    });
    document.getElementById('subcategorySelect').value = '';
    fetchServices();
});

document.getElementById('subcategorySelect').addEventListener('change', fetchServices);

// initial load
fetchServices();
</script>
</body>
</html>
