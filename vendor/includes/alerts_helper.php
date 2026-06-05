<?php
// vendor/includes/alerts_helper.php

/**
 * Fetches and filters available alerts (both online shop orders and offline manual tasks)
 * according to the vendor's active subscription, allowed categories, and expiration settings.
 */
function getAvailableAlerts($conn, $mainConn, $vendor_id)
{
    // Fetch vendor role (internal/external)
    $vendor_role = 'external';
    $vRoleQ = $conn->prepare("SELECT role FROM vendors WHERE id = ? LIMIT 1");
    $vRoleQ->bind_param("i", $vendor_id);
    $vRoleQ->execute();
    $vRoleRes = $vRoleQ->get_result()->fetch_assoc();
    $vRoleQ->close();
    if (!empty($vRoleRes['role'])) {
        $vendor_role = strtolower(trim($vRoleRes['role']));
    }

    // Get vendor's active subscription package and its allowed rules
    $package_id = null;
    $pc_rules = [];
    $has_rules = false;

    $sub_stmt = $conn->prepare("SELECT package_id FROM vendor_subscriptions WHERE vendor_id = ? AND status = 'active' AND credits_remaining > 0 LIMIT 1");
    $sub_stmt->bind_param("i", $vendor_id);
    $sub_stmt->execute();
    $sub_res = $sub_stmt->get_result();
    if ($sub_row = $sub_res->fetch_assoc()) {
        $package_id = (int)$sub_row['package_id'];
    }
    $sub_stmt->close();

    if ($package_id !== null) {
        $pc_stmt = $conn->prepare("SELECT category_id, subcategory_id FROM package_categories WHERE package_id = ?");
        $pc_stmt->bind_param("i", $package_id);
        $pc_stmt->execute();
        $pc_res = $pc_stmt->get_result();
        while ($pc_row = $pc_res->fetch_assoc()) {
            $has_rules = true;
            $c = (int)$pc_row['category_id'];
            $s = $pc_row['subcategory_id'] !== null ? (int)$pc_row['subcategory_id'] : null;
            if (!isset($pc_rules[$c])) {
                $pc_rules[$c] = [];
            }
            $pc_rules[$c][] = $s;
        }
        $pc_stmt->close();
    }

    $isAllowed = function($c, $s) use ($has_rules, $pc_rules) {
        if (!$has_rules) {
            return true; // No rules means all allowed
        }
        if (!isset($pc_rules[$c])) {
            return false; // Category not allowed
        }
        if (in_array(null, $pc_rules[$c], true) || empty($pc_rules[$c])) {
            return true; // All subcategories allowed under this category
        }
        return $s !== null && in_array((int)$s, $pc_rules[$c], true);
    };

    $alerts = [];
    // A. Automated Orders
    $stmt = $conn->prepare("SELECT id, order_id, sent_at, 'auto' as type FROM order_vendor_notifications WHERE vendor_id = ? AND status = 'pending'");
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $alerts[] = $r;
    $stmt->close();

    // B. Offline Orders
    $stmt2 = $conn->prepare("
        SELECT ta.task_id as order_id, ta.sent_at, 'manual' as type, mt.status as task_status
        FROM task_alerts ta
        JOIN manual_tasks mt ON mt.id = ta.task_id
        WHERE ta.vendor_id = ? AND ta.status = 'pending' AND mt.status = 'open'
    ");
    $stmt2->bind_param("i", $vendor_id);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    while ($r = $res2->fetch_assoc()) $alerts[] = $r;
    $stmt2->close();

    // C. Sort (Newest First)
    usort($alerts, function ($a, $b) {
        return strtotime($b['sent_at']) - strtotime($a['sent_at']);
    });

    $finalAlerts = [];
    foreach ($alerts as $item) {
        $id       = $item['order_id'];
        $notif_id = isset($item['id']) ? (int)$item['id'] : 0;
        $isManual = ($item['type'] === 'manual');
        $sentAt   = strtotime($item['sent_at']);
        $expiryTs = $sentAt + 1800; // 30 min expiry

        // Expiry cleanup
        if (time() > $expiryTs) {
            if (!$isManual && $notif_id > 0) {
                $conn->query("UPDATE order_vendor_notifications SET status='expired' WHERE id=$notif_id");
            } elseif ($isManual) {
                $conn->query("UPDATE task_alerts SET status='expired' WHERE task_id=$id AND vendor_id=$vendor_id");
            }
            continue;
        }

        if ($isManual) {
            $q = $conn->query("SELECT mt.*, gc.name as cat_name FROM manual_tasks mt LEFT JOIN gig_categories gc ON gc.id = mt.category_id WHERE mt.id = $id");
            $data = $q->fetch_assoc();
            if (!$data) continue;
            if ($data['status'] !== 'open' || ($data['assigned_vendor_id'] > 0 && $data['assigned_vendor_id'] != $vendor_id)) {
                $conn->query("UPDATE task_alerts SET status='expired' WHERE task_id=$id AND vendor_id=$vendor_id");
                continue;
            }

            // Check subscription package categories/subcategories allowed rules
            $m_cat = $data['category_id'] !== null ? (int)$data['category_id'] : null;
            $m_subcat = $data['subcategory_id'] !== null ? (int)$data['subcategory_id'] : null;
            if ($m_cat !== null && !$isAllowed($m_cat, $m_subcat)) {
                continue; // Subscription doesn't allow this category/subcategory
            }

            // Prepare Title (Category + Service Title)
            $displayTitle = $data['cat_name'];
            if (!empty($data['service_title'])) {
                $displayTitle .= " - " . $data['service_title'];
            }

            // Prepare Data
            $remarks = $data['remarks'] ?? '';
            $eventDate = "Not Set";
            $timingInfo = "";
            if (preg_match('/Event:\s*(.*?)(?=\s*(Reach:|Reach\:|Ready:|Ready\:|Notes:|Notes\:|\n|\r|$))/i', $remarks, $m)) $eventDate = trim($m[1]);
            if (preg_match('/Reach:\s*(.*?)(?=\s*(Ready:|Ready\:|Notes:|Notes\:|\n|\r|$))/i', $remarks, $m)) $timingInfo .= "Reach: " . trim($m[1]);
            if (preg_match('/Ready:\s*(.*?)(?=\s*(Notes:|Notes\:|\n|\r|$))/i', $remarks, $m)) $timingInfo .= ($timingInfo ? " | " : "") . "Ready: " . trim($m[1]);

            $vPrice = isset($data['vendor_price']) ? floatval($data['vendor_price']) : 0;
            $ivPrice = isset($data['internal_vendor_price']) ? floatval($data['internal_vendor_price']) : 0;
            $price = ($vendor_role === 'internal') ? ($ivPrice > 0 ? $ivPrice : $vPrice) : ($vPrice > 0 ? $vPrice : 0);

            $media = json_decode($data['admin_media'] ?? '[]', true);
            $finalAlerts[] = array_merge($item, [
                'title' => $displayTitle,
                'desc' => $data['inclusions'],
                'price' => $price,
                'priceLabel' => "You Earn",
                'eventDate' => $eventDate,
                'timingInfo' => $timingInfo,
                'loc' => $data['locality'],
                'img' => !empty($media) ? '../uploads/admin_task_media/' . $media[0] : '',
                'typeLabel' => "🛠 Offline Orders",
                'badgeColor' => "#fd7e14",
                'accentColor' => "#ff9800",
                'expiryTs' => $expiryTs,
                'inclusions' => $data['inclusions'],
                'gallery' => [],
                'productLink' => '',
                'sticky_note' => $data['sticky_note'] ?? '',
                'order_type' => $data['order_type'] ?? ''
            ]);
        } else {
            // Shop Orders
            $sql = "SELECT o.address_line, o.city, o.booking_date, o.datetime, o.reach_time, o.ready_time, o.vendor_id, o.sticky_note, s.id as service_id, s.name, s.description, s.main_image, s.gallery, s.slug, s.category_id as service_cat_id, s.subcategory_id as service_subcat_id, s.vendor_price, s.manpower_price, o.base_amount as price FROM orders o LEFT JOIN services s ON s.id = o.service_id WHERE o.id = $id LIMIT 1";
            $q = $mainConn->query($sql);
            if (!$q) continue;
            $data = $q->fetch_assoc();
            if (!$data) continue;

            $mainServiceTaken = ($data['vendor_id'] > 0 && $data['vendor_id'] != $vendor_id);
            $roleFound = false;
            $alertData = [];

            $main_cat = $data['service_cat_id'] !== null ? (int)$data['service_cat_id'] : null;
            $main_subcat = $data['service_subcat_id'] !== null ? (int)$data['service_subcat_id'] : null;

            if (!$mainServiceTaken && $isAllowed($main_cat, $main_subcat)) {
                // Main Service Match
                $roleFound = true;
                $vendor_price = isset($data['vendor_price']) ? floatval($data['vendor_price']) : 0;
                $manpower_price = isset($data['manpower_price']) ? floatval($data['manpower_price']) : 0;
                $base_price = isset($data['price']) ? floatval($data['price']) : 0;
                $price = ($vendor_role === 'internal') ? ($manpower_price > 0 ? $manpower_price : ($vendor_price > 0 ? $vendor_price : $base_price)) : ($vendor_price > 0 ? $vendor_price : $base_price);

                $alertData = [
                    'title' => $data['name'],
                    'desc' => strip_tags($data['description']),
                    'price' => $price,
                    'priceLabel' => "Payout",
                    'img' => $data['main_image'] ? "https://surpriseville.co.in/" . ltrim($data['main_image'], "/") : "",
                    'typeLabel' => "🛒 Online Orders",
                    'badgeColor' => "var(--stat-bg-5)",
                    'accentColor' => "#007bff",
                    'gallery' => json_decode($data['gallery'] ?? '[]', true),
                    'productLink' => $data['slug'] ? "https://surpriseville.co.in/service-details.php?slug=" . urlencode($data['slug']) : ''
                ];
            } else {
                // Check Addons
                $addonQ = $mainConn->query("SELECT a.id as addon_id, a.name, a.description, a.image, a.category_id, a.subcategory_id, a.price FROM order_addons oa JOIN addons a ON oa.addon_id = a.id WHERE oa.order_id = $id");
                while ($addon = $addonQ->fetch_assoc()) {
                    $addon_cat = $addon['category_id'] !== null ? (int)$addon['category_id'] : null;
                    $addon_subcat = $addon['subcategory_id'] !== null ? (int)$addon['subcategory_id'] : null;

                    if ($isAllowed($addon_cat, $addon_subcat)) {
                        $aid = $addon['addon_id'];
                        $asn = $mainConn->query("SELECT vendor_id FROM order_vendor_assignments WHERE order_id = $id AND addon_id = $aid LIMIT 1")->fetch_assoc();
                        if ($asn && $asn['vendor_id'] > 0 && $asn['vendor_id'] != $vendor_id) continue;

                        $roleFound = true;
                        $alertData = [
                            'title' => $addon['name'],
                            'desc' => strip_tags($addon['description']),
                            'price' => floatval($addon['price']),
                            'priceLabel' => "Addon Payout",
                            'img' => $addon['image'] ? "https://surpriseville.co.in/" . ltrim($addon['image'], "/") : "",
                            'typeLabel' => "➕ Online Addon",
                            'badgeColor' => "var(--stat-bg-4)",
                            'accentColor' => "#9c27b0",
                            'gallery' => [],
                            'productLink' => ''
                        ];
                        break;
                    }
                }
            }

            if (!$roleFound) continue;

            // Finalize Shop Order Details
            $eventDate = $data['booking_date'] ?: ($data['datetime'] ?: "Not Set");
            $timingInfo = "";
            if ($eventDate != "Not Set" && strpos($eventDate, ' ') !== false && empty($data['reach_time'])) {
                $pts = explode(' ', $eventDate);
                $eventDate = $pts[0];
                $timingInfo = "Time: " . $pts[1];
            }
            if (!empty($data['reach_time'])) $timingInfo = "Reach: " . $data['reach_time'];
            if (!empty($data['ready_time'])) $timingInfo .= ($timingInfo ? " | " : "") . "Ready: " . $data['ready_time'];

            $finalAlerts[] = array_merge($item, $alertData, [
                'loc' => $data['address_line'] ?: $data['city'],
                'eventDate' => $eventDate,
                'timingInfo' => $timingInfo,
                'expiryTs' => $expiryTs,
                'inclusions' => $data['description'] ?? '',
                'sticky_note' => $data['sticky_note'] ?? ''
            ]);
        }
    }
    return $finalAlerts;
}
