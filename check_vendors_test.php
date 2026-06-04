<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'db.php';

echo "VENDOR CHECK:\n";
echo str_repeat("-", 80) . "\n";

$sql = "
SELECT 
    v.id,
    v.name,
    v.status,
    v.role,
    v.latitude,
    v.longitude,
    vw.balance,
    (SELECT GROUP_CONCAT(category_id) FROM vendor_categories WHERE vendor_id = v.id) as main_cats,
    (SELECT GROUP_CONCAT(category_id) FROM vendor_gig_skills WHERE vendor_id = v.id) as gig_cats
FROM vendors v
LEFT JOIN vendor_wallet vw ON vw.vendor_id = v.id
WHERE v.name LIKE '%muskan%' OR v.name LIKE '%priyanshu%' OR v.id IN (10008, 10010)
";

$res = $conn->query($sql);

if (!$res) {
    echo "Query Error: " . $conn->error . "\n";
    exit;
}

if ($res->num_rows === 0) {
    echo "No matching vendors found.\n";
} else {
    while ($r = $res->fetch_assoc()) {
        printf(
            "Vendor #%d: %s | Status: %s | Role: %s | Lat/Lng: %s,%s | Wallet: %s | MainCats: %s | GigCats: %s\n",
            $r['id'],
            $r['name'],
            $r['status'],
            $r['role'] ? $r['role'] : 'NULL',
            $r['latitude'] ? $r['latitude'] : 'NULL',
            $r['longitude'] ? $r['longitude'] : 'NULL',
            $r['balance'] !== null ? $r['balance'] : 'NULL (No Wallet)',
            $r['main_cats'] ? $r['main_cats'] : 'None',
            $r['gig_cats'] ? $r['gig_cats'] : 'None'
        );
    }
}
echo str_repeat("-", 80) . "\n";
