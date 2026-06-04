<?php
try {
    $pdo = new PDO("mysql:host=swift.herosite.pro;dbname=surpriseville_emp;charset=utf8mb4", "surpriseville_emp", "Sv@123@4567");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage() . "\n");
}

$stmt = $pdo->query("SELECT * FROM call_sessions WHERE caller_type != 'vendor' ORDER BY id DESC LIMIT 20");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Recent Non-Vendor Call Sessions:\n";
foreach ($rows as $row) {
    print_r($row);
}
