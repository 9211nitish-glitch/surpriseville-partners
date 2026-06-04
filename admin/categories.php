<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

require_once "../db.php";
require_once "../db_main.php";

$DB = $mainConn;
$message = '';
$error = '';

if (isset($_POST['add_category'])) {
    $name = trim($_POST['name']);
    $slug = strtolower(str_replace(" ", "-", $name));
    $description = trim($_POST['description']);
    $icon = trim($_POST['icon']);
    $status = $_POST['status'];

    $stmt = $DB->prepare("INSERT INTO categories (name, slug, description, icon, status) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $name, $slug, $description, $icon, $status);
    if ($stmt->execute()) {
        $message = "Category added successfully!";
    } else {
        $error = "Error occurred: " . $stmt->error;
    }
    $stmt->close();
}

if (isset($_POST['edit_category'])) {
    $id = intval($_POST['category_id']);
    $name = trim($_POST['name']);
    $slug = strtolower(str_replace(" ", "-", $name));
    $description = trim($_POST['description']);
    $icon = trim($_POST['icon']);
    $status = $_POST['status'];

    $stmt = $DB->prepare("UPDATE categories SET name=?, slug=?, description=?, icon=?, status=? WHERE id=?");
    $stmt->bind_param("sssssi", $name, $slug, $description, $icon, $status, $id);
    if ($stmt->execute()) {
        $message = "Category updated successfully!";
    } else {
        $error = "Update failed: " . $stmt->error;
    }
    $stmt->close();
}

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $DB->prepare("DELETE FROM categories WHERE id=?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $message = "Category deleted.";
    } else {
        $error = "Delete failed: " . $stmt->error;
    }
    $stmt->close();
}

$categories = [];
$result = $DB->query("SELECT * FROM categories ORDER BY id ASC");
while ($row = $result->fetch_assoc()) {
    $categories[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Categories | Surprise Ville</title>
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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

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

        .main-content {
            flex: 1;
            min-width: 0;
        }

        .category-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .classification-card {
            background: var(--glass);
            backdrop-filter: blur(12px);
            border-radius: 32px;
            border: 1px solid var(--glass-border);
            padding: 2rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .classification-card:hover {
            transform: translateY(-8px);
            border-color: var(--primary);
            box-shadow: 0 20px 25px -5px rgba(79, 70, 229, 0.1);
        }

        .cat-icon {
            font-size: 2.5rem;
            width: 72px;
            height: 72px;
            background: white;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }

        .status-pill {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 0.6rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .status-active {
            background: #dcfce7;
            color: #166534;
        }

        .status-inactive {
            background: #f1f5f9;
            color: #475569;
        }

        .btn-protocol {
            background: var(--primary);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 14px;
            border: none;
            font-weight: 800;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
        }

        .btn-protocol:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(79, 70, 229, 0.3);
        }

        .action-tray {
            display: flex;
            gap: 12px;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--glass-border);
        }

        .tray-btn {
            flex: 1;
            padding: 10px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: white;
            color: #64748b;
            font-weight: 700;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            font-size: 0.8rem;
            transition: all 0.2s;
        }

        .tray-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-2px);
        }

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(12px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .modal-box {
            background: white;
            width: 100%;
            max-width: 480px;
            border-radius: 32px;
            padding: 2.5rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: modalIn 0.3s ease-out;
        }

        @keyframes modalIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .form-control {
            width: 100%;
            padding: 1.1rem;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            font-family: inherit;
            margin-bottom: 1.25rem;
            font-weight: 600;
            outline: none;
        }

        .form-control:focus {
            border-color: var(--primary);
        }

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
            .dashboard-container {
                flex-direction: column;
                padding: 1.5rem;
                gap: 1rem;
            }

            .header {
                padding: 1rem 1.5rem;
            }
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
                <i class="fa-solid fa-folder-tree"></i>
            </div>
            <h1 style="margin: 0; font-size: 1.4rem; font-weight: 800; letter-spacing: -0.5px;">Manage Categories</h1>
        </div>
        <nav style="display: flex; align-items: center; gap: 20px;">
            <button onclick="toggleModal('addModal', true)" class="btn-protocol"><i class="fa-solid fa-plus"></i> Add New Category</button>
        </nav>
    </header>

    <div class="dashboard-container">
        <?php include 'sidebar_fragment.php'; ?>
        <main class="main-content">

            <div style="margin-bottom: 2.5rem;">
                <h2 style="margin: 0; font-weight: 800; font-size: 1.8rem; letter-spacing: -0.5px;">All Categories</h2>
                <p style="color: #64748b; font-weight: 600; margin-top: 5px;">Manage your shop categories here</p>
            </div>

            <?php if ($message): ?>
                <div style="background: #ecfdf5; color: #065f46; padding: 1.25rem; border-radius: 20px; border: 1px solid #6ee7b7; margin-bottom: 2rem; font-weight: 700; display: flex; align-items: center; gap: 12px;">
                    <i class="fa-solid fa-circle-check" style="font-size: 1.2rem;"></i> <?= $message ?>
                </div>
            <?php endif; ?>

            <div class="category-grid">
                <?php foreach ($categories as $cat): ?>
                    <div class="classification-card">
                        <span class="status-pill status-<?= $cat['status'] ?>"><?= $cat['status'] ?></span>
                        <div class="cat-icon"><?= $cat['icon'] ?></div>
                        <h3 style="margin: 0; font-weight: 800; font-size: 1.3rem; letter-spacing: -0.3px;"><?= htmlspecialchars($cat['name']) ?></h3>
                        <div style="font-size: 0.75rem; color: var(--primary); font-weight: 700; margin-top: 4px;">/<?= $cat['slug'] ?></div>
                        <p style="font-size: 0.9rem; color: #64748b; font-weight: 500; line-height: 1.6; min-height: 50px; margin-top: 1rem;"><?= htmlspecialchars($cat['description']) ?></p>

                        <div class="action-tray">
                            <button onclick='editNode(<?= json_encode($cat) ?>)' class="tray-btn"><i class="fa-solid fa-pen-nib"></i> Edit</button>
                            <a href="?delete=<?= $cat['id'] ?>" onclick="return confirm('Delete this category?')" class="tray-btn" style="color: var(--danger);"><i class="fa-solid fa-trash-can"></i> Delete</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        </main>
    </div>

    <!-- ADD MODAL -->
    <div id="addModal" class="modal-overlay">
        <div class="modal-box">
            <h3 style="margin-top:0; font-weight: 800; font-size: 1.5rem; letter-spacing: -0.5px;">Add New Category</h3>
            <p style="color: #64748b; font-weight: 600; margin-bottom: 2rem; font-size: 0.9rem;">Add a new category to your shop.</p>
            <form method="POST">
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 15px;">
                    <div>
                        <label style="font-size: 0.65rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 8px;">Category Name</label>
                        <input type="text" name="name" required class="form-control" placeholder="e.g. Corporate Events">
                    </div>
                    <div>
                        <label style="font-size: 0.65rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 8px;">Icon</label>
                        <input type="text" name="icon" class="form-control" placeholder="🏢">
                    </div>
                </div>
                <label style="font-size: 0.65rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 8px;">Description</label>
                <textarea name="description" rows="3" class="form-control" placeholder="Enter description here..."></textarea>
                <label style="font-size: 0.65rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 8px;">Status</label>
                <select name="status" class="form-control">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem; margin-top: 1rem;">
                    <button type="submit" name="add_category" class="btn-protocol" style="justify-content: center;">Save Category</button>
                    <button type="button" onclick="toggleModal('addModal', false)" style="padding: 1rem; border: none; border-radius: 14px; background: #f1f5f9; color: #475569; font-weight: 800; cursor: pointer;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- EDIT MODAL -->
    <div id="editModal" class="modal-overlay">
        <div class="modal-box">
            <h3 style="margin-top:0; font-weight: 800; font-size: 1.5rem; letter-spacing: -0.5px;">Edit Category</h3>
            <p style="color: #64748b; font-weight: 600; margin-bottom: 2rem; font-size: 0.9rem;">Change category details.</p>
            <form method="POST">
                <input type="hidden" name="category_id" id="edit_id">
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 15px;">
                    <div>
                        <label style="font-size: 0.65rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 8px;">Category Name</label>
                        <input type="text" name="name" id="edit_name" required class="form-control">
                    </div>
                    <div>
                        <label style="font-size: 0.65rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 8px;">Icon</label>
                        <input type="text" name="icon" id="edit_icon" class="form-control">
                    </div>
                </div>
                <label style="font-size: 0.65rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 8px;">Description</label>
                <textarea name="description" id="edit_desc" rows="3" class="form-control"></textarea>
                <label style="font-size: 0.65rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 8px;">Status</label>
                <select name="status" id="edit_status" class="form-control">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem; margin-top: 1rem;">
                    <button type="submit" name="edit_category" class="btn-protocol" style="justify-content: center;">Save Changes</button>
                    <button type="button" onclick="toggleModal('editModal', false)" style="padding: 1rem; border: none; border-radius: 14px; background: #f1f5f9; color: #475569; font-weight: 800; cursor: pointer;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleModal(id, show) {
            document.getElementById(id).style.display = show ? 'flex' : 'none';
        }

        function editNode(cat) {
            document.getElementById('edit_id').value = cat.id;
            document.getElementById('edit_name').value = cat.name;
            document.getElementById('edit_icon').value = cat.icon;
            document.getElementById('edit_desc').value = cat.description;
            document.getElementById('edit_status').value = cat.status;
            toggleModal('editModal', true);
        }
    </script>
</body>

</html>