<?php
try {
    $pdo = new PDO("mysql:host=swift.herosite.pro;dbname=surpriseville_emp;charset=utf8mb4", "surpriseville_emp", "Sv@123@4567");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage() . "\n");
}

$stmt = $pdo->query("DESCRIBE call_sessions");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "call_sessions Schema:\n";
print_r($columns);
