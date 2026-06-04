<?php
// admin/track_vendor.php
session_start();
require_once '../db.php';

// Check Admin Login (Optional but recommended)
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

$vid = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fetch Vendor Location
$stmt = $conn->prepare("SELECT name, business_name, latitude, longitude, last_location_update FROM vendors WHERE id = ?");
$stmt->bind_param("i", $vid);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();

// Agar location nahi mili
if (!$vendor || !$vendor['latitude']) {
    die("
    <div style='height:100vh; display:flex; align-items:center; justify-content:center; background:#f8fafc; font-family:Inter, sans-serif; text-align:center;'>
        <div style='background:white; padding:40px; border-radius:24px; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #e2e8f0; max-width:400px;'>
            <div style='width:60px; height:60px; background:#fee2e2; color:#ef4444; border-radius:16px; display:flex; align-items:center; justify-content:center; margin:0 auto 20px; font-size:1.5rem;'>
                <i class='fa-solid fa-location-slash'></i>
            </div>
            <h2 style='margin:0 0 10px; font-weight:800;'>Protocol Offline</h2>
            <p style='color:#64748b; font-weight:600; line-height:1.5;'>Location telemetry data not found for this partner. Verify that they have granted GPS permissions.</p>
            <button onclick='window.close()' style='margin-top:20px; padding:12px 24px; background:#1e293b; color:white; border:none; border-radius:10px; font-weight:700; cursor:pointer;'>Terminate Session</button>
        </div>
    </div>
    <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'>
    ");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Geo-Intelligence Terminal | <?= htmlspecialchars($vendor['name']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --dark: #1e293b;
            --success: #10b981;
            --glass: rgba(255, 255, 255, 0.9);
        }

        body { margin: 0; padding: 0; font-family: 'Inter', sans-serif; overflow: hidden; }
        #map { height: 100vh; width: 100%; z-index: 1; }

        .telemetry-card {
            position: absolute;
            top: 20px;
            left: 20px;
            z-index: 1000;
            background: var(--glass);
            backdrop-filter: blur(12px);
            padding: 25px;
            border-radius: 24px;
            border: 1px solid rgba(255,255,255,0.4);
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            width: 320px;
            animation: slideDown 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes slideDown {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #ecfdf5;
            color: #065f46;
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 0.7rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 15px;
        }

        .partner-name {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--dark);
            margin: 0 0 5px;
            letter-spacing: -0.5px;
        }

        .telemetry-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-top: 20px;
        }

        .telemetry-icon {
            width: 36px;
            height: 36px;
            background: #f1f5f9;
            color: #64748b;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .telemetry-content { flex: 1; }
        .telemetry-label { font-size: 0.65rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; margin-bottom: 2px; }
        .telemetry-value { font-size: 0.9rem; font-weight: 700; color: var(--dark); }

        .btn-refresh {
            width: 100%;
            margin-top: 25px;
            padding: 14px;
            background: var(--dark);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s;
        }

        .btn-refresh:hover { background: #0f172a; transform: translateY(-2px); }

        .pulse {
            width: 8px;
            height: 8px;
            background: var(--success);
            border-radius: 50%;
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        /* Custom Map Marker */
        .custom-marker {
            width: 40px;
            height: 40px;
            background: var(--primary);
            border: 4px solid white;
            border-radius: 50% 50% 50% 0;
            transform: rotate(-45deg);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 10px rgba(0,0,0,0.3);
        }
        .custom-marker i { transform: rotate(45deg); color: white; font-size: 1.2rem; }
    </style>
</head>
<body>

    <div class="telemetry-card">
        <div class="status-badge">
            <div class="pulse"></div>
            Live Monitoring Active
        </div>
        
        <h3 class="partner-name"><?= htmlspecialchars($vendor['business_name'] ?: $vendor['name']) ?></h3>
        <p style="margin:0; font-size:0.8rem; color:#64748b; font-weight:600;">Deployment Unit ID: PV-<?= str_pad($vid, 4, '0', STR_PAD_LEFT) ?></p>

        <div class="telemetry-item">
            <div class="telemetry-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
            <div class="telemetry-content">
                <div class="telemetry-label">Signal Recieved</div>
                <div class="telemetry-value"><?= date('d M, h:i:s A', strtotime($vendor['last_location_update'])) ?></div>
            </div>
        </div>

        <div class="telemetry-item">
            <div class="telemetry-icon"><i class="fa-solid fa-location-crosshairs"></i></div>
            <div class="telemetry-content">
                <div class="telemetry-label">Coordinate Matrix</div>
                <div class="telemetry-value" style="font-family: monospace; letter-spacing: 0.5px;"><?= $vendor['latitude'] ?>, <?= $vendor['longitude'] ?></div>
            </div>
        </div>

        <button onclick="location.reload()" class="btn-refresh">
            <i class="fa-solid fa-arrows-rotate"></i> Resync Telemetry
        </button>
    </div>

    <div id="map"></div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // Initialize Map
        var lat = <?= $vendor['latitude'] ?>;
        var lng = <?= $vendor['longitude'] ?>;
        
        var map = L.map('map', {
            zoomControl: false
        }).setView([lat, lng], 16);

        // Add Zoom Control to Bottom Right
        L.control.zoom({ position: 'bottomright' }).addTo(map);

        // Load Modern Gray-scale-ish Tiles (or default OSM)
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© Surprise Ville Intelligence'
        }).addTo(map);

        // Custom Marker Icon
        var customIcon = L.divIcon({
            className: 'custom-marker-container',
            html: '<div class="custom-marker"><i class="fa-solid fa-user-ninja"></i></div>',
            iconSize: [40, 40],
            iconAnchor: [20, 40]
        });

        // Add Marker
        var marker = L.marker([lat, lng], { icon: customIcon }).addTo(map)
            .bindPopup('<div style="font-family:Inter; font-weight:800; padding:5px;"><?= htmlspecialchars($vendor['name']) ?> is active here.</div>');

        // Optional: Auto Refresh every 45 seconds to avoid heavy load
        setTimeout(function(){ location.reload(); }, 45000);
    </script>

</body>
</html>