<?php
// vendor/gig_helper.php

/**
 * Expands the broadcast radius for a given manual task to find more vendors.
 * Stage 0: Broadcast to Internal Vendors (search_radius = 0)
 * Stage 1: Fallback to External Vendors (search_radius >= 10, up to 30)
 * Stage 2: Fallback to City-Wide (search_radius = 999) if no location
 */
function expandRadiusLoop($conn, $task_id)
{
    // 1. Get Task Info
    $t_sql = "SELECT category_id, subcategory_id, event_latitude, event_longitude, search_radius, original_price FROM manual_tasks WHERE id=$task_id";
    $t_res = $conn->query($t_sql);

    if ($t_res->num_rows == 0) return false;
    $t = $t_res->fetch_assoc();

    $lat = $t['event_latitude'];
    $lng = $t['event_longitude'];
    $cat_id = $t['category_id'];
    $subcat_id = $t['subcategory_id'] !== null ? (int)$t['subcategory_id'] : null;
    $current_radius = (int)$t['search_radius'];
    $original_price = (float)$t['original_price'];

    $max_radius = 30; // 30km Limit
    $expand_by = 5;   // +5km Step

    $found_new = false;
    $next_radius = $current_radius;

    // Check if we are currently in the Internal Phase (radius = 0)
    // If so, and we're being asked to expand, it means internal vendors declined or timed out.
    // Move to External Phase (starting at 10km)
    if ($current_radius == 0) {
        if ($lat && $lng) {
            $next_radius = 10;
        } else {
            // No GPS, jump straight to city-wide external fallback
            $next_radius = 999;
        }
    } else if ($current_radius >= 10 && $current_radius < 999) {
        $next_radius = $current_radius + $expand_by;
    }

    // Limit cross -> Send to Admin
    if ($next_radius > $max_radius && $next_radius != 999) {
        $conn->query("UPDATE manual_tasks SET status='unfilled' WHERE id=$task_id");
        return false;
    }

    // Loop until found or max limit
    while ($next_radius <= $max_radius || $next_radius == 999) {

        $pc_cond = $subcat_id === null 
            ? "(category_id = $cat_id AND subcategory_id IS NULL)" 
            : "( (category_id = $cat_id AND subcategory_id IS NULL) OR subcategory_id = $subcat_id )";

        if ($next_radius == 999) {
            // Fallback: Find all active EXTERNAL vendors with the required skill, regardless of location
            $sql = "SELECT DISTINCT v.id 
                    FROM vendors v
                    INNER JOIN vendor_subscriptions vs ON vs.vendor_id = v.id AND vs.status = 'active' AND vs.credits_remaining > 0
                    INNER JOIN packages p ON vs.package_id = p.id
                    LEFT JOIN vendor_wallet vw ON vw.vendor_id = v.id
                    WHERE v.status = 'active'
                    AND v.role != 'internal'
                    AND (vw.balance IS NULL OR vw.balance >= 0)
                    AND (
                        (SELECT COUNT(*) FROM package_categories WHERE package_id = vs.package_id) = 0
                        OR 
                        EXISTS (
                            SELECT 1 FROM package_categories 
                            WHERE package_id = vs.package_id 
                            AND $pc_cond
                        )
                    )
                    AND (p.order_min_price IS NULL OR $original_price >= p.order_min_price)
                    AND (p.order_max_price IS NULL OR $original_price <= p.order_max_price)
                    AND v.id NOT IN (SELECT vendor_id FROM task_alerts WHERE task_id = $task_id)";
        } else {
            // Find EXTERNAL Vendors in New Radius who were NOT notified before
            $sql = "SELECT DISTINCT v.id, 
            ( 6371 * acos( cos( radians($lat) ) * cos( radians( v.latitude ) ) * cos( radians( v.longitude ) - radians($lng) ) + sin( radians($lat) ) * sin( radians( v.latitude ) ) ) ) AS distance 
            FROM vendors v
            INNER JOIN vendor_subscriptions vs ON vs.vendor_id = v.id AND vs.status = 'active' AND vs.credits_remaining > 0
            INNER JOIN packages p ON vs.package_id = p.id
            LEFT JOIN vendor_wallet vw ON vw.vendor_id = v.id
            WHERE v.status = 'active'
            AND v.role != 'internal'
            AND (vw.balance IS NULL OR vw.balance >= 0)
            AND (
                (SELECT COUNT(*) FROM package_categories WHERE package_id = vs.package_id) = 0
                OR 
                EXISTS (
                    SELECT 1 FROM package_categories 
                    WHERE package_id = vs.package_id 
                    AND $pc_cond
                )
            )
            AND (p.order_min_price IS NULL OR $original_price >= p.order_min_price)
            AND (p.order_max_price IS NULL OR $original_price <= p.order_max_price)
            HAVING distance <= $next_radius
            AND id NOT IN (SELECT vendor_id FROM task_alerts WHERE task_id = $task_id)";
        }

        $res = $conn->query($sql);

        if ($res && $res->num_rows > 0) {
            // New Vendors Found!
            while ($v = $res->fetch_assoc()) {
                $vid = $v['id'];
                $conn->query("INSERT INTO task_alerts (task_id, vendor_id, status, sent_at) VALUES ($task_id, $vid, 'pending', NOW())");
            }
            // Update Radius & Reset Timer so they get 30 mins
            $conn->query("UPDATE manual_tasks SET search_radius=$next_radius, last_radius_update=NOW() WHERE id=$task_id");
            $found_new = true;
            break; // Stop loop because we found people
        }

        if ($next_radius == 999) break; // Finished fallback

        // Agar is radius me bhi koi nahi mila, to aur +5 badhao
        $next_radius += $expand_by;
        if ($next_radius > $max_radius) {
            $next_radius = 999; // jump to fallback if max radius exceeded and nobody found
        }
    }

    // Agar sab try kar liya aur koi nahi mila
    if (!$found_new && $next_radius == 999) {
        $conn->query("UPDATE manual_tasks SET status='unfilled' WHERE id=$task_id");
    }

    return $found_new;
}

/**
 * Sync status to CRM database
 */
function syncStatusToCRM($conn, $task_id, $vendor_status) {
    try {
        // 1. Fetch task details to check crm_booking_id and assigned_vendor_id
        $stmt = $conn->prepare("SELECT crm_booking_id, assigned_vendor_id FROM manual_tasks WHERE id = ?");
        $stmt->bind_param("i", $task_id);
        $stmt->execute();
        $task = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$task || empty($task['crm_booking_id'])) {
            return; // Not a CRM booking
        }

        $partners_crm_id = intval($task['crm_booking_id']);
        
        // Fetch the actual crm_booking_id (representing bookings.id in CRM DB) from crm_bookings
        $cb_stmt = $conn->prepare("SELECT crm_booking_id FROM crm_bookings WHERE id = ?");
        $cb_stmt->bind_param("i", $partners_crm_id);
        $cb_stmt->execute();
        $cb_res = $cb_stmt->get_result()->fetch_assoc();
        $cb_stmt->close();
        
        if (!$cb_res || empty($cb_res['crm_booking_id'])) {
            return; // Linked CRM booking not found
        }

        $crm_booking_id = intval($cb_res['crm_booking_id']);
        $vendor_id = intval($task['assigned_vendor_id']);

        // 2. Fetch vendor info
        $vendor_name = '';
        if ($vendor_id > 0) {
            $v_stmt = $conn->prepare("SELECT name, business_name FROM vendors WHERE id = ?");
            $v_stmt->bind_param("i", $vendor_id);
            $v_stmt->execute();
            $vendor = $v_stmt->get_result()->fetch_assoc();
            $v_stmt->close();

            $vendor_name = $vendor ? ($vendor['business_name'] ?: $vendor['name']) : 'Vendor';
        }

        // 3. Map status
        $crm_status = '';
        $log_message = '';
        switch ($vendor_status) {
            case 'open':
            case 'broadcasted':
                $crm_status = 'Broadcasted';
                $log_message = "Task has been broadcasted to local vendors. Searching for vendor...";
                $vendor_name = 'Broadcasting...';
                break;
            case 'assigned':
                $crm_status = 'Vendor Assigned';
                $log_message = "Vendor " . ($vendor_name ?: 'Vendor') . " has been assigned to this booking.";
                break;
            case 'out_for_service':
                $crm_status = 'Out for Delivery';
                $log_message = "Vendor $vendor_name is out for delivery/service.";
                break;
            case 'reached':
                $crm_status = 'Reached at Location';
                $log_message = "Vendor $vendor_name has reached the event location.";
                break;
            case 'started':
                $crm_status = 'Work Started';
                $log_message = "Vendor $vendor_name has started setup/work.";
                break;
            case 'completed':
                $crm_status = 'Work Completed';
                $log_message = "Vendor $vendor_name has completed the service.";
                break;
        }

        if (empty($crm_status)) {
            return;
        }

        // 4. Update Partners crm_bookings status
        $p_status = ($vendor_status === 'completed') ? 'completed' : (($vendor_status === 'open' || $vendor_status === 'broadcasted') ? 'pending' : 'assigned');
        $upd_p = $conn->prepare("UPDATE crm_bookings SET status = ? WHERE id = ?");
        $upd_p->bind_param("si", $p_status, $partners_crm_id);
        $upd_p->execute();
        $upd_p->close();

        // 5. Connect to CRM database
        $crm_host = 'swift.herosite.pro';
        $crm_db = 'btneventsin_crm';
        $crm_user = 'btneventsin_crm';
        $crm_pass = 'Btn@123@12';

        $crm_pdo = new PDO("mysql:host=$crm_host;dbname=$crm_db;charset=utf8", $crm_user, $crm_pass);
        $crm_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Fetch user_id and lead_id from CRM bookings table
        $bk_stmt = $crm_pdo->prepare("SELECT user_id, lead_id FROM bookings WHERE id = ?");
        $bk_stmt->execute([$crm_booking_id]);
        $booking_info = $bk_stmt->fetch(PDO::FETCH_ASSOC);

        if ($booking_info) {
            $crm_user_id = intval($booking_info['user_id']);
            $lead_id = intval($booking_info['lead_id']);

            // Update bookings table
            $upd_bk = $crm_pdo->prepare("UPDATE bookings SET work_status = ?, assigned_staff_name = ? WHERE id = ?");
            $upd_bk->execute([$crm_status, $vendor_name, $crm_booking_id]);

            // Add history log in lead_logs
            $log_stmt = $crm_pdo->prepare("INSERT INTO lead_logs (lead_id, user_id, type, message, status_tag) VALUES (?, ?, 'booking', ?, ?)");
            $log_stmt->execute([$lead_id, $crm_user_id, $log_message, $crm_status]);
        }
    } catch (Exception $e) {
        error_log("CRM Sync Error: " . $e->getMessage());
    }
}

