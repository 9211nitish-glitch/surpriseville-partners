<?php
session_start();
// admin/gig_categories.php

// Check Admin Login
if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

require_once "../db.php";

$msg = "";
$err = "";

// --- ADD CATEGORY ---
if (isset($_POST['add_cat'])) {
    $name = trim($_POST['name']);
    $type = $_POST['type'];

    if (!empty($name) && !empty($type)) {
        $stmt = $conn->prepare("INSERT INTO gig_categories (name, type) VALUES (?, ?)");
        $stmt->bind_param("ss", $name, $type);
        if ($stmt->execute()) {
            $msg = "Category Added!";
        } else {
            $err = "Error: " . $conn->error;
        }
    } else {
        $err = "Please fill all fields.";
    }
}

// --- DELETE CATEGORY ---
if (isset($_GET['del'])) {
    $id = intval($_GET['del']);
    $conn->query("DELETE FROM gig_categories WHERE id=$id");
    header("Location: gig_categories.php");
    exit;
}

// --- FETCH ALL ---
$cats = $conn->query("SELECT * FROM gig_categories ORDER BY type ASC, name ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gig Categories | Surprise Ville</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --glass: rgba(255, 255, 255, 0.7);
            --glass-border: rgba(255, 255, 255, 0.4);
            --shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%); 
            font-family: 'Outfit', sans-serif; 
            color: #1e293b;
            min-height: 100vh;
        }

        .header {
            background: var(--glass);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--glass-border);
            padding: 1.25rem 2.5rem;
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .dashboard-container {
            display: flex;
            gap: 2rem;
            padding: 2.5rem;
            max-width: 1400px;
            margin: 0 auto;
        }

        .main-content { flex: 1; min-width: 0; }

        .premium-card {
            background: var(--glass);
            backdrop-filter: blur(12px);
            border-radius: 32px;
            border: 1px solid var(--glass-border);
            padding: 2.5rem;
            box-shadow: var(--shadow);
            margin-bottom: 2.5rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 1.5rem;
            align-items: flex-end;
        }

        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-size: 0.65rem;
            font-weight: 800;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .protocol-input {
            width: 100%;
            padding: 1.1rem;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            font-family: inherit;
            font-weight: 600;
            background: white;
            outline: none;
            transition: all 0.2s;
        }
        .protocol-input:focus { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1); }

        .btn-add {
            background: var(--primary);
            color: white;
            border: none;
            padding: 1.1rem 2rem;
            border-radius: 16px;
            font-weight: 800;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
        }
        .btn-add:hover { transform: translateY(-2px); box-shadow: 0 8px 15px rgba(79, 70, 229, 0.3); }

        .table-responsive { overflow-x: auto; border-radius: 24px; }
        .modern-table { width: 100%; border-collapse: collapse; }
        .modern-table th {
            background: rgba(248, 250, 252, 0.5);
            padding: 1.25rem 1.5rem;
            text-align: left;
            font-size: 0.7rem;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 1px solid var(--glass-border);
        }
        .modern-table td { 
            padding: 1.25rem 1.5rem; 
            border-bottom: 1px solid rgba(226, 232, 240, 0.5);
            vertical-align: middle;
        }

        .status-badge {
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .badge-activity { background: #e0f2fe; color: #0369a1; }
        .badge-decoration { background: #fef2f2; color: #991b1b; }

        .btn-delete {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #fee2e2;
            background: white;
            color: var(--danger);
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-delete:hover { background: var(--danger); color: white; border-color: var(--danger); transform: scale(1.1); }

        .sidebar-toggle {
            display: flex;
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 10px;
            align-items: center;
            justify-content: center;
            border: 1px solid #e2e8f0;
            color: var(--primary);
            cursor: pointer;
            font-size: 1.2rem;
            transition: all 0.2s;
        }

        @media (max-width: 1024px) {
            .dashboard-container { flex-direction: column; padding: 1.5rem; gap: 1rem; }
            .header { padding: 1rem 1.5rem; }
            .form-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <header class="header">
        <div style="display: flex; align-items: center; gap: 15px;">
            <div class="sidebar-toggle" onclick="toggleSidebar()">
                <i class="fa-solid fa-bars"></i>
            </div>
            <div style="background: var(--primary); color: white; width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                <i class="fa-solid fa-layer-group"></i>
            </div>
            <h1 style="margin: 0; font-size: 1.4rem; font-weight: 800; letter-spacing: -0.5px;">Task Categories</h1>
        </div>
        <nav style="display: flex; align-items: center; gap: 20px;">
            <span style="font-weight: 700; color: #64748b; font-size: 0.85rem;"><i class="fa-solid fa-user-shield"></i> Welcome Admin</span>
        </nav>
    </header>

    <div class="dashboard-container">
        <?php include 'sidebar_fragment.php'; ?>
        <main class="main-content">
            <div style="margin-bottom: 2.5rem;">
                <h2 style="margin: 0; font-weight: 800; font-size: 1.8rem; letter-spacing: -0.5px;">Manage Task Categories</h2>
                <p style="color: #64748b; font-weight: 600; margin-top: 5px;">Add or remove task categories for offline orders</p>
            </div>

            <?php if ($msg): ?>
                <div style="background: #ecfdf5; color: #065f46; padding: 1.25rem; border-radius: 20px; border: 1px solid #6ee7b7; margin-bottom: 2rem; font-weight: 700; display: flex; align-items: center; gap: 12px;">
                    <i class="fa-solid fa-circle-check" style="font-size: 1.2rem;"></i> <?= $msg ?>
                </div>
            <?php endif; ?>

            <div class="premium-card">
                <h4 style="margin-top: 0; margin-bottom: 1.5rem; font-weight: 800; color: var(--primary); font-size: 1.1rem; letter-spacing: -0.5px;">Add New Category</h4>
                <form method="POST" class="form-grid">
                    <div class="form-group">
                        <label>Category Name</label>
                        <input type="text" name="name" class="protocol-input" placeholder="e.g. Magician, Tattoo Artist" required>
                    </div>

                    <div class="form-group">
                        <label>Role Type</label>
                        <select name="type" class="protocol-input" required>
                            <option value="activity">Activity (Person/Artist)</option>
                            <option value="decoration">Decoration (Setup/Item)</option>
                        </select>
                    </div>

                    <button type="submit" name="add_cat" class="btn-add">
                        <i class="fa-solid fa-plus"></i> Add
                    </button>
                </form>
            </div>

            <div class="premium-card" style="padding: 0;">
                <div class="table-responsive">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th style="width: 100px;">ID</th>
                                <th>Category Name</th>
                                <th>Type</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($cats->num_rows > 0): ?>
                                <?php while ($row = $cats->fetch_assoc()): ?>
                                    <tr>
                                        <td style="color: #94a3b8; font-weight: 800;">#<?= $row['id'] ?></td>
                                        <td>
                                            <div style="font-weight: 800; color: #0f172a; font-size: 1.1rem;"><?= htmlspecialchars($row['name']) ?></div>
                                        </td>
                                        <td>
                                            <span class="status-badge badge-<?= $row['type'] ?>">
                                                <?= ucfirst($row['type']) ?>
                                            </span>
                                        </td>
                                        <td style="text-align: right;">
                                            <div style="display: flex; justify-content: flex-end;">
                                                <a href="?del=<?= $row['id'] ?>" onclick="return confirm('Delete this category?')" class="btn-delete" title="Delete">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; padding: 100px; color: #94a3b8;">
                                        <i class="fa-solid fa-layer-group fa-3x" style="margin-bottom: 20px; opacity: 0.5;"></i><br>
                                        No categories defined. Create one above.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</body>
</html>