<?php
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';

$msg = "";

// 1. Handle Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['allocation_mode'])) {
    $mode = $_POST['allocation_mode'] === 'manual' ? 'manual' : 'auto';

    $stmt = $conn->prepare("UPDATE settings SET value = ? WHERE `key` = 'order_allocation_mode'");
    $stmt->bind_param("s", $mode);
    if ($stmt->execute()) {
        $msg = "<div class='alert success'>Allocation mode updated to <b>" . strtoupper($mode) . "</b></div>";
    } else {
        $msg = "<div class='alert error'>Failed to update settings.</div>";
    }
}

// 2. Fetch Current Mode
$current_mode = 'auto';
$res = $conn->query("SELECT value FROM settings WHERE `key` = 'order_allocation_mode' LIMIT 1");
if ($res && $row = $res->fetch_assoc()) {
    $current_mode = $row['value'];
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings | Surprise Ville</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --success: #10b981;
            --danger: #ef4444;
            --border: #e2e8f0;
            --dark: #1e293b;
        }

        body {
            background-color: #f1f5f9;
            font-family: 'Inter', sans-serif;
            color: var(--dark);
        }

        .header {
            background: #fff;
            border-bottom: 1px solid var(--border);
            padding: 15px 30px;
        }

        .main-content {
            padding: 30px;
        }

        .settings-card {
            background: #fff;
            padding: 35px;
            border-radius: 20px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border);
            max-width: 700px;
        }

        .toggle-box {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 25px;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-top: 25px;
            transition: all 0.3s;
        }

        .toggle-box:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }

        .mode-indicator {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
        }

        .mode-auto {
            background: #dcfce7;
            color: #166534;
        }

        .mode-manual {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Modern Switch */
        .switch {
            position: relative;
            display: inline-block;
            width: 64px;
            height: 32px;
            flex-shrink: 0;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #cbd5e1;
            transition: .4s;
            border-radius: 32px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 24px;
            width: 24px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        input:checked+.slider {
            background-color: var(--primary);
        }

        input:checked+.slider:before {
            transform: translateX(32px);
        }

        .info-pill {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }

        .pill {
            flex: 1;
            padding: 15px;
            border-radius: 12px;
            background: #fff;
            border: 1px solid var(--border);
        }

        .btn-save {
            background: var(--dark);
            color: white;
            padding: 15px;
            border-radius: 12px;
            border: none;
            font-weight: 700;
            width: 100%;
            margin-top: 30px;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .btn-save:hover {
            transform: translateY(-2px);
            background: #0f172a;
        }
    </style>
</head>

<body>
    <div class="header">
        <h1 style="margin: 0; font-size: 1.5rem; font-weight: 800;">Settings</h1>
        <nav style="display: flex; align-items: center; gap: 20px;">
            <span style="font-weight: 600; color: #64748b;"><i class="fa-solid fa-user-shield"></i> Welcome Admin</span>
            <a href="logout.php" style="color: var(--danger); font-weight: 700; text-decoration: none;"><i class="fa-solid fa-power-off"></i> Logout</a>
        </nav>
    </div>

    <div class="container">
        <div class="dashboard-layout">
            <?php include 'sidebar_fragment.php'; ?>
            <main class="main-content">

                <?php if ($msg) echo $msg; ?>

                <div class="settings-card">
                    <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 10px;">
                        <div style="width: 45px; height: 45px; background: #eff6ff; color: var(--primary); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                            <i class="fa-solid fa-sliders"></i>
                        </div>
                        <div>
                            <h2 style="margin: 0; font-weight: 800;">Order Assignment</h2>
                            <p style="margin: 0; color: #64748b; font-size: 0.9rem;">How orders are given to vendors</p>
                        </div>
                    </div>

                    <form method="POST">
                        <div class="toggle-box">
                            <div>
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                                    <h3 style="margin: 0; font-weight: 700; font-size: 1.1rem;">Order Assignment Mode</h3>
                                    <span class="mode-indicator mode-<?= $current_mode ?>">
                                        <?= strtoupper($current_mode) ?>
                                    </span>
                                </div>
                                <p style="margin: 0; color: #64748b; font-size: 0.9rem; line-height: 1.5;">
                                    Choose if orders go to vendors automatically or if you want to assign them manually.
                                </p>
                            </div>
                            <label class="switch">
                                <input type="hidden" name="allocation_mode" value="auto">
                                <input type="checkbox" name="allocation_mode" value="manual" onchange="this.form.submit()" <?= $current_mode == 'manual' ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>

                        <div class="info-pill">
                            <div class="pill">
                                <div style="color: #10b981; font-weight: 800; font-size: 0.75rem; text-transform: uppercase; margin-bottom: 5px;">Automatic (Fast)</div>
                                <div style="font-size: 0.8rem; color: #64748b; line-height: 1.4;">
                                    Sends order to 5 vendors at once. Quickest way to finish work.
                                </div>
                            </div>
                            <div class="pill">
                                <div style="color: #ef4444; font-weight: 800; font-size: 0.75rem; text-transform: uppercase; margin-bottom: 5px;">Manual (Control)</div>
                                <div style="font-size: 0.8rem; color: #64748b; line-height: 1.4;">
                                    No notifications sent automatically. You choose which vendor gets the order.
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn-save">
                            <i class="fa-solid fa-check-circle"></i> Save Settings
                        </button>
                    </form>
                </div>
            </main>
        </div>
    </div>
</body>

</html>