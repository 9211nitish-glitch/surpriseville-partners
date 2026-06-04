<?php
// backend/whatsapp_helper.php

// API Credentials (AOC Portal)
if (!defined('WHATSAPP_API_URL')) define('WHATSAPP_API_URL', 'https://api.aoc-portal.com/v1/whatsapp');
if (!defined('WHATSAPP_API_KEY')) define('WHATSAPP_API_KEY', 'u9kBwRS1JHyu7pvLMpY5zg1UV7cIE4');
if (!defined('WHATSAPP_SENDER_PHONE')) define('WHATSAPP_SENDER_PHONE', '+919871919411');

/**
 * Sends WhatsApp notification for Order or Gig status updates.
 * 
 * @param mysqli $conn The database connection (Main DB for orders, Vendor DB for gigs)
 * @param int $id The ID (order_id or task_id)
 * @param string $status The new status
 * @param bool $isGig True if it's a Manual Gig, False if it's a Shop Order
 */
function sendOrderStatusNotification($conn, $id, $status, $isGig = false) {
    $phone = '';
    $name = '';
    $orderRef = '';

    try {
        if (!$conn) {
            error_log("WhatsApp Helper: Database connection is null.");
            return false;
        }

        if ($isGig) {
            // Fetch from manual_tasks (Vendor DB)
            $stmt = $conn->prepare("SELECT client_phone, client_name, service_title FROM manual_tasks WHERE id = ?");
            if (!$stmt) {
                error_log("WhatsApp Helper: Prepare failed (Gig) - " . $conn->error);
                return false;
            }
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($r = $res->fetch_assoc()) {
                $phone = $r['client_phone'];
                $name = $r['client_name'];
                $orderRef = "*" . ($r['service_title'] ?: 'Service') . "*";
            }
            $stmt->close();
        } else {
            // Fetch from orders (Main DB) - Join with services to get the name
            $stmt = $conn->prepare("SELECT o.phone, o.name, s.name as service_name 
                                    FROM orders o 
                                    LEFT JOIN services s ON o.service_id = s.id 
                                    WHERE o.id = ?");
            if (!$stmt) {
                error_log("WhatsApp Helper: Prepare failed (Order) - " . $conn->error);
                return false;
            }
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($r = $res->fetch_assoc()) {
                $phone = $r['phone'];
                $name = $r['name'];
                $orderRef = "*" . ($r['service_name'] ?: 'Order') . "*";
            }
            $stmt->close();
        }

        if (empty($phone)) {
            error_log("WhatsApp Helper: Phone number not found for ID $id (isGig: " . ($isGig?'yes':'no') . ")");
            return false;
        }

        // Format Phone
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) === 10) $phone = "+91" . $phone;
        elseif (strlen($phone) === 12 && substr($phone, 0, 2) === '91') $phone = "+" . $phone;

        // Support Contact
        $supportContact = "8745818818";
        $supportSuffix = "\n\nFor any queries, feel free to call or WhatsApp us at $supportContact.";

        // Status Messages
        switch ($status) {
            case 'confirmed':
            case 'assigned':
                $msg = "Greetings from *Surprise Ville*! ✨\n\nDear $name, your $orderRef has been successfully confirmed and assigned to our professional team. We are committed to making your event special!$supportSuffix";
                break;
            case 'out_for_service':
                $msg = "Dear $name, our team is now on the way for your $orderRef! 🚀 We'll be arriving shortly to begin the setup.$supportSuffix";
                break;
            case 'reached':
                $locLink = "";
                // Try to fetch location from DB
                if ($isGig) {
                    $lStmt = $conn->prepare("SELECT vendor_loc_reached FROM manual_tasks WHERE id = ?");
                    $lStmt->bind_param("i", $id);
                    $lStmt->execute();
                    $lRes = $lStmt->get_result()->fetch_assoc();
                    if (!empty($lRes['vendor_loc_reached'])) $locLink = "\n\n📍 *Current Location:* https://www.google.com/maps?q=" . $lRes['vendor_loc_reached'];
                    $lStmt->close();
                } else {
                    $lStmt = $conn->prepare("SELECT loc_reached FROM order_vendor_assignments WHERE id = (SELECT id FROM order_vendor_assignments WHERE order_id = ? AND vendor_id IS NOT NULL ORDER BY id DESC LIMIT 1)");
                    $lStmt->bind_param("i", $id);
                    $lStmt->execute();
                    $lRes = $lStmt->get_result()->fetch_assoc();
                    if (!empty($lRes['loc_reached'])) $locLink = "\n\n📍 *Current Location:* https://www.google.com/maps?q=" . $lRes['loc_reached'];
                    $lStmt->close();
                }
                $msg = "Good news! Our team has reached the venue for $orderRef. 📍$locLink$supportSuffix";
                break;
            case 'started':
                $msg = "Dear $name, the work for your $orderRef has officially started! ✨ We are working hard to ensure everything is perfect for you.$supportSuffix";
                break;
            case 'completed':
                $rateLink = "https://partners.surpriseville.co.in/rate-vendor.php?order_id=$id&is_gig=" . ($isGig ? 1 : 0);
                $msg = "Congratulations! Your $orderRef has been successfully completed. 🎊\n\nWe hope you are delighted with our service. Please take a moment to rate the team:\n⭐ *Rate here:* $rateLink\n\nThank you for choosing *Surprise Ville* for your special occasion! 🙏$supportSuffix";
                break;
            default:
                $msg = "Hi $name, your $orderRef status is now: " . strtoupper($status) . ".$supportSuffix";
        }

        // Send via AOC Portal
        $data = [
            "from" => WHATSAPP_SENDER_PHONE,
            "to" => $phone,
            "type" => "text",
            "text" => $msg
        ];

        if (!function_exists('curl_init')) {
            error_log("WhatsApp Helper: cURL is not enabled on this server.");
            return false;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => WHATSAPP_API_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                "apikey: " . WHATSAPP_API_KEY,
                "content-type: application/json"
            ],
            CURLOPT_TIMEOUT => 5
        ]);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            error_log("WhatsApp Helper: cURL Error - " . curl_error($ch));
            curl_close($ch);
            return false;
        }
        curl_close($ch);

        return $response;

    } catch (Throwable $t) {
        error_log("WhatsApp Notification Exception: " . $t->getMessage());
        return false;
    }
}

/**
 * Sends a WhatsApp OTP using the 'otpverification' template.
 * 
 * @param string $phone The recipient's phone number
 * @param string $otp The 4 or 6 digit OTP
 * @return bool True if successful, false otherwise
 */
function sendWhatsAppOTP($phone, $otp) {
    // Format Phone
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) === 10) $phone = "+91" . $phone;
    elseif (strlen($phone) === 12 && substr($phone, 0, 2) === '91') $phone = "+" . $phone;

    $data = [
        "from" => WHATSAPP_SENDER_PHONE,
        "to" => $phone,
        "type" => "template",
        "templateName" => "otpverification",
        "otp" => (string)$otp,
        "language" => array("code" => "en")
    ];

    if (!function_exists('curl_init')) {
        error_log("WhatsApp Helper (OTP): cURL not enabled.");
        return false;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => WHATSAPP_API_URL,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            "apikey: " . WHATSAPP_API_KEY,
            "content-type: application/json"
        ],
        CURLOPT_TIMEOUT => 10
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        error_log("WhatsApp OTP Error (cURL): " . $err);
        return false;
    }

    $resData = json_decode($response, true);
    return ($httpCode >= 200 && $httpCode < 300);
}