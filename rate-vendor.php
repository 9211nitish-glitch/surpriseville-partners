<?php
// rate-vendor.php
require_once 'db.php';
require_once 'db_main.php';

$order_id = intval($_GET['order_id'] ?? 0);
$is_gig = intval($_GET['is_gig'] ?? 0);

if ($order_id <= 0) {
    die("Invalid request.");
}

$vendor_id = 0;
$vendor_name = "";
$client_name = "";

// Fetch Job & Vendor Info
if ($is_gig) {
    $stmt = $conn->prepare("SELECT t.assigned_vendor_id, t.client_name, v.business_name, v.name as vname 
                            FROM manual_tasks t 
                            LEFT JOIN vendors v ON t.assigned_vendor_id = v.id 
                            WHERE t.id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    if ($res) {
        $vendor_id = $res['assigned_vendor_id'];
        $vendor_name = $res['business_name'] ?: $res['vname'];
        $client_name = $res['client_name'];
    }
    $stmt->close();
} else {
    // For Shop Orders, we look at order_vendor_assignments
    $stmt = $mainConn->prepare("SELECT a.vendor_id, o.client_name 
                                FROM order_vendor_assignments a 
                                JOIN orders o ON a.order_id = o.id 
                                WHERE o.id = ? AND a.vendor_id IS NOT NULL 
                                ORDER BY a.id DESC LIMIT 1");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    if ($res) {
        $vendor_id = $res['vendor_id'];
        $client_name = $res['client_name'];
        
        // Fetch vendor name from Vendor DB
        $vStmt = $conn->prepare("SELECT business_name, name FROM vendors WHERE id = ?");
        $vStmt->bind_param("i", $vendor_id);
        $vStmt->execute();
        $vRes = $vStmt->get_result()->fetch_assoc();
        if ($vRes) $vendor_name = $vRes['business_name'] ?: $vRes['name'];
        $vStmt->close();
    }
    $stmt->close();
}

if (!$vendor_id) {
    die("Vendor not found for this order.");
}

// Handle Form Submission
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rating = intval($_POST['rating']);
    $review = $_POST['review'] ?? '';
    
    $ins = $conn->prepare("INSERT INTO vendor_reviews (vendor_id, order_id, is_gig, rating, review, client_name) VALUES (?, ?, ?, ?, ?, ?)");
    $ins->bind_param("iiiiss", $vendor_id, $order_id, $is_gig, $rating, $review, $client_name);
    if ($ins->execute()) {
        $success = true;
    }
    $ins->close();
}

// Fetch Overall Rating for this Vendor
$avgRating = 0;
$totalReviews = 0;
$q = $conn->prepare("SELECT AVG(rating) as avg, COUNT(*) as cnt FROM vendor_reviews WHERE vendor_id = ?");
$q->bind_param("i", $vendor_id);
$q->execute();
$r = $q->get_result()->fetch_assoc();
if ($r) {
    $avgRating = round($r['avg'], 1);
    $totalReviews = $r['cnt'];
}
$q->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rate our Service - Surprise Ville</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #135bec;
            --secondary: #ff9800;
            --bg: #f4f7fe;
            --card: #ffffff;
            --text: #1e293b;
            --muted: #64748b;
        }
        body {
            font-family: 'Outfit', sans-serif;
            background: var(--bg);
            color: var(--text);
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            background: var(--card);
            padding: 40px;
            border-radius: 30px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.05);
            max-width: 450px;
            width: 100%;
            text-align: center;
        }
        .logo {
            width: 150px;
            margin-bottom: 20px;
        }
        h1 {
            font-size: 24px;
            margin-bottom: 10px;
            font-weight: 700;
        }
        p {
            color: var(--muted);
            line-height: 1.6;
            margin-bottom: 30px;
        }
        .vendor-profile {
            background: #f8fafc;
            padding: 20px;
            border-radius: 20px;
            margin-bottom: 30px;
            border: 1px solid #e2e8f0;
        }
        .vendor-name {
            font-weight: 700;
            font-size: 18px;
            color: var(--primary);
            display: block;
            margin-bottom: 5px;
        }
        .rating-badge {
            display: inline-flex;
            align-items: center;
            background: #fff;
            padding: 5px 12px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 14px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
            color: var(--secondary);
        }
        .rating-badge span {
            margin-right: 5px;
        }

        /* Star Rating CSS */
        .stars {
            display: flex;
            justify-content: center;
            flex-direction: row-reverse;
            margin-bottom: 20px;
        }
        .stars input {
            display: none;
        }
        .stars label {
            font-size: 40px;
            color: #cbd5e1;
            cursor: pointer;
            transition: color 0.2s;
            margin: 0 5px;
        }
        .stars label:hover,
        .stars label:hover ~ label,
        .stars input:checked ~ label {
            color: var(--secondary);
        }

        textarea {
            width: 100%;
            padding: 15px;
            border: 2px solid #e2e8f0;
            border-radius: 15px;
            font-family: inherit;
            font-size: 15px;
            resize: none;
            box-sizing: border-box;
            margin-bottom: 20px;
            transition: border-color 0.3s;
        }
        textarea:focus {
            outline: none;
            border-color: var(--primary);
        }
        .btn {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 15px 30px;
            border-radius: 15px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: all 0.3s;
            box-shadow: 0 10px 20px rgba(19, 91, 236, 0.2);
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 30px rgba(19, 91, 236, 0.3);
        }
        .success-msg {
            color: #10b981;
            font-weight: 600;
            font-size: 18px;
        }
        @media (max-width: 480px) {
            .stars label {
                font-size: 32px;
                margin: 0 3px;
            }
            body {
                padding: 10px;
            }
            .container {
                padding: 25px 15px;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <img src="https://partners.surpriseville.co.in/assets/img/logo.png" alt="Logo" class="logo">
    
    <?php if ($success): ?>
        <div class="success-msg">
            <div style="font-size: 60px; margin-bottom: 15px;">🎉</div>
            Thank you, <?= htmlspecialchars($client_name) ?>!<br>
            Your feedback has been recorded successfully.
        </div>
        <p style="margin-top: 20px;">We appreciate your time and hope to serve you again soon!</p>
    <?php else: ?>
        <h1>Rate our Service</h1>
        <p>Hi <?= htmlspecialchars($client_name) ?>, how was your experience with our team today?</p>

        <div class="vendor-profile">
            <span class="vendor-name"><?= htmlspecialchars($vendor_name) ?></span>
            <div class="rating-badge">
                <span>⭐</span> <?= $avgRating ?> / 5 (<?= $totalReviews ?> reviews)
            </div>
        </div>

        <form method="POST">
            <div class="stars">
                <input type="radio" name="rating" value="5" id="s5" required><label for="s5">★</label>
                <input type="radio" name="rating" value="4" id="s4"><label for="s4">★</label>
                <input type="radio" name="rating" value="3" id="s3"><label for="s3">★</label>
                <input type="radio" name="rating" value="2" id="s2"><label for="s2">★</label>
                <input type="radio" name="rating" value="1" id="s1"><label for="s1">★</label>
            </div>

            <textarea name="review" rows="4" placeholder="Write a short review about the service (optional)..."></textarea>

            <button type="submit" class="btn">Submit Review</button>
        </form>
    <?php endif; ?>
</div>

</body>
</html>
