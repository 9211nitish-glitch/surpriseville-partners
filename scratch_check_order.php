<?php
require_once 'db.php';
require_once 'db_main.php';

echo "=== ONLINE ORDERS ===\n";
$q = $mainConn->query("SELECT * FROM orders WHERE name LIKE '%Neha%' OR name LIKE '%Test%' LIMIT 5");
if ($q) {
    while ($row = $q->fetch_assoc()) {
        echo "Order ID: " . $row['id'] . "\n";
        echo "Name: " . $row['name'] . "\n";
        echo "Address: " . $row['address_line'] . "\n";
        echo "City: " . $row['city'] . "\n";
        echo "Earning/Price details: \n";
        echo "Note: " . $row['note'] . "\n";
        echo "Sticky Note: " . $row['sticky_note'] . "\n";
        echo "Remarks: " . ($row['remarks'] ?? 'N/A') . "\n";
        echo "Admin Note: " . ($row['admin_notes'] ?? 'N/A') . "\n";
        echo "Main Image: " . ($row['main_image'] ?? 'N/A') . "\n";
        echo "-----------------------\n";
    }
} else {
    echo "Query failed: " . $mainConn->error . "\n";
}

echo "=== OFFLINE ORDERS ===\n";
$q2 = $conn->query("SELECT * FROM manual_tasks WHERE client_name LIKE '%Neha%' OR client_name LIKE '%Test%' LIMIT 5");
if ($q2) {
    while ($row = $q2->fetch_assoc()) {
        echo "Task ID: " . $row['id'] . "\n";
        echo "Client Name: " . $row['client_name'] . "\n";
        echo "Category ID: " . $row['category_id'] . "\n";
        echo "Remarks: " . $row['remarks'] . "\n";
        echo "Inclusions: " . $row['inclusions'] . "\n";
        echo "Admin Media: " . $row['admin_media'] . "\n";
        echo "-----------------------\n";
    }
} else {
    echo "Query failed: " . $conn->error . "\n";
}
