<?php
// PDO Connection to partners database
try {
    $pdo_partners = new PDO("mysql:host=swift.herosite.pro;dbname=surpriseville_partners;charset=utf8mb4", "surpriseville_partners", "Sv@123@4567");
    $pdo_partners->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Partners DB Connection failed: " . $e->getMessage() . "\n");
}

// Check Admin
$admin_user = 'admin';
$admin_pass = '123456789';

echo "\n=== ADMIN CHECK ===\n";
$stmt2 = $pdo_partners->prepare("SELECT id, username, password, status FROM admin_users WHERE username = ? LIMIT 1");
$stmt2->execute([$admin_user]);
$admin = $stmt2->fetch(PDO::FETCH_ASSOC);

if ($admin) {
    echo "Found Admin ID: " . $admin['id'] . ", Username: " . $admin['username'] . ", Status: " . $admin['status'] . "\n";
    echo "Password Hash in DB: " . $admin['password'] . "\n";
    if (password_verify($admin_pass, $admin['password'])) {
        echo "Password verification: MATCH (Valid bcrypt)!\n";
    } elseif ($admin['password'] === md5($admin_pass)) {
        echo "Password verification: MATCH (MD5)!\n";
    } elseif ($admin['password'] === $admin_pass) {
        echo "Password verification: MATCH (Plaintext)!\n";
    } else {
        echo "Password verification: MISMATCH!\n";
    }
} else {
    echo "Admin user not found in admin_users!\n";
}
