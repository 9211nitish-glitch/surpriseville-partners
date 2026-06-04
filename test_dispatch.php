<?php
require_once 'e:/project file/btnevents/shop/partners.surpriseville.co.in/backend/dispatch_order.php';

// Simulate a manual mode order dispatch
$order_id = 1530; // Use a known order ID or a dummy one if DB allows

echo "Testing Manual Mode Dispatch for Order #$order_id...\n";

// Set mode to manual in DB temporarily or just mock the logic?
// I'll just call the function. It should read from the DB.

$result = dispatch_order($order_id);
print_r($result);
