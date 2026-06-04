<?php
// vendor/my_skills.php
session_start();
header('Location: dashboard.php');
exit;
require_once '../db.php'; // Vendor DB
require_once '../db_main.php'; // Shop DB

if (!isset($_SESSION['vendor_id'])) {
    header('Location: login.php');
    exit;
}
$vendor_id = $_SESSION['vendor_id'];
$vendor_name = $_SESSION['vendor_name'] ?? 'Vendor';
$msg = "";

// Handle Save
if (isset($_POST['save_skills'])) {
    // 1. Unified Sync Mapping (Gig Category -> Shop Categories)
    function getShopCats($gigCatId) {
        $map = [
            1 => [1, 4, 5, 6, 16, 17, 18, 19], // Decor -> Adults, Couples, Kids, etc.
            2 => [7, 8, 10, 15],              // Activity -> Magician, Other, Videography, DJ
            3 => [7],                         // Tattoo -> Service Category 7 fallback
            4 => [9, 10]                      // Photographer -> Photographer, Videography
        ];
        return $map[$gigCatId] ?? [];
    }

    // Clear old skills to avoid duplicates
    $conn->query("DELETE FROM vendor_gig_skills WHERE vendor_id = $vendor_id");
    $conn->query("DELETE FROM vendor_categories WHERE vendor_id = $vendor_id");
    $conn->query("DELETE FROM vendor_subcategories WHERE vendor_id = $vendor_id");

    // Save Categories
    if (!empty($_POST['categories'])) {
        foreach ($_POST['categories'] as $cat_id) {
            $cid = (int)$cat_id;
            // Save to Gig Skills (Manual)
            $conn->query("INSERT INTO vendor_gig_skills (vendor_id, category_id) VALUES ($vendor_id, $cid)");
            
            // Sync to automated categories (Shop)
            $shopCats = getShopCats($cid);
            foreach ($shopCats as $scid) {
                $conn->query("INSERT IGNORE INTO vendor_categories (vendor_id, category_id) VALUES ($vendor_id, $scid)");
            }
        }
    }

    // Save Subcategories
    if (!empty($_POST['subcategories'])) {
        foreach ($_POST['subcategories'] as $sub_id) {
            $sid = (int)$sub_id;
            $conn->query("INSERT IGNORE INTO vendor_subcategories (vendor_id, subcategory_id, created_at) VALUES ($vendor_id, $sid, NOW())");
        }
    }

    $msg = "Profile updated successfully! You are now eligible for matching shop orders.";
}

// Get All Categories
$cats = $conn->query("SELECT * FROM gig_categories ORDER BY type, name");

// Get My Selected Categories
$my_cats = [];
$res = $conn->query("SELECT category_id FROM vendor_gig_skills WHERE vendor_id = $vendor_id");
while ($r = $res->fetch_assoc()) $my_cats[] = $r['category_id'];

// Get My Selected Subcategories
$my_subcats = [];
$res = $conn->query("SELECT subcategory_id FROM vendor_subcategories WHERE vendor_id = $vendor_id");
if ($res) {
    while ($r = $res->fetch_assoc()) $my_subcats[] = (int)$r['subcategory_id'];
}

// Get All Subcategories from Shop DB (grouped)
$all_subcats = [];
if (isset($mainConn)) {
    $resS = $mainConn->query("SELECT s.id, s.name, c.name as cat_name FROM subcategories s JOIN categories c ON s.category_id = c.id ORDER BY c.name, s.name");
    while ($r = $resS->fetch_assoc()) {
        $all_subcats[$r['cat_name']][] = $r;
    }
}
?>
<?php
$page_title = 'My Skills';
include 'header.php';
?>
<style>
    /* SKILLS CARD */
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
        margin-bottom: 15px;
        font-size: 20px;
        font-weight: 700;
    }

    .skill-group {
        margin-bottom: 20px;
        padding: 0;
    }

    .skill-item {
        display: flex;
        align-items: center;
        padding: 12px;
        border-bottom: 1px solid var(--border-color);
        transition: background 0.2s;
    }

    .skill-item:last-child {
        border-bottom: none;
    }

    .skill-item:hover {
        background: rgba(0, 0, 0, 0.02);
    }

    :root[data-theme="dark"] .skill-item:hover {
        background: rgba(255, 255, 255, 0.05);
    }

    .skill-item strong {
        color: var(--text-main) !important;
    }

    .skill-label {
        cursor: pointer;
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: 14px;
    }

    .skill-label input {
        margin-right: 10px;
        transform: scale(1.2);
    }

    /* Disabled look */
    .skill-item.disabled {
        opacity: 0.5;
        background: #fafafa;
    }

    .skill-item.disabled input {
        cursor: not-allowed;
    }

    /* Badges */
    .badge-type {
        font-size: 10px;
        color: white;
        padding: 3px 8px;
        border-radius: 20px;
        text-transform: uppercase;
        font-weight: 700;
        letter-spacing: 0.5px;
    }

    .bg-decoration {
        background: #d9534f;
    }

    .bg-activity {
        background: #007bff;
    }

    /* Buttons */
    .btn-save {
        width: 100%;
        padding: 12px;
        border: none;
        border-radius: 8px;
        background: #28a745;
        color: white;
        font-weight: 600;
        font-size: 16px;
        cursor: pointer;
        transition: background 0.2s;
    }

    .btn-save:hover {
        background: #218838;
    }

    .subcat-section {
        margin-top: 30px;
        padding-top: 20px;
        border-top: 2px dashed var(--border-color);
    }

    .subcat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 10px;
        margin-top: 10px;
    }

    .subcat-item {
        font-size: 13px;
        background: rgba(0,0,0,0.03);
        padding: 8px;
        border-radius: 6px;
        display: flex;
        align-items: center;
    }

    .subcat-item input {
        margin-right: 8px;
    }

    .subcat-group-title {
        font-size: 14px;
        font-weight: 700;
        margin-top: 15px;
        color: var(--text-main);
        background: #eee;
        padding: 5px 10px;
        border-radius: 4px;
    }
</style>
<?php if ($msg): ?>
    <div style="padding:15px; background:#d4edda; color:#155724; margin-bottom:20px; border-radius:8px; border:1px solid #c3e6cb;">
        <?= $msg ?>
    </div>
<?php endif; ?>

<div class="card">
    <h2>Manage Services</h2>
    <p style="font-size:13px; color:#666; margin-bottom:20px; line-height: 1.5; background: #fff3cd; padding: 10px; border-radius: 6px; border: 1px solid #ffeeba;">
        <strong>Note:</strong> You can select multiple <strong>Activities</strong> (e.g., Magician + Photographer), but <strong>Decoration</strong> cannot be combined with Activities.
    </p>

    <form method="POST">
        <div class="skill-group">
            <?php
            // Reset pointer to start
            $cats->data_seek(0);
            while ($c = $cats->fetch_assoc()):
                $checked = in_array($c['id'], $my_cats) ? 'checked' : '';
                $typeLabel = ucfirst($c['type']);
                $bgClass = ($c['type'] == 'decoration') ? 'bg-decoration' : 'bg-activity';
            ?>
                <div class="skill-item">
                    <label class="skill-label">
                        <div style="display:flex; align-items:center;">
                            <input type="checkbox" class="cat-checkbox"
                                name="categories[]"
                                value="<?= $c['id'] ?>"
                                data-type="<?= $c['type'] ?>"
                                <?= $checked ?>>
                            <strong style="margin-left:8px; color:#333;"><?= htmlspecialchars($c['name']) ?></strong>
                        </div>
                        <span class="badge-type <?= $bgClass ?>"><?= $typeLabel ?></span>
                    </label>
                </div>
            <?php endwhile; ?>
        </div>

        <div class="subcat-section">
            <h2>Specific Event Types (Subcategories)</h2>
            <p style="font-size:12px; color:#666; margin-bottom:15px;">
                Select the specific types of events you specialize in to get better matching automated orders from <strong>surpriseville.co.in</strong>.
            </p>
            
            <?php foreach ($all_subcats as $catName => $subs): ?>
                <div class="subcat-group-title"><?= htmlspecialchars($catName) ?></div>
                <div class="subcat-grid">
                    <?php foreach ($subs as $s): ?>
                        <?php $sChecked = in_array((int)$s['id'], $my_subcats) ? 'checked' : ''; ?>
                        <div class="subcat-item">
                            <label style="display:flex; align-items:center; cursor:pointer; margin:0;">
                                <input type="checkbox" name="subcategories[]" value="<?= $s['id'] ?>" <?= $sChecked ?>>
                                <?= htmlspecialchars($s['name']) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <button type="submit" name="save_skills" class="btn-save" style="margin-top:30px;">Save All Profile Skills</button>
    </form>
</div>
<script>
    // Logic for Decoration vs Activity Exclusion
    document.addEventListener("DOMContentLoaded", function() {
        const checkboxes = document.querySelectorAll('.cat-checkbox');

        function updateState() {
            let hasDecoration = false;
            let hasActivity = false;

            // 1. Check what is currently selected
            checkboxes.forEach(cb => {
                if (cb.checked) {
                    if (cb.dataset.type === 'decoration') hasDecoration = true;
                    if (cb.dataset.type === 'activity') hasActivity = true;
                }
            });

            // 2. Apply disable rules based on selection
            checkboxes.forEach(cb => {
                const type = cb.dataset.type;
                const itemRow = cb.closest('.skill-item');

                if (hasDecoration) {
                    // If Decoration is selected -> Disable all Activities
                    if (type === 'activity') {
                        cb.disabled = true;
                        itemRow.classList.add('disabled');
                    } else {
                        cb.disabled = false;
                        itemRow.classList.remove('disabled');
                    }
                } else if (hasActivity) {
                    // If Activity is selected -> Disable all Decorations
                    if (type === 'decoration') {
                        cb.disabled = true;
                        itemRow.classList.add('disabled');
                    } else {
                        cb.disabled = false;
                        itemRow.classList.remove('disabled');
                    }
                } else {
                    // If nothing selected -> Enable everything
                    cb.disabled = false;
                    itemRow.classList.remove('disabled');
                }
            });
        }

        // Attach event listeners
        checkboxes.forEach(cb => {
            cb.addEventListener('change', updateState);
        });

        // Run once on load
        updateState();
    });
</script>
<?php include 'footer.php'; ?>