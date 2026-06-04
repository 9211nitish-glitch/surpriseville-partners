<?php
try {
    $pdo = new PDO("mysql:host=swift.herosite.pro;dbname=surpriseville_emp;charset=utf8mb4", "surpriseville_emp", "Sv@123@4567");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage() . "\n");
}

$stmt = $pdo->prepare("SELECT id, order_id, caller_type, caller_id, status, created_at FROM call_sessions WHERE order_id = ? ORDER BY id DESC LIMIT 20");
$stmt->execute([28]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Call Sessions for Order 28:\n";
foreach ($rows as $row) {
    print_r($row);
}
