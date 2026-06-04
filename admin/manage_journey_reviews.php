<?php
/**
 * Admin Panel: Manage Journey Reviews
 * Approve or reject vendor journey submissions
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

// Handle Actions (Approve / Reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "CSRF verification failed. Action unauthorized.";
    } else {
        $action = $_POST['action'] ?? '';
        $review_id = intval($_POST['review_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        if ($review_id <= 0) {
            $error = "Invalid review ID.";
        } else {
            if ($action === 'approve') {
                $stmt = $conn->prepare("UPDATE vendor_journey_reviews SET status = 'approved', admin_notes = ? WHERE id = ?");
                $stmt->bind_param("si", $notes, $review_id);
                if ($stmt->execute()) {
                    $message = "Journey review approved successfully!";
                } else {
                    $error = "Error approving review: " . $conn->error;
                }
                $stmt->close();
            } elseif ($action === 'reject') {
                if (empty($notes)) {
                    $error = "Rejection notes are required to reject a review.";
                } else {
                    $stmt = $conn->prepare("UPDATE vendor_journey_reviews SET status = 'rejected', admin_notes = ? WHERE id = ?");
                    $stmt->bind_param("si", $notes, $review_id);
                    if ($stmt->execute()) {
                        $message = "Journey review rejected.";
                    } else {
                        $error = "Error rejecting review: " . $conn->error;
                    }
                    $stmt->close();
                }
            }
        }
    }
}

// Fetch status counts for badge filters
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
$count_query = $conn->query("SELECT status, COUNT(*) as cnt FROM vendor_journey_reviews GROUP BY status");
if ($count_query) {
    while ($row = $count_query->fetch_assoc()) {
        if (isset($counts[$row['status']])) {
            $counts[$row['status']] = intval($row['cnt']);
        }
    }
}

// Determine active status tab filter
$status_filter = $_GET['status'] ?? 'pending';
if (!in_array($status_filter, ['pending', 'approved', 'rejected'])) {
    $status_filter = 'pending';
}

// Fetch reviews matching selected status tab
$reviews = [];
$stmt = $conn->prepare("
    SELECT r.*, v.name as vendor_name, v.business_name 
    FROM vendor_journey_reviews r
    JOIN vendors v ON r.vendor_id = v.id
    WHERE r.status = ?
    ORDER BY r.created_at DESC
");
$stmt->bind_param("s", $status_filter);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $reviews[] = $row;
}
$stmt->close();

/**
 * Helper function to render HTML5 video player or YouTube embed iframe
 */
function renderVideoPlayer($video_url) {
    $video_url = trim($video_url);
    if (empty($video_url)) {
        return '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#94a3b8;font-weight:700;"><i class="fa-solid fa-video-slash"></i> No Video Provided</div>';
    }

    // Check YouTube formats
    $youtube_id = '';
    if (preg_match('/embed\/([a-zA-Z0-9_-]+)/i', $video_url, $matches)) {
        $youtube_id = $matches[1];
    } elseif (preg_match('/watch\?v=([a-zA-Z0-9_-]+)/i', $video_url, $matches)) {
        $youtube_id = $matches[1];
    } elseif (preg_match('/youtu\.be\/([a-zA-Z0-9_-]+)/i', $video_url, $matches)) {
        $youtube_id = $matches[1];
    }

    if (!empty($youtube_id)) {
        return '
        <div class="iframe-container">
            <iframe src="https://www.youtube.com/embed/' . htmlspecialchars($youtube_id) . '" allowfullscreen></iframe>
        </div>';
    }

    // Default to local/direct MP4 video render
    return '
    <video controls class="html5-video">
        <source src="' . htmlspecialchars($video_url) . '" type="video/mp4">
        Your browser does not support the video tag.
    </video>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Journeys | Surprise Ville</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-hover: #4338ca;
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
            margin-bottom: 2rem;
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

        /* Filter Tabs Navigation */
        .tab-headers {
            display: flex;
            gap: 12px;
            margin-bottom: 2.5rem;
            border-bottom: 1px solid var(--glass-border);
            padding-bottom: 1rem;
            overflow-x: auto;
        }

        .tab-link {
            text-decoration: none;
            color: #64748b;
            font-weight: 700;
            font-size: 0.95rem;
            padding: 10px 20px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.4);
            border: 1px solid var(--glass-border);
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            white-space: nowrap;
        }

        .tab-link:hover {
            color: var(--primary);
            background: rgba(255, 255, 255, 0.8);
            transform: translateY(-2px);
        }

        .tab-link.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
            border-color: var(--primary);
        }

        .tab-badge {
            font-size: 0.7rem;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 20px;
        }

        .tab-badge-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .tab-link.active .tab-badge-pending {
            background: white;
            color: var(--warning);
        }

        .tab-badge-approved {
            background: #dcfce7;
            color: #166534;
        }

        .tab-link.active .tab-badge-approved {
            background: white;
            color: var(--success);
        }

        .tab-badge-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .tab-link.active .tab-badge-rejected {
            background: white;
            color: var(--danger);
        }

        /* Review Cards Grid Layout */
        .reviews-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
            gap: 2rem;
            margin-top: 1.5rem;
        }

        .review-card {
            background: var(--glass);
            backdrop-filter: blur(12px);
            border-radius: 28px;
            border: 1px solid var(--glass-border);
            padding: 1.75rem;
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            height: 100%;
        }

        .review-card:hover {
            transform: translateY(-6px);
            border-color: var(--primary);
            box-shadow: 0 20px 30px -8px rgba(0, 0, 0, 0.08);
        }

        .vendor-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .vendor-avatar {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: white;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 1.2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--glass-border);
            flex-shrink: 0;
        }

        .vendor-meta {
            flex: 1;
            min-width: 0;
        }

        .vendor-name {
            font-weight: 800;
            font-size: 1rem;
            color: #0f172a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .business-name {
            font-size: 0.8rem;
            color: #64748b;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .video-wrapper {
            width: 100%;
            aspect-ratio: 16/9;
            border-radius: 16px;
            overflow: hidden;
            background: #000;
            border: 1px solid rgba(0,0,0,0.1);
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
        }

        .html5-video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .iframe-container {
            width: 100%;
            height: 100%;
            position: relative;
        }

        .iframe-container iframe {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: 0;
        }

        .review-content {
            background: rgba(255,255,255,0.45);
            border-radius: 16px;
            padding: 1rem;
            border: 1px solid rgba(255,255,255,0.5);
            font-size: 0.9rem;
            line-height: 1.5;
            color: #334155;
            font-weight: 500;
            flex-grow: 1;
            overflow-wrap: break-word;
            display: -webkit-box;
            -webkit-line-clamp: 4;
            -webkit-box-orient: vertical;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.2s;
        }

        .review-content.expanded {
            display: block;
            -webkit-line-clamp: unset;
            max-height: none;
        }

        .review-date-status {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.75rem;
            color: #94a3b8;
            font-weight: 700;
        }

        .badge-status {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 800;
        }

        .status-val-pending { background: #fef3c7; color: #d97706; }
        .status-val-approved { background: #dcfce7; color: #15803d; }
        .status-val-rejected { background: #fee2e2; color: #b91c1c; }

        .admin-notes-section {
            background: rgba(241, 245, 249, 0.7);
            border-left: 4px solid #64748b;
            padding: 8px 12px;
            border-radius: 4px 12px 12px 4px;
            font-size: 0.8rem;
            color: #475569;
            font-weight: 600;
        }

        .card-actions {
            display: flex;
            gap: 10px;
            margin-top: auto;
        }

        .card-actions button {
            flex: 1;
            padding: 10px;
            border-radius: 12px;
            font-weight: 800;
            font-size: 0.8rem;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .btn-action-approve {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .btn-action-approve:hover {
            background: #bbf7d0;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(16, 185, 129, 0.15);
        }

        .btn-action-reject {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .btn-action-reject:hover {
            background: #fecaca;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(239, 68, 68, 0.15);
        }

        .no-data-card {
            text-align: center;
            padding: 4rem 2rem;
            background: var(--glass);
            border: 1px solid var(--glass-border);
            border-radius: 28px;
            grid-column: 1 / -1;
            box-shadow: var(--shadow);
        }

        /* Modal window style */
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
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
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
    </style>
</head>
<body>

    <header class="header">
        <div style="display: flex; align-items: center; gap: 15px;">
            <div class="sidebar-toggle" onclick="toggleSidebar()">
                <i class="fa-solid fa-bars"></i>
            </div>
            <div style="background: var(--primary); color: white; width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                <i class="fa-solid fa-heart"></i>
            </div>
            <h1 style="margin: 0; font-size: 1.4rem; font-weight: 800; letter-spacing: -0.5px;">Vendor Journeys</h1>
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
                    <h2>Vendor Journey Reviews</h2>
                    <p>Review and moderate success journey and review videos submitted by our registered vendors</p>
                </div>
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

            <!-- Navigation Tabs -->
            <div class="tab-headers">
                <a href="?status=pending" class="tab-link <?= $status_filter === 'pending' ? 'active' : '' ?>">
                    <i class="fa-solid fa-clock"></i> Pending 
                    <span class="tab-badge tab-badge-pending"><?= $counts['pending'] ?></span>
                </a>
                <a href="?status=approved" class="tab-link <?= $status_filter === 'approved' ? 'active' : '' ?>">
                    <i class="fa-solid fa-circle-check"></i> Approved 
                    <span class="tab-badge tab-badge-approved"><?= $counts['approved'] ?></span>
                </a>
                <a href="?status=rejected" class="tab-link <?= $status_filter === 'rejected' ? 'active' : '' ?>">
                    <i class="fa-solid fa-circle-xmark"></i> Rejected 
                    <span class="tab-badge tab-badge-rejected"><?= $counts['rejected'] ?></span>
                </a>
            </div>

            <!-- Grid of Cards -->
            <div class="reviews-grid">
                <?php if (empty($reviews)): ?>
                    <div class="no-data-card">
                        <i class="fa-solid fa-face-smile" style="font-size: 3rem; color: #64748b; opacity: 0.5; margin-bottom: 1rem; display: block;"></i>
                        <h3 style="font-weight: 800; font-size: 1.25rem; color: #0f172a; margin-bottom: 5px;">All Clear!</h3>
                        <p style="color: #64748b; font-weight: 600; font-size: 0.9rem;">No reviews found matching the status "<?= ucfirst($status_filter) ?>"</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($reviews as $rev): ?>
                        <div class="review-card">
                            <!-- Vendor Details Header -->
                            <div class="vendor-info">
                                <div class="vendor-avatar">
                                    <?= strtoupper(substr($rev['business_name'], 0, 1)) ?>
                                </div>
                                <div class="vendor-meta">
                                    <div class="vendor-name"><?= htmlspecialchars($rev['vendor_name']) ?></div>
                                    <div class="business-name"><?= htmlspecialchars($rev['business_name']) ?></div>
                                </div>
                            </div>

                            <!-- Video Review Component -->
                            <div class="video-wrapper">
                                <?= renderVideoPlayer($rev['video_url']) ?>
                            </div>

                            <!-- Review Text Block -->
                            <div class="review-content" onclick="this.classList.toggle('expanded')" title="Click to expand/collapse review text">
                                <?= nl2br(htmlspecialchars($rev['review_text'])) ?>
                            </div>

                            <!-- Submission Metadata -->
                            <div class="review-date-status">
                                <div>
                                    <i class="fa-regular fa-calendar-days"></i> 
                                    <?= date('M d, Y', strtotime($rev['created_at'])) ?>
                                </div>
                                <span class="badge-status status-val-<?= $rev['status'] ?>">
                                    <?= $rev['status'] ?>
                                </span>
                            </div>

                            <!-- Internal Admin Notes (if any exist) -->
                            <?php if (!empty($rev['admin_notes'])): ?>
                                <div class="admin-notes-section">
                                    <div style="font-size: 0.65rem; text-transform: uppercase; color: #64748b; font-weight: 800; margin-bottom: 4px; letter-spacing: 0.5px;">
                                        <i class="fa-solid fa-comment-dots"></i> Admin Notes / Rejection Reason
                                    </div>
                                    <?= htmlspecialchars($rev['admin_notes']) ?>
                                </div>
                            <?php endif; ?>

                            <!-- Action Panel -->
                            <div class="card-actions">
                                <?php if ($rev['status'] === 'pending'): ?>
                                    <button class="btn-action-approve" onclick="approveReview(<?= $rev['id'] ?>)">
                                        <i class="fa-solid fa-check"></i> Approve
                                    </button>
                                    <button class="btn-action-reject" onclick="rejectReview(<?= $rev['id'] ?>)">
                                        <i class="fa-solid fa-xmark"></i> Reject
                                    </button>
                                <?php elseif ($rev['status'] === 'approved'): ?>
                                    <button class="btn-action-reject" onclick="rejectReview(<?= $rev['id'] ?>)" style="flex: unset; width: 100%;">
                                        <i class="fa-solid fa-xmark"></i> Change to Rejected
                                    </button>
                                <?php elseif ($rev['status'] === 'rejected'): ?>
                                    <button class="btn-action-approve" onclick="approveReview(<?= $rev['id'] ?>)" style="flex: unset; width: 100%;">
                                        <i class="fa-solid fa-check"></i> Change to Approved
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- ACTION DIALOG MODAL (Approve / Reject Dialogs) -->
    <div id="actionModal" class="modal-overlay">
        <div class="modal-box">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h3 id="modalActionTitle" style="font-weight: 800; font-size: 1.4rem; letter-spacing: -0.5px;">Reject Review Submission</h3>
                <button type="button" onclick="closeActionModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b;"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <p id="modalActionDesc" style="color: #64748b; font-weight: 600; margin-bottom: 1.5rem; font-size: 0.9rem; line-height: 1.5;">Please input a rejection reason.</p>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" id="modal_action" value="">
                <input type="hidden" name="review_id" id="modal_review_id" value="">
                
                <div class="form-group">
                    <label for="modal_notes" id="modal_notes_label">Notes / Reason <span style="color: var(--danger);">*</span></label>
                    <textarea name="notes" id="modal_notes" required class="form-control" rows="4" style="resize: vertical; min-height: 100px; font-weight: 600; line-height: 1.5;"></textarea>
                </div>
                
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem; margin-top: 1.5rem;">
                    <button type="submit" class="btn-protocol" id="modalSubmitBtn" style="justify-content: center; font-size: 0.95rem;">Submit</button>
                    <button type="button" onclick="closeActionModal()" style="padding: 1rem; border: none; border-radius: 14px; background: #f1f5f9; color: #475569; font-weight: 800; cursor: pointer; font-size: 0.95rem;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function approveReview(id) {
            document.getElementById('modalActionTitle').textContent = 'Approve Journey Review';
            document.getElementById('modalActionDesc').textContent = 'Confirm approval of this vendor journey review submission. You can add internal notes below if desired.';
            document.getElementById('modal_action').value = 'approve';
            document.getElementById('modal_review_id').value = id;
            document.getElementById('modal_notes_label').innerHTML = 'Approval Notes <span style="font-weight: normal; color: #94a3b8;">(Optional)</span>';
            document.getElementById('modal_notes').required = false;
            document.getElementById('modal_notes').placeholder = 'e.g. Approved. Beautiful feedback on our operational speed.';
            document.getElementById('modalSubmitBtn').style.background = 'var(--success)';
            document.getElementById('modalSubmitBtn').style.boxShadow = '0 4px 12px rgba(16, 185, 129, 0.25)';
            document.getElementById('modalSubmitBtn').textContent = 'Approve';
            document.getElementById('actionModal').style.display = 'flex';
        }

        function rejectReview(id) {
            document.getElementById('modalActionTitle').textContent = 'Reject Journey Review';
            document.getElementById('modalActionDesc').textContent = 'Please enter a clear reason for rejecting this vendor journey review. This will help the vendor understand the issue.';
            document.getElementById('modal_action').value = 'reject';
            document.getElementById('modal_review_id').value = id;
            document.getElementById('modal_notes_label').innerHTML = 'Rejection Reason <span style="color: var(--danger);">*</span>';
            document.getElementById('modal_notes').required = true;
            document.getElementById('modal_notes').placeholder = 'e.g. Video submission contains background noise / violates quality guidelines.';
            document.getElementById('modalSubmitBtn').style.background = 'var(--danger)';
            document.getElementById('modalSubmitBtn').style.boxShadow = '0 4px 12px rgba(239, 68, 68, 0.25)';
            document.getElementById('modalSubmitBtn').textContent = 'Reject Submission';
            document.getElementById('actionModal').style.display = 'flex';
        }

        function closeActionModal() {
            document.getElementById('actionModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(e) {
            const modal = document.getElementById('actionModal');
            if (e.target === modal) {
                closeActionModal();
            }
        });
    </script>
</body>
</html>
