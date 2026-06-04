<?php
/**
 * Admin Notification Helper
 * Handles alerts for Manual mode orders and "No vendor found" scenarios.
 */

/**
 * Sends an alert to Admin via WhatsApp
 * 
 * @param int $order_id
 * @param string $reason ('manual_allocation_required' or 'no_matching_vendors')
 * @param PDO $db The Shop DB connection
 */
function sendAdminAlert($order_id, $reason, $db = null) {
    // Admin Number
    $admin_phone = "8745818818"; 
    
    // API Details
    $api_url = 'https://api.aoc-portal.com/v1/whatsapp';
    $api_key = 'u9kBwRS1JHyu7pvLMpY5zg1UV7cIE4';
    
    try {
        if (!$db) {
            // Fallback initialization if $db not provided
            $db = new PDO("mysql:host=swift.herosite.pro;dbname=surpriseville_emp;charset=utf8mb4", "surpriseville_emp", "Sv@123@4567", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }

        $stmt = $db->prepare("SELECT o.*, s.name as service_name FROM orders o LEFT JOIN services s ON o.service_id = s.id WHERE o.id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) return false;

        $service = $order['service_name'] ?: 'Service';
        $city = $order['city'] ?: 'Unknown City';
        $amount = $order['grand_total'] ?: '0';
        $customer = $order['customer_name'] ?? 'Customer';

        if ($reason === 'manual_allocation_required') {
            $title = "🚨 *Manual Allocation Needed*";
            $msg = "$title\n\nA new order has been placed in *Manual Mode* and requires admin assignment.\n\n*Order ID:* #$order_id\n*Service:* $service\n*City:* $city\n*Customer:* $customer\n*Total:* ₹$amount\n\nGo to Admin Panel to allocate.";
        } else {
            $title = "⚠️ *No Vendors Found*";
            $msg = "$title\n\nAn order was placed but no matching vendors were found for broadcasting.\n\n*Order ID:* #$order_id\n*Service:* $service\n*City:* $city\n*Total:* ₹$amount\n\nPlease check and assign manually.";
        }

        // Send via AOC Portal
        $data = [
            'key' => $api_key,
            'phone' => '+91' . $admin_phone,
            'message' => $msg
        ];

        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        $response = curl_exec($ch);
        curl_close($ch);

        return true;
    } catch (Exception $e) {
        error_log("Admin Notify Error: " . $e->getMessage());
        return false;
    }
}
