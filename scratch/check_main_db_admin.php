<?php
try {
    $pdo = new PDO("mysql:host=swift.herosite.pro;dbname=surpriseville_emp;charset=utf8mb4", "surpriseville_emp", "Sv@123@4567");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage() . "\n");
}

$tables = $pdo->query("SHOW TABLES LIKE 'admin_users'")->fetchAll();
if (count($tables) > 0) {
    echo "admin_users table exists in surpriseville_emp database.\n";
    $stmt = $pdo->query("SELECT * FROM admin_users LIMIT 5");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} else {
    echo "admin_users table does NOT exist in surpriseville_emp database!\n";
}
