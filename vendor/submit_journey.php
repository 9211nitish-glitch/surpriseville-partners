<?php
// vendor/submit_journey.php
require_once 'includes/session_manager.php';

if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';

$vendor_id = (int)$_SESSION['vendor_id'];

// 1. Auto-initialize table if it doesn't exist
$conn->query("CREATE TABLE IF NOT EXISTS vendor_journey_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_id INT NOT NULL UNIQUE,
    video_path VARCHAR(255) NOT NULL,
    testimonial_text TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    admin_notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$success_message = '';
$error_message = '';

// Retrieve existing record for this vendor
$stmt = $conn->prepare("SELECT * FROM vendor_journey_reviews WHERE vendor_id = ?");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$review = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Handling Uploads/submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$review || $review['status'] === 'rejected' || isset($_POST['resubmit_action'])) {
        $testimonial_text = isset($_POST['testimonial_text']) ? trim($_POST['testimonial_text']) : '';
        
        if (empty($testimonial_text)) {
            $error_message = "Testimonial text is required.";
        } elseif (!isset($_FILES['journey_video']) || $_FILES['journey_video']['error'] === UPLOAD_ERR_NO_FILE) {
            $error_message = "Please upload your journey video.";
        } else {
            // Handle file upload
            $file = $_FILES['journey_video'];
            $allowed_extensions = ['mp4', 'mov', 'webm'];
            $allowed_mimes = ['video/mp4', 'video/quicktime', 'video/webm'];
            
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (!in_array($ext, $allowed_extensions)) {
                $error_message = "Invalid file extension. Only MP4, MOV, and WEBM videos are allowed.";
            } elseif ($file['size'] > 100 * 1024 * 1024) {
                $error_message = "File size exceeds the 100MB limit.";
            } else {
                // Verify MIME type if finfo is available
                $mime_valid = true;
                if (function_exists('finfo_open')) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime_type = finfo_file($finfo, $file['tmp_name']);
                    finfo_close($finfo);
                    
                    if (!in_array($mime_type, $allowed_mimes)) {
                        $mime_valid = false;
                        $error_message = "Invalid video file type. Please upload a valid MP4, MOV, or WEBM video.";
                    }
                }
                
                if ($mime_valid) {
                    // Create directory uploads/vendor-journeys/ if it doesn't exist
                    $upload_dir = __DIR__ . '/../uploads/vendor-journeys/';
                    if (!is_dir($upload_dir)) {
                        @mkdir($upload_dir, 0755, true);
                    }
                    
                    // Store files securely using unique names
                    $timestamp = time();
                    try {
                        $random = bin2hex(random_bytes(4));
                    } catch (Exception $e) {
                        $random = substr(md5(mt_rand()), 0, 8);
                    }
                    $filename = "journey-{$vendor_id}-{$timestamp}-{$random}.{$ext}";
                    $dest_path = $upload_dir . $filename;
                    $db_video_path = '/uploads/vendor-journeys/' . $filename;
                    
                    if (move_uploaded_file($file['tmp_name'], $dest_path)) {
                        // Delete old file if exists
                        if ($review && !empty($review['video_path'])) {
                            $old_file_path = __DIR__ . '/..' . $review['video_path'];
                            if (file_exists($old_file_path) && is_file($old_file_path)) {
                                @unlink($old_file_path);
                            }
                        }
                        
                        // Insert or update record
                        $stmt = $conn->prepare("INSERT INTO vendor_journey_reviews (vendor_id, video_path, testimonial_text, status, admin_notes) 
                                                VALUES (?, ?, ?, 'pending', NULL) 
                                                ON DUPLICATE KEY UPDATE video_path = VALUES(video_path), testimonial_text = VALUES(testimonial_text), status = 'pending', admin_notes = NULL");
                        $stmt->bind_param("iss", $vendor_id, $db_video_path, $testimonial_text);
                        
                        if ($stmt->execute()) {
                            $success_message = "Your journey review was submitted successfully and is now pending admin approval.";
                            // Refresh review info
                            $stmt_ref = $conn->prepare("SELECT * FROM vendor_journey_reviews WHERE vendor_id = ?");
                            $stmt_ref->bind_param("i", $vendor_id);
                            $stmt_ref->execute();
                            $review = $stmt_ref->get_result()->fetch_assoc();
                            $stmt_ref->close();
                        } else {
                            $error_message = "Database error: " . $conn->error;
                        }
                        $stmt->close();
                    } else {
                        $error_message = "Failed to save the uploaded file.";
                    }
                }
            }
        }
    } else {
        $error_message = "Submission is locked because a review is already approved or pending approval.";
    }
}

$page_title = 'Journey Video Review';
include 'header.php';
?>

<style>
    /* PAGE LAYOUT & CARDS */
    .journey-container {
        max-width: 850px;
        margin: 0 auto;
        padding-bottom: 40px;
    }

    .card {
        background: var(--bg-card);
        padding: 30px;
        border-radius: 20px;
        margin-bottom: 25px;
        box-shadow: var(--card-shadow);
        border: 1px solid var(--border-color);
        box-sizing: border-box;
    }

    .card h2 {
        margin-top: 0;
        color: var(--text-main);
        border-bottom: 1px solid var(--border-color);
        padding-bottom: 10px;
        margin-bottom: 20px;
        font-size: 22px;
        font-weight: 700;
    }

    /* BANNERS */
    .journey-alert {
        padding: 20px;
        border-radius: 16px;
        margin-bottom: 25px;
        display: flex;
        align-items: flex-start;
        gap: 15px;
        border: 1px solid transparent;
        box-sizing: border-box;
    }

    .journey-alert-content {
        flex: 1;
    }

    .journey-alert-title {
        font-weight: 700;
        font-size: 16px;
        margin-bottom: 6px;
    }

    .journey-alert-desc {
        font-size: 14px;
        line-height: 1.5;
        margin: 0;
    }

    .journey-alert-approved {
        background: rgba(16, 185, 129, 0.1);
        color: #10b981;
        border-color: rgba(16, 185, 129, 0.2);
    }
    :root[data-theme="dark"] .journey-alert-approved {
        background: rgba(16, 185, 129, 0.15);
        color: #34d399;
    }

    .journey-alert-pending {
        background: rgba(245, 158, 11, 0.1);
        color: #f59e0b;
        border-color: rgba(245, 158, 11, 0.2);
    }
    :root[data-theme="dark"] .journey-alert-pending {
        background: rgba(245, 158, 11, 0.15);
        color: #fbbf24;
    }

    .journey-alert-rejected {
        background: rgba(239, 68, 68, 0.1);
        color: #ef4444;
        border-color: rgba(239, 68, 68, 0.2);
    }
    :root[data-theme="dark"] .journey-alert-rejected {
        background: rgba(239, 68, 68, 0.15);
        color: #f87171;
    }

    /* FORM ELEMENTS */
    .section {
        margin-bottom: 25px;
        padding: 20px;
        border: 1px solid var(--border-color);
        border-radius: 12px;
        background: transparent;
        box-sizing: border-box;
    }

    .section h3 {
        margin-top: 0;
        font-size: 16px;
        color: var(--text-main);
        border-bottom: 1px dashed var(--border-color);
        padding-bottom: 8px;
        margin-bottom: 20px;
        font-weight: 600;
    }

    label.block {
        display: block;
        margin-bottom: 6px;
        font-weight: 600;
        font-size: 14px;
        color: var(--text-main);
    }

    input[type="file"] {
        width: 100%;
        padding: 12px;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        font-size: 14px;
        box-sizing: border-box;
        margin-bottom: 15px;
        background: rgba(0, 0, 0, 0.02);
        color: var(--text-main);
        transition: all 0.3s;
    }

    :root[data-theme="dark"] input[type="file"] {
        background: rgba(255, 255, 255, 0.02);
    }

    textarea {
        width: 100%;
        height: 120px;
        padding: 12px;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        font-size: 14px;
        background: rgba(0, 0, 0, 0.02);
        color: var(--text-main);
        font-family: inherit;
        margin-bottom: 20px;
        box-sizing: border-box;
        resize: vertical;
        transition: all 0.3s;
    }

    :root[data-theme="dark"] textarea {
        background: rgba(255, 255, 255, 0.02);
    }

    input[type="file"]:focus,
    textarea:focus {
        border-color: var(--primary);
        outline: none;
        box-shadow: 0 0 0 3px rgba(19, 91, 236, 0.1);
    }

    /* BUTTONS */
    .btn {
        padding: 12px 24px;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        font-size: 14px;
        font-weight: 600;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }

    .btn:hover {
        opacity: 0.9;
        transform: translateY(-1px);
    }

    .btn-primary {
        background: var(--primary);
        color: #fff;
        box-shadow: 0 4px 12px rgba(19, 91, 236, 0.3);
    }

    .btn-outline {
        background: transparent;
        border: 1px solid var(--border-color);
        color: var(--text-main);
    }

    .btn-outline:hover {
        background: rgba(0, 0, 0, 0.02);
    }
    :root[data-theme="dark"] .btn-outline:hover {
        background: rgba(255, 255, 255, 0.05);
    }

    /* PREVIEW CONTAINER */
    .preview-grid {
        display: grid;
        grid-template-columns: 1.2fr 1fr;
        gap: 24px;
        margin-top: 15px;
    }

    .video-preview-wrapper video {
        width: 100%;
        max-height: 320px;
        border-radius: 12px;
        background: #000;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
        border: 1px solid var(--border-color);
    }

    .text-preview-wrapper {
        padding: 20px;
        background: rgba(0, 0, 0, 0.01);
        border-radius: 12px;
        border: 1px solid var(--border-color);
        box-sizing: border-box;
    }

    :root[data-theme="dark"] .text-preview-wrapper {
        background: rgba(255, 255, 255, 0.01);
    }

    /* GLOBAL MESSAGE STYLES */
    .msg-banner {
        padding: 15px;
        border-radius: 10px;
        margin-bottom: 20px;
        font-weight: 600;
        font-size: 14px;
    }

    .msg-banner-success {
        background: rgba(16, 185, 129, 0.15);
        color: #10b981;
        border: 1px solid rgba(16, 185, 129, 0.2);
    }

    .msg-banner-error {
        background: rgba(239, 68, 68, 0.15);
        color: #ef4444;
        border: 1px solid rgba(239, 68, 68, 0.2);
    }

    /* RESPONSIVE DESIGN */
    @media (max-width: 768px) {
        .preview-grid {
            grid-template-columns: 1fr;
            gap: 16px;
        }

        .card {
            padding: 20px;
        }
    }

    @media (max-width: 480px) {
        .journey-alert {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }

        .btn {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<div class="journey-container">

    <!-- Success/Error Notifications -->
    <?php if (!empty($success_message)): ?>
        <div class="msg-banner msg-banner-success">
            <?= htmlspecialchars($success_message) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="msg-banner msg-banner-error">
            <?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>

    <!-- Main Card -->
    <div class="card">
        <h2>Your Journey Video Review</h2>

        <?php if ($review): ?>
            <!-- Status banner when review exists -->
            <?php if ($review['status'] === 'approved'): ?>
                <div class="journey-alert journey-alert-approved">
                    <span class="material-symbols-outlined" style="font-size: 28px;">check_circle</span>
                    <div class="journey-alert-content">
                        <div class="journey-alert-title">Review Approved</div>
                        <p class="journey-alert-desc">Your journey review is approved and published publicly.</p>
                    </div>
                </div>
            <?php elseif ($review['status'] === 'pending'): ?>
                <div class="journey-alert journey-alert-pending">
                    <span class="material-symbols-outlined" style="font-size: 28px;">hourglass_empty</span>
                    <div class="journey-alert-content">
                        <div class="journey-alert-title">Review Pending</div>
                        <p class="journey-alert-desc">Your review is currently pending admin approval.</p>
                    </div>
                </div>
            <?php elseif ($review['status'] === 'rejected'): ?>
                <div id="rejectedBanner" class="journey-alert journey-alert-rejected">
                    <span class="material-symbols-outlined" style="font-size: 28px;">error</span>
                    <div class="journey-alert-content">
                        <div class="journey-alert-title">Review Rejected</div>
                        <p class="journey-alert-desc">Your review was rejected. Admin feedback: <strong><?= htmlspecialchars($review['admin_notes'] ?? 'No feedback provided.') ?></strong></p>
                        <div style="margin-top: 15px;">
                            <button id="resubmitBtn" class="btn btn-outline" style="border-color: #ef4444; color: #ef4444;">
                                <span class="material-symbols-outlined">restart_alt</span>
                                Resubmit Review
                            </button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Display current review details -->
            <div id="reviewPreview" class="section">
                <h3>Current Submission Preview</h3>
                <div class="preview-grid">
                    <div class="video-preview-wrapper">
                        <video controls>
                            <source src="<?= htmlspecialchars($review['video_path']) ?>" type="video/mp4">
                            Your browser does not support the video tag.
                        </video>
                    </div>
                    <div class="text-preview-wrapper">
                        <h4 style="margin-top: 0; margin-bottom: 10px; color: var(--text-main); font-weight: 700; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px;">Your Testimonial</h4>
                        <p style="color: var(--text-muted); font-size: 14px; line-height: 1.6; white-space: pre-wrap; font-style: italic;">
                            "<?= htmlspecialchars($review['testimonial_text']) ?>"
                        </p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Submission Form (displayed if no review exists OR when Resubmit is clicked) -->
        <div id="submitFormWrapper" style="display: <?= (!$review) ? 'block' : 'none' ?>;">
            <form id="journeyForm" action="submit_journey.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="resubmit_action" value="1">
                <div class="section">
                    <h3>Submit Your Growth Story</h3>
                    
                    <label class="block" for="journey_video">Client Feedback Video / Journey Video</label>
                    <input type="file" name="journey_video" id="journey_video" accept="video/mp4,video/quicktime,video/webm" required>
                    <p style="font-size: 12px; color: var(--text-muted); margin: -10px 0 20px 0;">
                        Supported formats: <strong>MP4, MOV, WEBM</strong>. Max file size: <strong>100MB</strong>.
                    </p>

                    <label class="block" for="testimonial_text">Testimonial text</label>
                    <textarea name="testimonial_text" id="testimonial_text" placeholder="Share your experience and story of growth since joining Surpriseville..." required><?= $review ? htmlspecialchars($review['testimonial_text']) : '' ?></textarea>

                    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                        <button type="submit" class="btn btn-primary">
                            <span class="material-symbols-outlined">send</span>
                            Submit Review
                        </button>
                        <?php if ($review): ?>
                            <button type="button" id="cancelResubmitBtn" class="btn btn-outline">Cancel</button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>

    </div>
</div>

<script>
    // Client-side file size verification
    document.getElementById('journey_video').addEventListener('change', function() {
        const file = this.files[0];
        if (file && file.size > 100 * 1024 * 1024) {
            alert('File size exceeds the 100MB limit. Please choose a video under 100MB.');
            this.value = '';
        }
    });

    // Handle resubmit show/hide logic
    <?php if ($review && $review['status'] === 'rejected'): ?>
        document.getElementById('resubmitBtn').addEventListener('click', function() {
            document.getElementById('rejectedBanner').style.display = 'none';
            document.getElementById('reviewPreview').style.display = 'none';
            document.getElementById('submitFormWrapper').style.display = 'block';
        });

        document.getElementById('cancelResubmitBtn').addEventListener('click', function() {
            document.getElementById('rejectedBanner').style.display = 'flex';
            document.getElementById('reviewPreview').style.display = 'block';
            document.getElementById('submitFormWrapper').style.display = 'none';
        });
    <?php endif; ?>
</script>

<?php include 'footer.php'; ?>
