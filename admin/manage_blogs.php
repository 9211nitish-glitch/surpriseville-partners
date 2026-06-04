<?php
/**
 * Admin Panel: Manage Blogs
 * CRUD operations for partners_blogs table
 */

session_start();

// Check authentication
if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../db.php';

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

// Handle Delete (GET request with CSRF verification)
if (isset($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);
    $token = $_GET['token'] ?? '';
    
    if ($token !== $_SESSION['csrf_token']) {
        $error = "CSRF token validation failed. Delete action unauthorized.";
    } else {
        $stmt = $conn->prepare("DELETE FROM partners_blogs WHERE id = ?");
        $stmt->bind_param("i", $delete_id);
        if ($stmt->execute()) {
            $message = "Blog post deleted successfully!";
        } else {
            $error = "Failed to delete blog post: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Handle Form Submission (Create / Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "CSRF token validation failed. Form submission unauthorized.";
    } else {
        $action = $_POST['action'] ?? '';
        $blog_id = intval($_POST['blog_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $video_url = trim($_POST['video_url'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $meta_title = trim($_POST['meta_title'] ?? '');
        $meta_description = trim($_POST['meta_description'] ?? '');

        // Validation: Title and Content must not be empty
        if (empty($title) || empty($content)) {
            $error = "Title and Content are required fields.";
        } else {
            // Auto-generate slug if empty
            if (empty($slug)) {
                $slug = preg_replace('/[^a-z0-9\s-]/', '', strtolower($title));
                $slug = preg_replace('/\s+/', '-', trim($slug));
                $slug = preg_replace('/-+/', '-', $slug);
            } else {
                // Sanitize user provided slug
                $slug = preg_replace('/[^a-z0-9\s-]/', '', strtolower($slug));
                $slug = preg_replace('/\s+/', '-', trim($slug));
                $slug = preg_replace('/-+/', '-', $slug);
            }

            // Ensure unique slug
            if ($action === 'add') {
                $chk = $conn->prepare("SELECT id FROM partners_blogs WHERE slug = ?");
                $chk->bind_param("s", $slug);
                $chk->execute();
                $chk->store_result();
                if ($chk->num_rows > 0) {
                    $original_slug = $slug;
                    $i = 1;
                    do {
                        $slug = $original_slug . '-' . $i;
                        $chk->close();
                        $chk = $conn->prepare("SELECT id FROM partners_blogs WHERE slug = ?");
                        $chk->bind_param("s", $slug);
                        $chk->execute();
                        $chk->store_result();
                        $i++;
                    } while ($chk->num_rows > 0);
                }
                $chk->close();

                $stmt = $conn->prepare("INSERT INTO partners_blogs (title, slug, content, video_url, meta_title, meta_description) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssss", $title, $slug, $content, $video_url, $meta_title, $meta_description);
                if ($stmt->execute()) {
                    $message = "Blog post created successfully!";
                } else {
                    $error = "Error creating blog post: " . $stmt->error;
                }
                $stmt->close();
            } elseif ($action === 'edit' && $blog_id > 0) {
                $chk = $conn->prepare("SELECT id FROM partners_blogs WHERE slug = ? AND id != ?");
                $chk->bind_param("si", $slug, $blog_id);
                $chk->execute();
                $chk->store_result();
                if ($chk->num_rows > 0) {
                    $original_slug = $slug;
                    $i = 1;
                    do {
                        $slug = $original_slug . '-' . $i;
                        $chk->close();
                        $chk = $conn->prepare("SELECT id FROM partners_blogs WHERE slug = ? AND id != ?");
                        $chk->bind_param("si", $slug, $blog_id);
                        $chk->execute();
                        $chk->store_result();
                        $i++;
                    } while ($chk->num_rows > 0);
                }
                $chk->close();

                $stmt = $conn->prepare("UPDATE partners_blogs SET title = ?, slug = ?, content = ?, video_url = ?, meta_title = ?, meta_description = ? WHERE id = ?");
                $stmt->bind_param("ssssssi", $title, $slug, $content, $video_url, $meta_title, $meta_description, $blog_id);
                if ($stmt->execute()) {
                    $message = "Blog post updated successfully!";
                } else {
                    $error = "Error updating blog post: " . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
}

// Fetch blogs with optional search filter
$search = trim($_GET['search'] ?? '');
$blogs = [];
if (!empty($search)) {
    $search_query = "%" . $search . "%";
    $stmt = $conn->prepare("SELECT * FROM partners_blogs WHERE title LIKE ? OR content LIKE ? ORDER BY created_at DESC");
    $stmt->bind_param("ss", $search_query, $search_query);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $blogs[] = $row;
    }
    $stmt->close();
} else {
    $result = $conn->query("SELECT * FROM partners_blogs ORDER BY created_at DESC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $blogs[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Blogs | Surprise Ville</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --danger-hover: #dc2626;
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
            width: 100%;
        }

        .main-content {
            flex: 1;
            min-width: 0;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-title h2 {
            margin: 0;
            font-weight: 800;
            font-size: 1.8rem;
            letter-spacing: -0.5px;
        }

        .page-title p {
            color: #64748b;
            font-weight: 600;
            margin-top: 5px;
        }

        .alert {
            padding: 1.25rem;
            border-radius: 20px;
            margin-bottom: 2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: fadeIn 0.3s ease;
        }

        .alert-success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }

        .alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .premium-card {
            background: var(--glass);
            backdrop-filter: blur(12px);
            border-radius: 32px;
            border: 1px solid var(--glass-border);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 2.5rem;
        }

        .search-bar {
            background: white;
            padding: 1rem 1.5rem;
            border-radius: 18px;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }

        .search-bar form {
            display: flex;
            width: 100%;
            align-items: center;
            gap: 1rem;
        }

        .search-bar input {
            border: none;
            outline: none;
            width: 100%;
            font-family: inherit;
            font-size: 0.95rem;
            font-weight: 500;
            color: #1e293b;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .modern-table {
            width: 100%;
            border-collapse: collapse;
        }

        .modern-table th {
            background: rgba(248, 250, 252, 0.5);
            padding: 1.25rem 1.5rem;
            text-align: left;
            font-size: 0.7rem;
            text-transform: uppercase;
            font-weight: 800;
            color: #64748b;
            letter-spacing: 1px;
            border-bottom: 1px solid var(--glass-border);
        }

        .modern-table td {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(226, 232, 240, 0.5);
            vertical-align: middle;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .modern-table tr:hover {
            background: rgba(255, 255, 255, 0.4);
        }

        .btn-protocol {
            background: var(--primary);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 14px;
            border: none;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
            font-size: 0.9rem;
        }

        .btn-protocol:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(79, 70, 229, 0.3);
        }

        .action-tray {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.2s;
            border: 1px solid #e2e8f0;
            background: white;
            cursor: pointer;
            font-size: 0.9rem;
        }

        .action-btn-edit {
            color: var(--primary);
        }

        .action-btn-edit:hover {
            border-color: var(--primary);
            background: rgba(79, 70, 229, 0.05);
            transform: translateY(-2px);
        }

        .action-btn-delete {
            color: var(--danger);
        }

        .action-btn-delete:hover {
            border-color: var(--danger);
            background: rgba(239, 68, 68, 0.05);
            transform: translateY(-2px);
        }

        /* Modal styling */
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
            overflow-y: auto;
        }

        .modal-box {
            background: white;
            width: 100%;
            max-width: 800px;
            border-radius: 32px;
            padding: 2.5rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: modalIn 0.3s ease-out;
            max-height: 90vh;
            overflow-y: auto;
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

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            font-size: 0.65rem;
            font-weight: 800;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: block;
            margin-bottom: 8px;
        }

        .form-control {
            width: 100%;
            padding: 1rem;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            font-family: inherit;
            font-weight: 600;
            outline: none;
            color: #1e293b;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .content-textarea {
            font-family: 'Outfit', sans-serif;
            font-size: 1rem;
            font-weight: 400;
            line-height: 1.6;
            padding: 1.2rem;
            border-radius: 16px;
            border: 2px solid rgba(79, 70, 229, 0.15);
            background: #fafbfd;
            color: #334155;
            outline: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.01);
            min-height: 250px;
        }

        .content-textarea:focus {
            border-color: var(--primary);
            background: #ffffff;
            box-shadow: 0 10px 25px -5px rgba(79, 70, 229, 0.05), 0 0 0 4px rgba(79, 70, 229, 0.15);
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

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-5px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Mobile responsive */
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

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: stretch;
            }
            .page-header .btn-protocol {
                justify-content: center;
            }
            .modal-box {
                padding: 1.5rem;
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
                <i class="fa-solid fa-feather"></i>
            </div>
            <h1 style="margin: 0; font-size: 1.4rem; font-weight: 800; letter-spacing: -0.5px;">Manage Blogs</h1>
        </div>
        <nav style="display: flex; align-items: center; gap: 20px;">
            <span style="font-weight: 700; color: var(--primary); font-size: 0.9rem;"><i class="fa-solid fa-shield-halved"></i> Admin Panel</span>
        </nav>
    </header>

    <div class="dashboard-container">
        <?php include 'sidebar_fragment.php'; ?>
        
        <main class="main-content">
            <div class="page-header">
                <div class="page-title">
                    <h2>Blog Posts</h2>
                    <p>Create, update, and manage articles for Surprise Ville partners and customers</p>
                </div>
                <button onclick="openBlogModal('add')" class="btn-protocol">
                    <i class="fa-solid fa-plus"></i> Add New Post
                </button>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert alert-success">
                    <i class="fa-solid fa-circle-check" style="font-size: 1.2rem;"></i> <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fa-solid fa-triangle-exclamation" style="font-size: 1.2rem;"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- Search Filter Bar -->
            <div class="search-bar">
                <form method="GET" action="">
                    <i class="fa-solid fa-magnifying-glass" style="color: #94a3b8;"></i>
                    <input type="text" name="search" id="blogSearch" placeholder="Search blog posts by title or content keywords..." value="<?= htmlspecialchars($search) ?>">
                    <?php if (!empty($search)): ?>
                        <a href="manage_blogs.php" style="color: #64748b; font-weight: 700; text-decoration: none; font-size: 0.9rem;">Clear</a>
                    <?php endif; ?>
                    <button type="submit" class="btn-protocol" style="padding: 0.5rem 1rem; border-radius: 10px;">Search</button>
                </form>
            </div>

            <!-- Blog List Table -->
            <div class="premium-card">
                <div class="table-responsive">
                    <table class="modern-table" id="blogTable">
                        <thead>
                            <tr>
                                <th style="width: 80px;">ID</th>
                                <th>Blog Title</th>
                                <th>Slug</th>
                                <th>Created At</th>
                                <th style="width: 120px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($blogs)): ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 3rem; color: #64748b;">
                                        <i class="fa-solid fa-feather" style="font-size: 2.5rem; margin-bottom: 1rem; display: block; opacity: 0.5;"></i>
                                        <div style="font-weight: 700; font-size: 1.1rem; margin-bottom: 5px;">No Blogs Found</div>
                                        <div>Try adjusting your search criteria or create a new blog post.</div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($blogs as $b): ?>
                                    <tr>
                                        <td style="color: #64748b; font-weight: 700;">#<?= $b['id'] ?></td>
                                        <td>
                                            <div style="font-weight: 800; color: #0f172a; font-size: 0.95rem;"><?= htmlspecialchars($b['title']) ?></div>
                                            <?php if (!empty($b['video_url'])): ?>
                                                <div style="font-size: 0.75rem; color: var(--primary); font-weight: 700; margin-top: 4px;">
                                                    <i class="fa-solid fa-circle-play"></i> Video Attached
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <code style="background: #f1f5f9; padding: 4px 8px; border-radius: 6px; font-size: 0.8rem; color: #475569; font-weight: 700;">/<?= htmlspecialchars($b['slug']) ?></code>
                                        </td>
                                        <td>
                                            <div style="color: #334155; font-size: 0.85rem;"><?= date('d M Y, h:i A', strtotime($b['created_at'])) ?></div>
                                        </td>
                                        <td>
                                            <div class="action-tray">
                                                <button onclick='openBlogModal("edit", <?= json_encode($b, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="action-btn action-btn-edit" title="Edit Post">
                                                    <i class="fa-solid fa-pen-nib"></i>
                                                </button>
                                                <a href="manage_blogs.php?delete=<?= $b['id'] ?>&token=<?= $_SESSION['csrf_token'] ?>" 
                                                   onclick="return confirm('Are you sure you want to permanently delete this blog post?')" 
                                                   class="action-btn action-btn-delete" title="Delete Post">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- ADD / EDIT MODAL -->
    <div id="blogModal" class="modal-overlay">
        <div class="modal-box">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
                <h3 id="modalTitle" style="font-weight: 800; font-size: 1.5rem; letter-spacing: -0.5px;">Add New Blog Post</h3>
                <button type="button" onclick="closeBlogModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b;"><i class="fa-solid fa-xmark"></i></button>
            </div>
            
            <form id="blogForm" method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" id="action_field" value="add">
                <input type="hidden" name="blog_id" id="blog_id" value="">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; flex-wrap: wrap;">
                    <div class="form-group">
                        <label for="blog_title">Blog Title <span style="color: var(--danger);">*</span></label>
                        <input type="text" name="title" id="blog_title" required class="form-control" placeholder="e.g. 10 Creative Wedding Decorations for 2026">
                    </div>
                    <div class="form-group">
                        <label for="blog_slug">URL Slug (Auto-generated)</label>
                        <input type="text" name="slug" id="blog_slug" class="form-control" placeholder="e.g. 10-creative-wedding-decorations-for-2026">
                    </div>
                </div>

                <div class="form-group">
                    <label for="blog_video_url">Video URL (Optional)</label>
                    <input type="text" name="video_url" id="blog_video_url" class="form-control" placeholder="YouTube watch URL, YouTube embed URL, or local MP4 file path">
                </div>

                <div class="form-group">
                    <label for="blog_content">Article Content <span style="color: var(--danger);">*</span></label>
                    <textarea name="content" id="blog_content" required class="form-control content-textarea" placeholder="Write your blog article here... Support HTML markup."></textarea>
                </div>

                <div style="border-top: 1px solid #e2e8f0; padding-top: 1.5rem; margin-top: 1.5rem; margin-bottom: 1.5rem;">
                    <h4 style="font-weight: 800; font-size: 1.1rem; color: #0f172a; margin-bottom: 1rem;"><i class="fa-solid fa-globe"></i> SEO Configuration (Optional)</h4>
                    
                    <div class="form-group">
                        <label for="blog_meta_title">Meta Title</label>
                        <input type="text" name="meta_title" id="blog_meta_title" class="form-control" placeholder="SEO search title (recommended under 60 chars)">
                    </div>

                    <div class="form-group">
                        <label for="blog_meta_description">Meta Description</label>
                        <textarea name="meta_description" id="blog_meta_description" rows="3" class="form-control" placeholder="SEO description snippet (recommended under 160 chars)"></textarea>
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 1rem;">
                    <button type="button" onclick="closeBlogModal()" style="padding: 0.75rem 1.5rem; border: none; border-radius: 14px; background: #f1f5f9; color: #475569; font-weight: 800; cursor: pointer;">Cancel</button>
                    <button type="submit" id="submitBtn" class="btn-protocol">Create Post</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Slug generation logic
        function generateSlug(text) {
            return text.toLowerCase()
                .replace(/[^a-z0-9\s-]/g, '')
                .trim()
                .replace(/\s+/g, '-')
                .replace(/-+/g, '-');
        }

        document.getElementById('blog_title').addEventListener('input', function() {
            const action = document.getElementById('action_field').value;
            // Only auto-generate on 'add' mode, and if slug isn't manually locked or modified
            if (action === 'add') {
                document.getElementById('blog_slug').value = generateSlug(this.value);
            }
        });

        document.getElementById('blog_slug').addEventListener('change', function() {
            this.value = generateSlug(this.value);
        });

        // Open modal
        function openBlogModal(action, blogData = null) {
            const modal = document.getElementById('blogModal');
            const modalTitle = document.getElementById('modalTitle');
            const submitBtn = document.getElementById('submitBtn');
            const blogForm = document.getElementById('blogForm');
            
            blogForm.reset();
            document.getElementById('blog_id').value = '';
            document.getElementById('action_field').value = action;
            
            if (action === 'add') {
                modalTitle.textContent = 'Add New Blog Post';
                submitBtn.textContent = 'Create Post';
            } else {
                modalTitle.textContent = 'Edit Blog Post';
                submitBtn.textContent = 'Save Changes';
                
                // Populate fields
                document.getElementById('blog_id').value = blogData.id;
                document.getElementById('blog_title').value = blogData.title;
                document.getElementById('blog_slug').value = blogData.slug;
                document.getElementById('blog_video_url').value = blogData.video_url || '';
                document.getElementById('blog_content').value = blogData.content;
                document.getElementById('blog_meta_title').value = blogData.meta_title || '';
                document.getElementById('blog_meta_description').value = blogData.meta_description || '';
            }
            
            modal.style.display = 'flex';
        }

        // Close modal
        function closeBlogModal() {
            document.getElementById('blogModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(e) {
            const modal = document.getElementById('blogModal');
            if (e.target === modal) {
                closeBlogModal();
            }
        });
    </script>
</body>
</html>
