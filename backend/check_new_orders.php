<?php

/**
 * API Endpoint: Check New Orders & Update Live Location
 * URL: https://vendor.btnevents.in/backend/check_new_orders.php?vendor_id={ID}&lat={LAT}&lng={LNG}
 * * Response Format:
 * - {"new_order": true, "type": "order"} 
 * - {"new_order": false, "type": "none"}
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Database configuration (Vendor Portal)
define('DB_HOST', 'swift.herosite.pro');
define('DB_NAME', 'surpriseville_partners');
define('DB_USER', 'surpriseville_partners');
define('DB_PASS', 'Sv@123@4567');

// Main Website DB Configuration (for Shop Orders)
define('MAIN_DB_NAME', 'surpriseville_emp'); 
define('MAIN_DB_USER', 'surpriseville_emp');
define('MAIN_DB_PASS', 'Sv@123@4567');

/**
 * Function to connect to database
 */
function getDBConnection()
{
    try {
        $conn = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
            DB_USER,
            DB_PASS,
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
        );
        return $conn;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        return null;
    }
}

// Main Execution
try {
    // 1. Validate Request Method
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(array('error' => 'Method not allowed'));
        exit;
    }

    // 2. Validate Vendor ID
    $vendor_id = isset($_GET['vendor_id']) ? trim($_GET['vendor_id']) : '';
    if (empty($vendor_id) || !is_numeric($vendor_id)) {
        http_response_code(400);
        echo json_encode(array('error' => 'Valid vendor_id is required'));
        exit;
    }

    // 3. Connect to DB
    $conn = getDBConnection();
    if (!$conn) {
        http_response_code(500);
        echo json_encode(array('error' => 'Database connection failed'));
        exit;
    }

    // ============================================================
    // 📍 PART 1: UPDATE LIVE LOCATION (Critical for Admin Map)
    // ============================================================
    if (isset($_GET['lat']) && isset($_GET['lng'])) {
        $lat = $_GET['lat'];
        $lng = $_GET['lng'];

        // Validate coordinates
        if (is_numeric($lat) && is_numeric($lng)) {
            $locSql = "UPDATE vendors SET latitude = :lat, longitude = :lng, last_location_update = NOW() WHERE id = :vid";
            $locStmt = $conn->prepare($locSql);
            $locStmt->execute([
                ':lat' => $lat,
                ':lng' => $lng,
                ':vid' => $vendor_id
            ]);
        }
    }

    // ============================================================
    // 🔔 PART 2: CHECK FOR NEW ORDERS
    // ============================================================
    $has_order = false;
    $type = 'none';

    // A. Check Automated Orders
    $stmt1 = $conn->prepare("SELECT id FROM order_vendor_notifications WHERE vendor_id = :vid AND status = 'pending' LIMIT 1");
    $stmt1->execute([':vid' => $vendor_id]);
    
    if ($stmt1->fetch()) {
        $has_order = true;
        $type = 'order';
    }

    // B. Check Manual Gigs (if no automated order found)
    if (!$has_order) {
        $stmt2 = $conn->prepare("
            SELECT ta.task_id 
            FROM task_alerts ta
            JOIN manual_tasks mt ON mt.id = ta.task_id
            WHERE ta.vendor_id = :vid 
            AND ta.status = 'pending' 
            AND mt.status = 'open'
            LIMIT 1
        ");
        $stmt2->execute([':vid' => $vendor_id]);
        
        if ($stmt2->fetch()) {
            $has_order = true;
            $type = 'gig';
        }
    }

    // C. NEW: Check for 2-Hour Reminders (Upcoming Tasks)
    if (!$has_order) {
        // 1. Manual Tasks (Gigs)
        $mt_sql = "
            SELECT id 
            FROM manual_tasks 
            WHERE assigned_vendor_id = :vid 
            AND status = 'assigned'
            AND (reminder_sent IS NULL OR reminder_sent = 0)
            AND reach_datetime BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 125 MINUTE)
            LIMIT 1
        ";
        $stmt3 = $conn->prepare($mt_sql);
        $stmt3->execute([':vid' => $vendor_id]);
        $reminder_task = $stmt3->fetch(PDO::FETCH_ASSOC);

        if ($reminder_task) {
            $has_order = true;
            $type = 'reminder';
            
            $upd = $conn->prepare("UPDATE manual_tasks SET reminder_sent = 1 WHERE id = :tid");
            $upd->execute([':tid' => $reminder_task['id']]);
        }
    }

    if (!$has_order) {
        // 2. Shop Orders (Automated)
        // Fetch accepted but not-yet-reminded orders for this vendor
        $notif_sql = "SELECT id, order_id FROM order_vendor_notifications WHERE vendor_id = :vid AND status = 'accepted' AND (reminder_sent IS NULL OR reminder_sent = 0) LIMIT 5";
        $stmt4 = $conn->prepare($notif_sql);
        $stmt4->execute([':vid' => $vendor_id]);
        $potential_reminders = $stmt4->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($potential_reminders)) {
            // Need to check reach_time in MAIN DB for these orders
            try {
                $mainConn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . MAIN_DB_NAME, MAIN_DB_USER, MAIN_DB_PASS);
                foreach ($potential_reminders as $pr) {
                    $oid = $pr['order_id'];
                    // Parse booking_date and reach_time
                    $chk_sql = "
                        SELECT id 
                        FROM orders 
                        WHERE id = :oid 
                        AND STR_TO_DATE(CONCAT(booking_date, ' ', reach_time), '%Y-%m-%d %h:%i %p') BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 125 MINUTE)
                    ";
                    $chk_stmt = $mainConn->prepare($chk_sql);
                    $chk_stmt->execute([':oid' => $oid]);
                    if ($chk_stmt->fetch()) {
                        $has_order = true;
                        $type = 'reminder';
                        
                        // Mark reminder as sent in Vendor DB
                        $upd_notif = $conn->prepare("UPDATE order_vendor_notifications SET reminder_sent = 1 WHERE id = :nid");
                        $upd_notif->execute([':nid' => $pr['id']]);
                        break;
                    }
                }
            } catch (PDOException $e) {
                // Main DB log error but don't crash
                error_log("Main DB connection failed in reminder check: " . $e->getMessage());
            }
        }
    }

    // ============================================================
    // 🔐 PART 0: VALIDATE DEVICE ID (Single Device Lock)
    // ============================================================
    $req_device_id = isset($_GET['device_id']) ? $_GET['device_id'] : '';
    if (!empty($req_device_id)) {
        $devStmt = $conn->prepare("SELECT current_device_id FROM vendors WHERE id = :vid");
        $devStmt->execute([':vid' => $vendor_id]);
        $stored_device_id = $devStmt->fetchColumn();

        if ($stored_device_id && $stored_device_id !== $req_device_id) {
            echo json_encode(array('new_order' => false, 'type' => 'logout', 'message' => 'Logged in from another device'));
            exit;
        }
    }

    // Return Response
    echo json_encode(array('new_order' => $has_order, 'type' => $type));

} catch (Exception $e) {
    error_log("Unexpected error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(array('error' => 'Internal server error'));
}
?>