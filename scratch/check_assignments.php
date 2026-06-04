<?php
try {
    $pdo = new PDO("mysql:host=swift.herosite.pro;dbname=surpriseville_emp;charset=utf8mb4", "surpriseville_emp", "Sv@123@4567");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage() . "\n");
}

$stmt = $pdo->prepare("SELECT * FROM order_vendor_assignments WHERE order_id = ?");
$stmt->execute([28]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Assignments for Order 28:\n";
print_r($rows);

$stmt2 = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
$stmt2->execute([28]);
$order = $stmt2->fetch(PDO::FETCH_ASSOC);
echo "\nOrder 28 Details:\n";
print_r($order);
