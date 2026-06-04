<?php
// vendor/my-reviews.php
session_start();
if (!isset($_SESSION['vendor_logged_in'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';
require_once '../db_main.php';

$vendor_id = $_SESSION['vendor_id'];

// Fetch overall rating
$q = $conn->prepare("SELECT AVG(rating) as avg, COUNT(*) as cnt FROM vendor_reviews WHERE vendor_id = ?");
$q->bind_param("i", $vendor_id);
$q->execute();
$overall = $q->get_result()->fetch_assoc();
$q->close();

$avg = round($overall['avg'], 1);
$count = $overall['cnt'];

// Fetch reviews
$stmt = $conn->prepare("SELECT * FROM vendor_reviews WHERE vendor_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$reviews = $stmt->get_result();
$stmt->close();

$page_title = 'My Reviews & Ratings';
include 'header.php';
?>

<style>
    .rating-header {
        background: var(--bg-card);
        padding: 30px;
        border-radius: 20px;
        text-align: center;
        margin-bottom: 25px;
        border: 1px solid var(--border-color);
        box-shadow: var(--card-shadow);
    }
    .big-rating {
        font-size: 48px;
        font-weight: 800;
        color: #ff9800;
        margin-bottom: 5px;
    }
    .review-card {
        background: var(--bg-card);
        padding: 20px;
        border-radius: 15px;
        margin-bottom: 15px;
        border: 1px solid var(--border-color);
        transition: transform 0.2s;
    }
    .review-card:hover {
        transform: translateY(-3px);
    }
    .review-meta {
        display: flex;
        justify-content: space-between;
        margin-bottom: 10px;
        font-size: 14px;
    }
    .client-name {
        font-weight: 700;
        color: var(--primary);
    }
    .stars {
        color: #ff9800;
        font-weight: 700;
    }
    .review-text {
        font-size: 15px;
        line-height: 1.5;
        color: var(--text-main);
        font-style: italic;
    }
    .no-reviews {
        text-align: center;
        padding: 50px;
        color: var(--text-muted);
    }
</style>

<div class="rating-header">
    <div class="big-rating"><?= $avg ?: '0.0' ?> ⭐</div>
    <div style="font-weight: 600; color: var(--text-muted);">Overall Rating Based on <?= $count ?> Reviews</div>
</div>

<h2 style="margin-bottom: 20px;">Client Feedback</h2>

<?php if ($count == 0): ?>
    <div class="no-reviews card">
        <p>No reviews yet. Your ratings will appear here once clients complete your orders!</p>
    </div>
<?php else: ?>
    <?php while($r = $reviews->fetch_assoc()): ?>
        <div class="review-card">
            <div class="review-meta">
                <span class="client-name"><?= htmlspecialchars($r['client_name']) ?></span>
                <span class="stars"><?= str_repeat('★', $r['rating']) ?><?= str_repeat('☆', 5-$r['rating']) ?></span>
            </div>
            <div class="review-text">
                "<?= htmlspecialchars($r['review'] ?: 'No comment provided.') ?>"
            </div>
            <div style="font-size: 11px; color: var(--text-muted); margin-top: 10px; text-align: right;">
                <?= date('d M Y, h:i A', strtotime($r['created_at'])) ?> | <?= $r['is_gig'] ? 'Gig Task' : 'Shop Order' ?> #<?= $r['order_id'] ?>
            </div>
        </div>
    <?php endwhile; ?>
<?php endif; ?>

<?php include 'footer.php'; ?>
