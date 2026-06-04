<?php
// vendor/call.php
if (file_exists(__DIR__ . '/includes/session_manager.php')) {
    require_once __DIR__ . '/includes/session_manager.php';
} else {
    session_start();
}
if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    echo "Unauthorized";
    exit;
}

require_once '../db.php';
require_once '../db_main.php';

$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
$call_id = isset($_GET['call_id']) ? intval($_GET['call_id']) : 0;
$action = isset($_GET['action']) ? $_GET['action'] : 'dial'; // 'dial' or 'answer'
$call_type = isset($_GET['call_type']) ? $_GET['call_type'] : 'audio';

if ($order_id <= 0) {
    echo "Invalid Order ID";
    exit;
}

$vendor_id = intval($_SESSION['vendor_id']);
$vendor_name = $_SESSION['vendor_name'] ?? 'Vendor';

// Auto-detect if it's offline (manual task) or shop order
$is_offline = 0;
$stmt = $conn->prepare("SELECT id FROM manual_tasks WHERE id = ? AND assigned_vendor_id = ?");
if ($stmt) {
    $stmt->bind_param("ii", $order_id, $vendor_id);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $is_offline = 1;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Call Room — surpriseville.co.in</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="/assets/js/webrtc_client.js"></script>
    <style>
        :root {
            --bg-dark: #0f172a;
            --card-dark: #1e293b;
            --primary: #4f46e5;
            --danger: #ef4444;
            --success: #10b981;
            --text-light: #f8fafc;
            --text-muted: #94a3b8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background-color: var(--bg-dark);
            font-family: 'Inter', sans-serif;
            color: var(--text-light);
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Video Container Grid */
        .call-container {
            flex: 1;
            position: relative;
            background: #0b0f19 url('../surpriseville-logo.png') no-repeat center;
            background-size: 240px auto;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Remote Video Frame (Full Screen background) */
        .remote-video-wrapper {
            width: 100%;
            height: 100%;
            position: absolute;
            top: 0;
            left: 0;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #remoteVideo {
            width: 100%;
            height: 100%;
            object-fit: cover;
            background: #000;
        }

        /* Local Video PIP Frame */
        .local-video-wrapper {
            position: absolute;
            bottom: 100px;
            right: 20px;
            width: 180px;
            height: 240px;
            border-radius: 16px;
            overflow: hidden;
            border: 2px solid rgba(255, 255, 255, 0.2);
            z-index: 10;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
            background: #111;
            transition: all 0.3s ease;
        }

        #localVideo {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transform: scaleX(-1); /* mirror preview */
        }

        /* Calling / Connecting Overlay */
        .calling-state-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 5;
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(15px);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .avatar-circle {
            width: 120px;
            height: 120px;
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 24px;
            position: relative;
        }

        .avatar-circle i {
            font-size: 48px;
            color: #fff;
        }

        .pulse-ring {
            position: absolute;
            width: 100%;
            height: 100%;
            border: 2px solid var(--primary);
            border-radius: 50%;
            animation: pulseOuter 2s infinite;
        }

        .caller-title {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .call-status {
            font-size: 16px;
            color: var(--text-muted);
            font-weight: 600;
            margin-bottom: 30px;
        }

        /* Calling Duration HUD */
        .duration-hud {
            position: absolute;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(8px);
            padding: 8px 18px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 14px;
            letter-spacing: 1px;
            z-index: 10;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .red-dot {
            width: 8px;
            height: 8px;
            background: var(--danger);
            border-radius: 50%;
            animation: blink 1s infinite alternate;
        }

        /* Controls Bottom Bar */
        .controls-bar {
            height: 90px;
            background: rgba(15, 23, 42, 0.95);
            backdrop-filter: blur(10px);
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            z-index: 20;
            padding: 0 20px;
        }

        .btn-control {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            border: none;
            color: #fff;
            font-size: 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            outline: none;
            background: rgba(255, 255, 255, 0.1);
        }

        .btn-control:hover {
            transform: scale(1.1);
            background: rgba(255, 255, 255, 0.2);
        }

        .btn-control.active {
            background: var(--danger);
        }

        .btn-hangup {
            background: var(--danger);
            width: 60px;
            height: 60px;
            font-size: 24px;
        }

        .btn-hangup:hover {
            background: #dc2626;
        }

        /* Animations */
        @keyframes pulseOuter {
            0% { transform: scale(1); opacity: 0.8; }
            100% { transform: scale(1.6); opacity: 0; }
        }

        @keyframes blink {
            from { opacity: 0.3; }
            to { opacity: 1; }
        }
    </style>
</head>
<body>

    <!-- Call Container (Streams & Ringing Hud) -->
    <div class="call-container">
        
        <!-- Call Duration HUD -->
        <div class="duration-hud" id="hudDuration" style="display: none;">
            <div class="red-dot"></div>
            <span id="txtDuration">00:00</span>
        </div>

        <!-- Local Video PIP -->
        <div class="local-video-wrapper" id="localVideoContainer" style="display: none;">
            <video id="localVideo" autoplay playsinline muted></video>
        </div>

        <!-- Remote Video Stream -->
        <div class="remote-video-wrapper">
            <video id="remoteVideo" autoplay playsinline></video>
        </div>

        <!-- Connecting / Calling HUD -->
        <div class="calling-state-overlay" id="connectingOverlay">
            <div class="avatar-circle">
                <div class="pulse-ring"></div>
                <i class="fa-solid fa-user-shield"></i>
            </div>
            <div class="caller-title">Admin (Support)</div>
            <div class="call-status" id="txtStatus">Connecting to signaling room...</div>
        </div>

    </div>

    <!-- Controls Bottom Bar -->
    <div class="controls-bar">
        <button class="btn-control" id="btnMute" title="Mute Microphone">
            <i class="fa-solid fa-microphone"></i>
        </button>
        
        <?php if ($call_type === 'video'): ?>
        <button class="btn-control" id="btnCamOff" title="Turn Camera Off">
            <i class="fa-solid fa-video"></i>
        </button>
        <?php endif; ?>

        <button class="btn-control" id="btnPip" title="Minimize / Picture-in-Picture">
            <i class="fa-solid fa-compress"></i>
        </button>

        <button class="btn-control btn-hangup" id="btnHangup" title="End Call">
            <i class="fa-solid fa-phone-slash"></i>
        </button>
    </div>

    <script>
        const ORDER_ID = <?= $order_id ?>;
        const ACTION = '<?= $action ?>';
        const CALL_TYPE = '<?= $call_type ?>';
        const INCOMING_CALL_ID = <?= $call_id ?>;
        const IS_OFFLINE = <?= $is_offline ?>;
        const VENDOR_ID = <?= $vendor_id ?>;
        const VENDOR_NAME = '<?= addslashes($vendor_name) ?>';

        let callSeconds = 0;
        let callTimerInterval = null;
        let hasConnected = false;

        function startTimer() {
            callSeconds = 0;
            document.getElementById('hudDuration').style.display = 'flex';
            clearInterval(callTimerInterval);
            callTimerInterval = setInterval(() => {
                callSeconds++;
                const m = String(Math.floor(callSeconds / 60)).padStart(2, '0');
                const s = String(callSeconds % 60).padStart(2, '0');
                document.getElementById('txtDuration').innerText = m + ':' + s;
            }, 1000);
        }

        function stopTimer() {
            clearInterval(callTimerInterval);
            document.getElementById('hudDuration').style.display = 'none';
        }

        // Post chat log database callback
        async function logCallOutcome(messageText) {
            try {
                const fd = new FormData();
                fd.append('message', messageText);
                if (IS_OFFLINE) {
                    fd.append('task_id', ORDER_ID);
                } else {
                    fd.append('order_id', ORDER_ID);
                }
                fd.append('is_offline', IS_OFFLINE ? '1' : '0');

                // Call save_vendor_message.php
                await fetch('ajax/save_vendor_message.php', {
                    method: 'POST',
                    body: fd
                });
            } catch (e) {
                console.warn('Failed to log call outcome:', e);
            }
        }

        function closeCallWindow() {
            window.close();
            setTimeout(() => {
                window.location.href = '/vendor/order-chat.php?order_id=' + ORDER_ID + '&is_offline=' + (IS_OFFLINE ? '1' : '0');
            }, 500);
        }

        // Setup WebRTCClient
        const client = new WebRTCClient({
            signalApiUrl: '/webrtc_signal_proxy.php',
            orderId:      ORDER_ID,
            myType:       'vendor',
            myId:         VENDOR_ID,
            displayName:  VENDOR_NAME,

            onRemoteStream(stream) {
                document.getElementById('remoteVideo').srcObject = stream;
                document.getElementById('connectingOverlay').style.display = 'none';
                if (CALL_TYPE === 'video') {
                    document.getElementById('localVideoContainer').style.display = 'block';
                }
                hasConnected = true;
                startTimer();
            },
            onCallConnected() {
                document.getElementById('txtStatus').innerText = 'Call connected';
            },
            onCallEnded(duration) {
                stopTimer();
                const icon = CALL_TYPE === 'video' ? '📹' : '📞';
                const typeText = CALL_TYPE === 'video' ? 'Video' : 'Voice';
                if (hasConnected) {
                    const minutes = String(Math.floor(duration / 60)).padStart(2, '0');
                    const seconds = String(duration % 60).padStart(2, '0');
                    logCallOutcome(`${icon} ${typeText} call ended (Duration: ${minutes}:${seconds})`).then(() => {
                        closeCallWindow();
                    });
                } else {
                    logCallOutcome(`📞 Cancelled/Missed ${typeText} Call`).then(() => {
                        closeCallWindow();
                    });
                }
            },
            onCallDeclined() {
                stopTimer();
                const typeText = CALL_TYPE === 'video' ? 'Video' : 'Voice';
                logCallOutcome(`📞 ${typeText} call declined`).then(() => {
                    alert('Call declined by Admin.');
                    closeCallWindow();
                });
            },
            onCallMissed() {
                stopTimer();
                const typeText = CALL_TYPE === 'video' ? 'Video' : 'Voice';
                logCallOutcome(`📞 ${typeText} call missed`).then(() => {
                    alert('No response from Admin.');
                    closeCallWindow();
                });
            }
        });

        // Initialize Call Session
        async function initCall() {
            // Trigger pre-check to create call room in production database
            fetch('ajax/ensure_webrtc_order.php?id=' + ORDER_ID).catch(e => {
                console.warn('Call room pre-check failed:', e);
            });

            if (ACTION === 'answer' && INCOMING_CALL_ID > 0) {
                // Answering an incoming call
                document.getElementById('txtStatus').innerText = 'Answering call...';
                const callData = {
                    id: INCOMING_CALL_ID,
                    call_type: CALL_TYPE,
                    sdp_offer: null
                };

                // Retrieve the caller SDP offer by polling
                try {
                    const fd = new FormData();
                    fd.append('action', 'poll_signal');
                    fd.append('order_id', ORDER_ID);
                    fd.append('call_session_id', INCOMING_CALL_ID);
                    fd.append('vendor_id', VENDOR_ID);
                    const res = await svFetch('/webrtc_signal_proxy.php', fd);
                    if (res.success) {
                        if (res.sdp_offer) {
                            callData.sdp_offer = res.sdp_offer;
                        } else if (res.signals) {
                            const offerSignal = res.signals.find(s => s.signal_type === 'offer' || s.type === 'offer');
                            if (offerSignal) {
                                callData.sdp_offer = offerSignal.payload;
                            }
                        }
                    }
                } catch (e) {
                    console.warn('Failed to retrieve offer signal:', e);
                }

                if (!callData.sdp_offer) {
                    alert('Call offer not found. The call might have ended.');
                    closeCallWindow();
                    return;
                }

                client.handleIncomingCall(callData).catch(err => {
                    alert('Could not answer call: ' + err.message);
                    closeCallWindow();
                });
            } else {
                // Dialing out
                document.getElementById('txtStatus').innerText = 'Calling Admin...';
                client.initiateCall(CALL_TYPE).catch(err => {
                    alert('Could not initiate call: ' + err.message);
                    closeCallWindow();
                });
            }
        }

        // Control Buttons Event Handlers
        document.getElementById('btnMute').onclick = function() {
            const isMuted = client.toggleMute();
            this.classList.toggle('active', isMuted);
            this.innerHTML = isMuted ? '<i class="fa-solid fa-microphone-slash"></i>' : '<i class="fa-solid fa-microphone"></i>';
        };

        const btnCam = document.getElementById('btnCamOff');
        if (btnCam) {
            btnCam.onclick = function() {
                const isCamOff = client.toggleCamera();
                this.classList.toggle('active', isCamOff);
                this.innerHTML = isCamOff ? '<i class="fa-solid fa-video-slash"></i>' : '<i class="fa-solid fa-video"></i>';
            };
        }

        document.getElementById('btnHangup').onclick = function() {
            client.endCall();
        };

        // Picture-in-Picture Minimization Click Handler
        const btnPip = document.getElementById('btnPip');
        const remoteVideo = document.getElementById('remoteVideo');
        if (btnPip && remoteVideo) {
            btnPip.onclick = async () => {
                try {
                    if (document.pictureInPictureElement) {
                        await document.exitPictureInPicture();
                    } else if (document.pictureInPictureEnabled) {
                        await remoteVideo.requestPictureInPicture();
                    } else {
                        alert("Picture-in-Picture is not supported on this browser/device.");
                    }
                } catch (e) {
                    console.error("PiP toggle error:", e);
                }
            };
            remoteVideo.addEventListener('enterpictureinpicture', () => {
                btnPip.classList.add('active');
                btnPip.innerHTML = '<i class="fa-solid fa-expand"></i>';
            });
            remoteVideo.addEventListener('leavepictureinpicture', () => {
                btnPip.classList.remove('active');
                btnPip.innerHTML = '<i class="fa-solid fa-compress"></i>';
            });
        }

        // Initialize Call room on window load
        window.onload = initCall;
    </script>
</body>
</html>
