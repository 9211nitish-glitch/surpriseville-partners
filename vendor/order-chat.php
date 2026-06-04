<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/../db.php';       // $conn (vendor DB mysqli)
require_once __DIR__ . '/../db_main.php';  // $mainConn (main DB mysqli)

$order_id   = intval($_GET['order_id'] ?? 0);
$is_offline = intval($_GET['is_offline'] ?? 0);
$call_id    = intval($_GET['call_id'] ?? 0); // optional: auto-accept this incoming call

if (!$order_id) {
    echo "Invalid Order ID";
    exit;
}

$vendor_id = $_SESSION['vendor_id'];

// --- SELF-CONTAINED STATUS POLLING ENDPOINT ---
if (isset($_GET['ajax_status'])) {
    header('Content-Type: application/json');
    $status_response = ['success' => false, 'status' => 'assigned'];
    
    if ($is_offline) {
        $stmt = $conn->prepare("SELECT vendor_status FROM manual_tasks WHERE id = ? AND assigned_vendor_id = ? LIMIT 1");
        $stmt->bind_param("ii", $order_id, $vendor_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        if ($res) {
            $status_response = ['success' => true, 'status' => $res['vendor_status'] ?: 'assigned'];
        }
    } else {
        $stmt = $mainConn->prepare("SELECT status FROM order_vendor_assignments WHERE order_id = ? AND vendor_id = ? LIMIT 1");
        $stmt->bind_param("ii", $order_id, $vendor_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        if ($res) {
            $status_response = ['success' => true, 'status' => $res['status'] ?: 'assigned'];
        }
    }
    echo json_encode($status_response);
    exit;
}

// --- LOAD DETAILS FOR SIDEBAR & CHAT ---
$details = [];
if ($is_offline) {
    // Verify manual task belongs to this vendor
    $stmt = $conn->prepare("
        SELECT mt.*, gc.name as cat_name 
        FROM manual_tasks mt 
        LEFT JOIN gig_categories gc ON mt.category_id = gc.id
        WHERE mt.id = ? AND mt.assigned_vendor_id = ? LIMIT 1
    ");
    $stmt->bind_param("ii", $order_id, $vendor_id);
    $stmt->execute();
    $task_row = $stmt->get_result()->fetch_assoc();

    if (!$task_row) {
        echo "Task not found or access denied.";
        exit;
    }

    $customer_id   = 1; // Default Admin ID for ChatEngine targetId
    $customer_name = "Admin (Support)";
    $vendor_name   = addslashes($_SESSION['vendor_name'] ?? 'Vendor');
    
    $details = [
        'title' => $task_row['cat_name'] ?: ($task_row['service_title'] ?: 'Offline Task #' . $order_id),
        'customer' => $task_row['client_name'],
        'date' => $task_row['created_at'],
        'address' => $task_row['full_address'] . ', ' . $task_row['locality'],
        'earning' => floatval($task_row['vendor_price']),
        'collect' => floatval($task_row['amount_to_collect']),
        'inclusions' => $task_row['inclusions'],
        'remarks' => $task_row['remarks'],
        'status' => $task_row['vendor_status'] ?: 'assigned',
        'map_link' => !empty($task_row['google_map']) ? $task_row['google_map'] : "https://www.google.com/maps/search/?api=1&query=" . urlencode($task_row['full_address']),
    ];
} else {
    // Verify order belongs to this vendor AND fetch customer/assignment info
    $stmt = $mainConn->prepare("
        SELECT o.user_id, u.name AS customer_name, o.datetime, o.address_line, o.city, o.remaining_amount,
               s.name as service_name, s.description as service_desc,
               ova.status as vendor_status, ova.vendor_price as assignment_price,
               s.vendor_price as service_vendor_price, s.manpower_price as service_manpower_price, o.base_amount
        FROM orders o 
        LEFT JOIN users u ON o.user_id = u.id 
        LEFT JOIN services s ON o.service_id = s.id
        JOIN order_vendor_assignments ova ON o.id = ova.order_id AND ova.vendor_id = ?
        WHERE o.id = ? LIMIT 1
    ");
    $stmt->bind_param("ii", $vendor_id, $order_id);
    $stmt->execute();
    $ord_row = $stmt->get_result()->fetch_assoc();

    if (!$ord_row) {
        echo "Order not found or access denied.";
        exit;
    }

    $customer_id   = $ord_row['user_id'];
    $customer_name = htmlspecialchars($ord_row['customer_name'] ?? 'Customer');
    $vendor_name   = addslashes($_SESSION['vendor_name'] ?? 'Vendor');

    // Fetch addon assignment if exists
    $ovq = "SELECT a.name as addon_name, a.description as addon_desc, a.price as addon_price 
            FROM order_vendor_assignments ova 
            JOIN addons a ON ova.addon_id = a.id 
            WHERE ova.order_id = $order_id 
            AND ova.vendor_id = $vendor_id 
            AND ova.addon_id IS NOT NULL 
            LIMIT 1";
    $ovRes = $mainConn->query($ovq);
    $is_addon = false;
    $addon_title = '';
    $addon_desc = '';
    $addon_price = 0;
    if ($ovRes && $ovRes->num_rows > 0) {
        $ovData = $ovRes->fetch_assoc();
        $is_addon = true;
        $addon_title = $ovData['addon_name'] . " (Addon)";
        $addon_desc = "Addon Service: " . $ovData['addon_desc'];
        $addon_price = floatval($ovData['addon_price']);
    }

    // Role check for earning calculation
    $vRole = 'external';
    $vq = $conn->query("SELECT role FROM vendors WHERE id = $vendor_id LIMIT 1");
    if ($vq && $rv = $vq->fetch_assoc()) {
        $vRole = strtolower(trim($rv['role']));
    }

    $earning = 0;
    if ($is_addon) {
        $earning = $addon_price;
    } else {
        $modPrice    = floatval($ord_row['assignment_price'] ?? 0);
        $vPriceInput = floatval($ord_row['service_vendor_price'] ?? 0);
        $mPrice      = floatval($ord_row['service_manpower_price'] ?? 0);
        $bPrice      = floatval($ord_row['base_amount'] ?? 0);
        if ($modPrice > 0) {
            $earning = $modPrice;
        } else {
            $earning = ($vRole === 'internal') ? ($mPrice > 0 ? $mPrice : ($vPriceInput > 0 ? $vPriceInput : $bPrice)) : ($vPriceInput > 0 ? $vPriceInput : $bPrice);
        }
    }

    $details = [
        'title' => $is_addon ? $addon_title : ($ord_row['service_name'] ?: 'Online Order #' . $order_id),
        'customer' => $ord_row['customer_name'] ?: 'Customer',
        'date' => $ord_row['datetime'],
        'address' => $ord_row['address_line'] . ', ' . $ord_row['city'],
        'earning' => floatval($earning),
        'collect' => floatval($ord_row['remaining_amount']),
        'inclusions' => $is_addon ? $addon_desc : ($ord_row['service_desc'] ?: ''),
        'remarks' => '',
        'status' => $ord_row['vendor_status'] ?: 'assigned',
        'map_link' => "https://www.google.com/maps/search/?api=1&query=" . urlencode($ord_row['address_line'] . ', ' . $ord_row['city']),
    ];
}

$page_title = "Chat: Order #" . $order_id;
include __DIR__ . '/header.php';
?>

<style>
    #floatingChatContainer {
        display: none !important;
    }

    .chat-layout {
        display: grid;
        grid-template-columns: 1fr 340px;
        height: calc(100dvh - 120px);
        background: var(--bg-card);
        border-radius: 20px;
        overflow: hidden;
        border: 1px solid var(--border-color);
        box-shadow: 0 12px 40px rgba(0, 0, 0, 0.03);
        transition: all 0.3s ease;
    }
    
    .chat-main-panel {
        display: flex;
        flex-direction: column;
        height: 100%;
        overflow: hidden;
    }

    .chat-sidebar-panel {
        display: flex;
        flex-direction: column;
        background: var(--bg-body);
        border-left: 1px solid var(--border-color);
        overflow-y: auto;
        padding: 24px;
        gap: 24px;
    }

    /* Stepper CSS */
    .stepper {
        display: flex;
        flex-direction: column;
        gap: 16px;
        background: var(--bg-card);
        padding: 20px;
        border-radius: 16px;
        border: 1px solid var(--border-color);
        box-shadow: 0 4px 20px rgba(0,0,0,0.01);
    }
    .stepper-step {
        display: flex;
        align-items: center;
        gap: 14px;
        position: relative;
    }
    .stepper-step:not(:last-child)::after {
        content: '';
        position: absolute;
        left: 16px;
        top: 32px;
        bottom: -16px;
        width: 2px;
        background: var(--border-color);
        z-index: 1;
    }
    .stepper-icon {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        background: var(--bg-body);
        border: 2px solid var(--border-color);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 13px;
        color: var(--text-muted);
        z-index: 2;
        font-weight: 700;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .stepper-step.completed .stepper-icon {
        background: #10b981;
        border-color: #10b981;
        color: #fff;
    }
    .stepper-step.active .stepper-icon {
        background: var(--primary);
        border-color: var(--primary);
        color: #fff;
        box-shadow: 0 0 12px rgba(59, 130, 246, 0.3);
    }
    .stepper-step.completed:not(:last-child)::after {
        background: #10b981;
    }
    .stepper-label {
        font-size: 13px;
        font-weight: 600;
        color: var(--text-muted);
    }
    .stepper-step.active .stepper-label {
        color: var(--text-main);
    }
    .stepper-step.completed .stepper-label {
        color: #10b981;
    }

    .info-card {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 20px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.01);
    }
    .info-card h4 {
        margin: 0 0 14px 0;
        font-size: 12px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: var(--text-muted);
    }
    .info-row {
        display: flex;
        flex-direction: column;
        gap: 6px;
        margin-bottom: 14px;
    }
    .info-row:last-child {
        margin-bottom: 0;
    }
    .info-label {
        font-size: 11px;
        color: var(--text-muted);
        text-transform: uppercase;
        font-weight: 700;
        letter-spacing: 0.5px;
    }
    .info-value {
        font-size: 13.5px;
        color: var(--text-main);
        font-weight: 600;
        line-height: 1.4;
    }

    /* Modern Chat Bubble Styling Overrides */
    .sv-msg-row {
        margin: 6px 0 !important;
    }
    .sv-bubble {
        border-radius: 18px !important;
        padding: 10px 15px !important;
        font-family: inherit !important;
        font-weight: 500 !important;
        box-shadow: 0 2px 10px rgba(0,0,0,0.02) !important;
    }
    .sv-bubble--me {
        background: linear-gradient(135deg, #135bec 0%, #0056b3 100%) !important;
        color: #ffffff !important;
        border-bottom-right-radius: 4px !important;
        box-shadow: 0 4px 15px rgba(19, 91, 236, 0.15) !important;
    }
    .sv-bubble--them {
        background: var(--bg-card) !important;
        color: var(--text-main) !important;
        border-bottom-left-radius: 4px !important;
        border: 1px solid var(--border-color) !important;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.02) !important;
    }
    .sv-bubble__text {
        font-size: 14px !important;
        line-height: 1.5 !important;
    }
    .sv-bubble__time {
        font-size: 10px !important;
        opacity: 0.7 !important;
    }
    .sv-bubble__receipt {
        font-size: 12px !important;
    }

    /* Live Online Indicator Pulsing */
    @keyframes green-glow {
        0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.5); }
        70% { box-shadow: 0 0 0 8px rgba(34, 197, 94, 0); }
        100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
    }
    #onlineStatusDot {
        transition: all 0.3s ease;
    }
    #onlineStatusDot[style*="background: rgb(34, 197, 94)"],
    #onlineStatusDot[style*="background:#22c55e"],
    #onlineStatusDot[style*="background: rgb(16, 185, 129)"] {
        animation: green-glow 2s infinite !important;
    }

    /* Chat Messages Styled Scrollbar */
    #chatMessages::-webkit-scrollbar {
        width: 6px;
    }
    #chatMessages::-webkit-scrollbar-track {
        background: transparent;
    }
    #chatMessages::-webkit-scrollbar-thumb {
        background: rgba(0, 0, 0, 0.1);
        border-radius: 10px;
    }
    :root[data-theme="dark"] #chatMessages::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.1);
    }
    #chatMessages::-webkit-scrollbar-thumb:hover {
        background: rgba(0, 0, 0, 0.2);
    }

    /* Input actions animations */
    #chatInput {
        transition: all 0.2s ease;
    }
    #chatInput:focus {
        border-color: var(--primary) !important;
        box-shadow: 0 0 0 3px rgba(19, 91, 236, 0.12) !important;
        background: var(--bg-card) !important;
    }
    #chatSendBtn {
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 4px 12px rgba(19, 91, 236, 0.2);
    }
    #chatSendBtn:hover:not(:disabled) {
        transform: translateY(-1px);
        box-shadow: 0 6px 16px rgba(19, 91, 236, 0.3);
        background: #0056b3 !important;
    }
    #chatSendBtn:active:not(:disabled) {
        transform: translateY(1px);
    }

    @media (max-width: 991px) {
        .chat-layout {
            grid-template-columns: 1fr;
        }
        .chat-sidebar-panel {
            display: none;
            position: fixed;
            top: 70px;
            right: 0;
            bottom: 0;
            width: 310px;
            z-index: 1000;
            box-shadow: -4px 0 30px rgba(0,0,0,0.1);
            background: var(--bg-card);
            border-left: 1px solid var(--border-color);
        }
        .chat-sidebar-panel.active {
            display: flex;
        }
        #btnToggleDetails {
            display: flex !important;
        }
        .mobile-close-sidebar-btn {
            display: flex !important;
        }
    }

    @media (max-width: 600px) {
        .call-btn-text {
            display: none !important;
        }
        #btnAudioCall, #btnVideoCall {
            padding: 8px 10px !important;
            gap: 0 !important;
        }
        #chatMessages {
            padding: 12px !important;
        }
        .chat-input-container {
            padding: 12px !important;
        }
        #chatInput {
            padding: 10px 14px !important;
        }
        #callOverlay {
            flex-direction: column !important;
            justify-content: flex-start !important;
            padding: 80px 15px 110px 15px !important;
            gap: 15px !important;
            box-sizing: border-box !important;
        }
        #remoteVideo {
            position: relative !important;
            width: 100% !important;
            flex: 1 !important;
            height: auto !important;
            object-fit: cover !important;
            border-radius: 12px !important;
            inset: auto !important;
        }
        #localVideo {
            position: relative !important;
            width: 100% !important;
            flex: 1 !important;
            height: auto !important;
            bottom: auto !important;
            right: auto !important;
            object-fit: cover !important;
            border: 2px solid rgba(255, 255, 255, 0.5) !important;
            border-radius: 12px !important;
        }
    }
</style>

<div class="chat-layout">

    <!-- Left Main Chat Area -->
    <div class="chat-main-panel">
        <!-- Chat Header -->
        <div style="padding: 15px 25px; border-bottom: 1px solid var(--border-color); background: var(--bg-card); display: flex; justify-content: space-between; align-items: center;">
            <div style="display:flex; align-items:center; gap:16px;">
                <a href="shop-orders.php" style="display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:50%; background:var(--bg-body); border:1px solid var(--border-color); color:var(--text-main); text-decoration:none; transition:all 0.2s ease;"
                   onmouseover="this.style.background='var(--primary)';this.style.color='#fff';this.style.borderColor='var(--primary)'"
                   onmouseout="this.style.background='var(--bg-body)';this.style.color='var(--text-main)';this.style.borderColor='var(--border-color)'">
                    <span class="material-symbols-outlined" style="font-size:20px;">arrow_back</span>
                </a>
                <div>
                    <h3 style="margin:0; font-size:18px; font-weight:700;">Chat with <?= htmlspecialchars($customer_name) ?></h3>
                    <div style="display:flex; align-items:center; gap:5px;">
                        <span id="onlineStatusDot" style="width:8px; height:8px; border-radius:50%; background:#ccc; display:inline-block;"></span>
                        <span id="onlineStatusText" style="font-size:11px; color:var(--text-muted);">Checking status...</span>
                    </div>
                </div>
            </div>
            
            <div style="display:flex; gap:10px; align-items:center;">
                <!-- Details toggle button visible only on tablets/mobiles -->
                <button id="btnToggleDetails" onclick="toggleDetails()" style="display:none; background:#f4f6f9; border:1px solid var(--border-color); color:var(--text-main); padding:8px 14px; border-radius:8px; cursor:pointer; align-items:center; gap:6px; font-size:13px; font-weight:600; transition:0.2s;">
                    <span class="material-symbols-outlined" style="font-size:18px;">info</span> <span class="call-btn-text">Details</span>
                </button>
                <button id="btnAudioCall" onclick="startCall('audio')" style="background:#f4f6f9; border:1px solid var(--border-color); color:var(--text-main); padding:8px 14px; border-radius:8px; cursor:pointer; display:flex; align-items:center; gap:6px; font-size:13px; font-weight:600; transition:0.2s;">
                    <span class="material-symbols-outlined" style="font-size:18px;">call</span> <span class="call-btn-text">Audio</span>
                </button>
                <button id="btnVideoCall" onclick="startCall('video')" style="background:var(--primary); color:#fff; border:none; padding:8px 14px; border-radius:8px; cursor:pointer; display:flex; align-items:center; gap:6px; font-size:13px; font-weight:600; transition:0.2s;">
                    <span class="material-symbols-outlined" style="font-size:18px;">videocam</span> <span class="call-btn-text">Video</span>
                </button>
            </div>
        </div>

        <!-- WebRTC Call Overlay -->
        <div id="callOverlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.93); z-index:20000; flex-direction:column; align-items:center; justify-content:center;">
            <video id="remoteVideo" autoplay playsinline style="width:100%; height:100%; object-fit:cover; position:absolute; inset:0;"></video>
            <video id="localVideo" autoplay muted playsinline style="position:absolute; bottom:120px; right:20px; width:140px; height:180px; border-radius:14px; object-fit:cover; border:2px solid rgba(255,255,255,0.5); box-shadow:0 4px 24px rgba(0,0,0,0.5); z-index:1;"></video>
            <div id="callStatusText" style="position:absolute; top:20px; left:50%; transform:translateX(-50%); color:#fff; font-size:16px; font-weight:600; z-index:2; background:rgba(0,0,0,0.45); padding:8px 22px; border-radius:20px; white-space:nowrap;">Calling...</div>
            <div id="callDuration" style="position:absolute; top:64px; left:50%; transform:translateX(-50%); color:rgba(255,255,255,0.75); font-size:13px; z-index:2; display:none; font-variant-numeric:tabular-nums;">00:00</div>
            <div style="position:absolute; bottom:30px; left:50%; transform:translateX(-50%); display:flex; gap:18px; z-index:2;">
                <button id="btnMute" onclick="handleToggleMute()" title="Mute / Unmute" style="width:58px; height:58px; border-radius:50%; background:rgba(255,255,255,0.15); border:2px solid rgba(255,255,255,0.35); color:#fff; font-size:24px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.2s;">🎤</button>
                <button id="btnEndCall" onclick="endCall()" title="End Call" style="width:58px; height:58px; border-radius:50%; background:#ef4444; border:none; color:#fff; font-size:24px; cursor:pointer; display:flex; align-items:center; justify-content:center; box-shadow:0 4px 16px rgba(239,68,68,0.5);">📵</button>
                <button id="btnCamOff" onclick="handleToggleCamera()" title="Camera On/Off" style="width:58px; height:58px; border-radius:50%; background:rgba(255,255,255,0.15); border:2px solid rgba(255,255,255,0.35); color:#fff; font-size:24px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.2s;">📷</button>
            </div>
        </div>

        <!-- Messages Area -->
        <div id="chatMessages" style="flex:1; overflow-y:auto; padding:20px; display:flex; flex-direction:column; gap:15px; background:var(--bg-body);">
            <div data-empty="1" style="text-align:center; color:var(--text-muted); font-size:14px; margin-top:20px;">Loading conversation...</div>
        </div>

        <!-- Input Area -->
        <div class="chat-input-container" style="padding:20px; border-top:1px solid var(--border-color); background:var(--bg-card);">
            <div style="display:flex; gap:10px;">
                <input type="text" id="chatInput" placeholder="Type your message..."
                       style="flex:1; padding:12px 15px; border-radius:10px; border:1px solid var(--border-color); background:var(--bg-body); color:var(--text-main); outline:none; font-size:14px;"
                       onkeydown="if(event.key==='Enter'){ event.preventDefault(); sendChatMessage(); }">
                <button id="chatSendBtn" onclick="sendChatMessage()"
                        style="background:var(--primary); color:#fff; border:none; padding:0 25px; border-radius:10px; font-weight:600; cursor:pointer; font-size:14px; transition:0.2s;">
                    Send
                </button>
            </div>
        </div>
    </div>

    <!-- Right Sidebar Panel for Order Details & Stepper -->
    <div class="chat-sidebar-panel" id="chatSidebarPanel">
        <!-- Close Button (Mobile only) -->
        <button class="mobile-close-sidebar-btn" onclick="toggleDetails()" style="display:none; align-items:center; justify-content:center; gap:8px; width:100%; padding:12px; background:var(--primary); color:#fff; border:none; border-radius:10px; font-weight:700; font-size:14.5px; cursor:pointer; margin-top:15px; margin-bottom:15px;">
            <span class="material-symbols-outlined" style="font-size:18px;">arrow_back</span> Back to Chat
        </button>

        <!-- Live Status Tracker Stepper -->
        <div class="stepper">
            <h4 style="margin:0 0 5px 0; font-size:13px; font-weight:800; color:var(--text-main); text-transform:uppercase; letter-spacing:0.5px;">Live Updates</h4>
            
            <div class="stepper-step" id="step-assigned">
                <div class="stepper-icon">1</div>
                <div class="stepper-label">Assigned</div>
            </div>
            <div class="stepper-step" id="step-out_for_service">
                <div class="stepper-icon">2</div>
                <div class="stepper-label">On the Way</div>
            </div>
            <div class="stepper-step" id="step-reached">
                <div class="stepper-icon">3</div>
                <div class="stepper-label">Reached</div>
            </div>
            <div class="stepper-step" id="step-started">
                <div class="stepper-icon">4</div>
                <div class="stepper-label">Work Started</div>
            </div>
            <div class="stepper-step" id="step-completed">
                <div class="stepper-icon">5</div>
                <div class="stepper-label">Completed</div>
            </div>
        </div>

        <!-- Info Card -->
        <div class="info-card">
            <h4>Order Details</h4>
            
            <div class="info-row">
                <span class="info-label">Service</span>
                <span class="info-value"><?= htmlspecialchars($details['title']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Customer</span>
                <span class="info-value"><?= htmlspecialchars($details['customer']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Booking Date</span>
                <span class="info-value"><?= date('d M Y h:i A', strtotime($details['date'])) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Address</span>
                <span class="info-value">
                    <?= htmlspecialchars($details['address']) ?>
                    <a href="<?= $details['map_link'] ?>" target="_blank" style="color:var(--primary); font-weight:bold; text-decoration:none; display:block; margin-top:4px;">🗺️ Open in Maps</a>
                </span>
            </div>
        </div>

        <!-- Pricing Card -->
        <div class="info-card">
            <h4>Payment</h4>
            <div class="info-row" style="flex-direction:row; justify-content:space-between; align-items:center;">
                <span class="info-label">Your Earning</span>
                <span class="info-value" style="color:#10b981; font-weight:700; font-size:15px;">₹<?= number_format($details['earning']) ?></span>
            </div>
            <?php if ($details['collect'] > 0): ?>
                <div class="info-row" style="flex-direction:row; justify-content:space-between; align-items:center; margin-top:8px;">
                    <span class="info-label" style="color:#ef4444;">To Collect</span>
                    <span class="info-value" style="color:#ef4444; font-weight:700; font-size:15px;">₹<?= number_format($details['collect']) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Inclusions & Remarks -->
        <?php if (!empty($details['inclusions']) || !empty($details['remarks'])): ?>
            <div class="info-card">
                <h4>Inclusions & Notes</h4>
                <?php if (!empty($details['inclusions'])): ?>
                    <div class="info-row">
                        <span class="info-label">Inclusions</span>
                        <span class="info-value" style="white-space:pre-wrap;"><?= htmlspecialchars($details['inclusions']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($details['remarks'])): ?>
                    <div class="info-row" style="margin-top:10px;">
                        <span class="info-label" style="color:#ed8936;">Remarks</span>
                        <span class="info-value" style="white-space:pre-wrap; color:#ed8936;"><?= htmlspecialchars($details['remarks']) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
    // Toggle Details Sidebar (Responsive)
    function toggleDetails() {
        const sidebar = document.getElementById('chatSidebarPanel');
        sidebar.classList.toggle('active');
    }

    // ── Live Tracker Status Stepper Updates ───────────────────────────
    const steps = ['assigned', 'out_for_service', 'reached', 'started', 'completed'];

    function updateStepperUI(currentStatus) {
        let activeIndex = steps.indexOf(currentStatus);
        if (activeIndex === -1) {
            // Default to assigned if status is unknown or open
            activeIndex = 0;
        }

        steps.forEach((step, idx) => {
            const stepEl = document.getElementById('step-' + step);
            if (!stepEl) return;

            stepEl.classList.remove('active', 'completed');
            if (idx < activeIndex) {
                stepEl.classList.add('completed');
            } else if (idx === activeIndex) {
                stepEl.classList.add('active');
            }
        });
    }

    // Poll Order/Task status every 10 seconds
    function pollOrderStatus() {
        const isOfflineParam = <?= $is_offline ?> ? '&is_offline=1' : '';
        fetch(`order-chat.php?order_id=<?= $order_id ?>${isOfflineParam}&ajax_status=1`)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    updateStepperUI(data.status);
                }
            })
            .catch(e => console.warn('Failed to fetch status update:', e));
    }

    // Initialize Stepper status
    updateStepperUI('<?= $details['status'] ?>');
    setInterval(pollOrderStatus, 10000);

    // ── PHP-injected configuration ──────────────────────────────────────────
    const ORDER_ID    = <?= $order_id ?>;
    const VENDOR_ID   = <?= (int)$_SESSION['vendor_id'] ?>;
    const CUSTOMER_ID = <?= (int)$customer_id ?>;
    const VENDOR_NAME = '<?= $vendor_name ?>';
    const INCOMING_CALL_ID = <?= $call_id ?>; // 0 if no auto-accept
    const API_URL     = '../chat_api_proxy.php';
    const SIGNAL_URL  = '/webrtc_signal_proxy.php';

    // ── ChatEngine Setup ─────────────────────────────────────────────────────
    const chatEngine = new ChatEngine({
        apiUrl:      API_URL,
        <?= $is_offline ? "taskId: ORDER_ID," : "orderId: ORDER_ID," ?>
        myType:      'vendor',
        myId:        VENDOR_ID,
        targetId:    CUSTOMER_ID,
        targetType:  <?= $is_offline ?> ? 'admin' : 'user',
        onNewMessages(msgs) {
            const box = document.getElementById('chatMessages');
            // Remove the loading / empty placeholder
            const placeholder = box.querySelector('[data-empty]');
            if (placeholder) placeholder.remove();

            if (msgs.length === 0 && box.children.length === 0) {
                box.innerHTML = '<div data-empty="1" style="text-align:center;color:var(--text-muted);font-size:14px;margin-top:20px;">No messages yet. Start the conversation!</div>';
                return;
            }
            msgs.forEach(m => {
                box.appendChild(chatEngine.renderMessage(m, 'vendor'));
            });
            box.scrollTop = box.scrollHeight;
        },
        onStatusChange(isOnline, name) {
            document.getElementById('onlineStatusDot').style.background = isOnline ? '#22c55e' : '#ccc';
            document.getElementById('onlineStatusText').innerText = isOnline ? 'Online' : 'Offline';
        }
    });
    chatEngine.start();

    let _isSending = false;
    function sendChatMessage() {
        if (_isSending) return; // prevent double-fire
        const input = document.getElementById('chatInput');
        const btn   = document.getElementById('chatSendBtn');
        const msg = input.value.trim();
        if (!msg) return;

        // Check for phone numbers
        const digits = msg.replace(/[^0-9]/g, '');
        if (/\d{10,}/.test(digits)) {
            alert("Sharing phone numbers is not allowed. / फ़ोन नंबर साझा करने की अनुमति नहीं है।");
            return;
        }

        _isSending = true;
        input.value = '';
        if (btn) { btn.disabled = true; btn.textContent = '...'; }

        chatEngine.sendMessage(msg);

        setTimeout(() => {
            _isSending = false;
            if (btn) { btn.disabled = false; btn.textContent = 'Send'; }
        }, 1000); // 1s cooldown prevents accidental double-tap
    }

    // ── WebRTC Call Timer ────────────────────────────────────────────────────
    let callDurationTimer = null;
    let callSeconds = 0;

    function startDurationTimer() {
        callSeconds = 0;
        clearInterval(callDurationTimer);
        const durEl = document.getElementById('callDuration');
        durEl.style.display = 'block';
        callDurationTimer = setInterval(() => {
            callSeconds++;
            const m = String(Math.floor(callSeconds / 60)).padStart(2, '0');
            const s = String(callSeconds % 60).padStart(2, '0');
            durEl.textContent = m + ':' + s;
        }, 1000);
    }

    function stopDurationTimer() {
        clearInterval(callDurationTimer);
        const durEl = document.getElementById('callDuration');
        if (durEl) { durEl.style.display = 'none'; durEl.textContent = '00:00'; }
    }

    // Post call logs to database
    async function logVendorCallOutcome(messageText) {
        try {
            const fd = new FormData();
            fd.append('message', messageText);
            if (<?= $is_offline ?>) {
                fd.append('task_id', ORDER_ID);
            } else {
                fd.append('order_id', ORDER_ID);
            }
            fd.append('is_offline', <?= $is_offline ?> ? '1' : '0');

            await fetch('ajax/save_vendor_message.php', {
                method: 'POST',
                body: fd
            });
        } catch (e) {
            console.warn('Failed to log vendor call outcome:', e);
        }
    }

    // ── WebRTCClient Setup ───────────────────────────────────────────────────
    const webrtcClient = new WebRTCClient({
        signalApiUrl: SIGNAL_URL,
        orderId:      ORDER_ID,
        myType:       'vendor',
        myId:         VENDOR_ID,
        displayName:  VENDOR_NAME,

        onRemoteStream(stream) {
            document.getElementById('remoteVideo').srcObject = stream;
            document.getElementById('callStatusText').innerText = 'Connected';
            startDurationTimer();
        },
        onCallConnected() {
            document.getElementById('callStatusText').innerText = 'In Call';
        },
        onCallEnded(duration) {
            const isCallActive = (callSeconds > 0);
            const callTypeCached = webrtcClient.callType;
            const isCallerCached = webrtcClient.isCaller;
            hideCallOverlay();
            
            if (isCallerCached) {
                const icon = callTypeCached === 'video' ? '📹' : '📞';
                const typeText = callTypeCached === 'video' ? 'Video' : 'Voice';
                if (isCallActive) {
                    const minutes = String(Math.floor(callSeconds / 60)).padStart(2, '0');
                    const seconds = String(callSeconds % 60).padStart(2, '0');
                    logVendorCallOutcome(`${icon} ${typeText} call ended (Duration: ${minutes}:${seconds})`).then(() => {
                        chatEngine.loadMessages();
                    });
                } else {
                    logVendorCallOutcome(`📞 Cancelled/Missed ${typeText} Call`).then(() => {
                        chatEngine.loadMessages();
                    });
                }
            } else {
                chatEngine.loadMessages();
            }
        },
        onCallDeclined() {
            const isCallerCached = webrtcClient.isCaller;
            const callTypeCached = webrtcClient.callType;
            hideCallOverlay();
            alert('The customer declined the call.');
            if (isCallerCached) {
                const typeText = callTypeCached === 'video' ? 'Video' : 'Voice';
                logVendorCallOutcome(`📞 ${typeText} call declined`).then(() => {
                    chatEngine.loadMessages();
                });
            }
        },
        onIncomingCall(callData) {
            if (confirm('Incoming ' + callData.call_type + ' call from customer. Accept?')) {
                showCallOverlay();
                webrtcClient.handleIncomingCall(callData);
            } else {
                webrtcClient.declineCall(callData.id);
            }
        },
        onCallMissed() {
            const isCallerCached = webrtcClient.isCaller;
            const callTypeCached = webrtcClient.callType;
            hideCallOverlay();
            alert('No answer.');
            if (isCallerCached) {
                const typeText = callTypeCached === 'video' ? 'Video' : 'Voice';
                logVendorCallOutcome(`📞 ${typeText} call missed`).then(() => {
                    chatEngine.loadMessages();
                });
            }
        }
    });

    // ── Call Overlay Controls ────────────────────────────────────────────────
    function startCall(type) {
        const callUrl = `/vendor/call.php?order_id=${ORDER_ID}&action=dial&call_type=${type}`;
        window.open(callUrl, 'webrtc_call_window', 'width=1000,height=750,toolbar=no,menubar=no,location=no,status=no');
    }

    function endCall() {
        webrtcClient.endCall();
    }

    function handleToggleMute() {
        const muted = webrtcClient.toggleMute();
        document.getElementById('btnMute').textContent = muted ? '🔇' : '🎤';
    }

    // Toggle Camera function checks WebRTC version
    function handleToggleCamera() {
        const off = webrtcClient.toggleCamera();
        document.getElementById('btnCamOff').textContent = off ? '🚫' : '📷';
    }

    function showCallOverlay() {
        document.getElementById('callOverlay').style.display = 'flex';
    }

    function hideCallOverlay() {
        document.getElementById('callOverlay').style.display = 'none';
        document.getElementById('remoteVideo').srcObject = null;
        document.getElementById('localVideo').srcObject = null;
        document.getElementById('btnMute').textContent = '🎤';
        document.getElementById('btnCamOff').textContent = '📷';
        stopDurationTimer();
    }

    // Auto-accept incoming call if call_id is in URL
    if (INCOMING_CALL_ID > 0) {
        setTimeout(async () => {
            try {
                const fd = new FormData();
                fd.append('action', 'poll_signal');
                fd.append('order_id', ORDER_ID);
                fd.append('call_session_id', INCOMING_CALL_ID);
                fd.append('vendor_id', VENDOR_ID);
                const res = await svFetch(SIGNAL_URL, fd);
                if (res.success && res.call_status === 'ringing') {
                    const callData = {
                        id:        INCOMING_CALL_ID,
                        call_type: 'audio',
                        sdp_offer: null
                    };
                    const fd2 = new FormData();
                    fd2.append('action', 'get_call_history');
                    fd2.append('order_id', ORDER_ID);
                    fd2.append('vendor_id', VENDOR_ID);
                    const hist = await svFetch(SIGNAL_URL, fd2);
                    if (hist.success) {
                        const activeCall = hist.calls?.find(c => c.id == INCOMING_CALL_ID);
                        if (activeCall) {
                            callData.call_type = activeCall.call_type;
                        }
                    }
                    showCallOverlay();
                    await webrtcClient.handleIncomingCall(callData);
                }
            } catch (e) {
                console.warn('Auto-accept failed:', e);
            }
        }, 800);
    }
</script>

<?php include __DIR__ . '/footer.php'; ?>
