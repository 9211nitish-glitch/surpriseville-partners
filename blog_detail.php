<?php
// partners.surpriseville.co.in/blog_detail.php
require 'db.php';

// Get slug
$slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';

if (empty($slug)) {
    header("Location: blogs.php");
    exit();
}

// Fetch blog details
$stmt = $conn->prepare("SELECT * FROM partners_blogs WHERE slug = ?");
$stmt->bind_param("s", $slug);
$stmt->execute();
$blog = $stmt->get_result()->fetch_assoc();

if (!$blog) {
    header("Location: blogs.php");
    exit();
}

// Fetch 4 recent other blogs for sidebar
$recent_stmt = $conn->prepare("SELECT * FROM partners_blogs WHERE id != ? ORDER BY created_at DESC LIMIT 4");
$recent_stmt->bind_param("i", $blog['id']);
$recent_stmt->execute();
$recent_result = $recent_stmt->get_result();

// SEO Meta Information
$meta_title = !empty($blog['meta_title']) ? $blog['meta_title'] : $blog['title'] . " | Surpriseville Partners";
$meta_description = !empty($blog['meta_description']) ? $blog['meta_description'] : mb_strimwidth(strip_tags($blog['content']), 0, 155, '...');

// Helper function to check/convert YouTube URLs to embed URL
function get_youtube_embed($url) {
    if (empty($url)) return null;
    
    // Match watch link, short link, or embed link
    $pattern = '/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/|youtube\.com\/shorts\/)([a-zA-Z0-9_-]{11})/';
    if (preg_match($pattern, $url, $matches)) {
        return "https://www.youtube.com/embed/" . $matches[1];
    }
    return null;
}

$youtube_embed_url = get_youtube_embed($blog['video_url']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  
  <!-- Dynamic SEO Meta Tags -->
  <title><?php echo htmlspecialchars($meta_title); ?></title>
  <meta name="description" content="<?php echo htmlspecialchars($meta_description); ?>">
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Exo+2:ital,wght@0,300;0,400;0,600;0,700;0,900;1,300&family=DM+Sans:wght@300;400;500;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
  
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg: #f6f8ff;
      --surface: #ffffff;
      --surface2: #eef1fc;
      --accent: #1a5cff;
      --accent2: #00c8ff;
      --accent3: #7b2fff;
      --text: #090e24;
      --text2: #3d4870;
      --text3: #6b74a8;
      --border: rgba(26,92,255,0.12);
      --border2: rgba(26,92,255,0.22);
      --glow: rgba(26,92,255,0.15);
      --radius: 16px;
      --radius-sm: 8px;
    }

    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--bg);
      color: var(--text);
      overflow-x: hidden;
      line-height: 1.65;
    }

    /* Grid background and decorative blobs */
    body::before {
      content: '';
      position: fixed; inset: 0;
      background-image:
        linear-gradient(rgba(26,92,255,0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(26,92,255,0.04) 1px, transparent 1px);
      background-size: 60px 60px;
      pointer-events: none; z-index: 0;
    }

    .blob {
      position: fixed; border-radius: 50%; pointer-events: none; z-index: 0;
      filter: blur(80px); opacity: 0.35;
    }
    .blob-1 { width: 600px; height: 600px; background: #b3d0ff; top: -150px; right: -100px; animation: drift 18s ease-in-out infinite alternate; }
    .blob-2 { width: 400px; height: 400px; background: #c5f0ff; bottom: 10%; left: -100px; animation: drift 22s ease-in-out infinite alternate-reverse; }
    @keyframes drift { from { transform: translate(0,0) scale(1); } to { transform: translate(30px,40px) scale(1.08); } }

    .container { max-width: 1180px; margin: 0 auto; padding: 0 2rem; position: relative; z-index: 1; }

    /* HEADER */
    header {
      position: sticky; top: 0; z-index: 100;
      background: rgba(246,248,255,0.82); backdrop-filter: blur(18px) saturate(180%);
      border-bottom: 1px solid var(--border);
      padding: 0.9rem 0;
    }
    .header-inner { display: flex; justify-content: space-between; align-items: center; }
    .logo-link { text-decoration: none; }
    .logo {
      font-family: 'Exo 2', sans-serif; font-weight: 900; font-size: 1.15rem;
      letter-spacing: 0.06em; color: var(--text);
    }
    .logo span { color: var(--accent); }
    nav { display: flex; align-items: center; gap: 1.5rem; }
    nav a {
      font-size: 0.875rem; font-weight: 500; color: var(--text2);
      text-decoration: none; transition: color 0.2s;
    }
    nav a:hover { color: var(--accent); }
    .btn {
      display: inline-flex; align-items: center; gap: 10px;
      font-family: 'DM Sans', sans-serif; font-weight: 500;
      text-decoration: none; border-radius: var(--radius-sm);
      transition: all 0.25s; cursor: pointer; border: none;
    }
    .btn-primary {
      background: var(--accent); color: #fff;
      padding: 0.6rem 1.4rem; font-size: 0.9rem;
      box-shadow: 0 4px 20px rgba(26,92,255,0.3);
    }
    .btn-primary:hover { background: #0049e0; transform: translateY(-2px); box-shadow: 0 8px 28px rgba(26,92,255,0.4); }

    /* BACK LINK & TITLE SECTION */
    .blog-header { padding: 4rem 0 2rem; }
    .back-link {
      display: inline-flex; align-items: center; gap: 8px;
      color: var(--accent); text-decoration: none;
      font-weight: 600; font-size: 0.9rem;
      margin-bottom: 1.5rem; transition: transform 0.2s;
    }
    .back-link:hover { transform: translateX(-4px); }
    
    .blog-post-title {
      font-family: 'Exo 2', sans-serif; font-size: clamp(2rem, 4.5vw, 2.8rem);
      font-weight: 900; line-height: 1.15; color: var(--text);
      margin-bottom: 1rem; letter-spacing: -0.01em;
    }
    .blog-post-date {
      font-family: 'JetBrains Mono', monospace; font-size: 12px;
      color: var(--text3); display: flex; align-items: center; gap: 6px;
    }
    .blog-post-date::before { content: ''; display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: var(--accent2); }

    /* GRID LAYOUT */
    .blog-detail-grid {
      display: grid;
      grid-template-columns: 2.5fr 1fr;
      gap: 3rem;
      padding-bottom: 6rem;
    }

    /* VIDEO SECTION */
    .video-container {
      position: relative; width: 100%;
      padding-bottom: 56.25%; /* 16:9 Aspect Ratio */
      height: 0; border-radius: var(--radius);
      overflow: hidden; border: 1px solid var(--border);
      box-shadow: 0 20px 40px rgba(26,92,255,0.06);
      margin-bottom: 2.5rem; background: #000;
    }
    .video-container iframe,
    .video-container video {
      position: absolute; top: 0; left: 0;
      width: 100%; height: 100%; border: 0;
      object-fit: cover;
    }

    /* CONTENT STYLING */
    .blog-content {
      font-size: 1.08rem; color: var(--text2);
      line-height: 1.85; background: rgba(255, 255, 255, 0.45);
      backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
      border: 1px solid var(--border); border-radius: var(--radius);
      padding: 2.5rem;
    }
    .blog-content p { margin-bottom: 1.5rem; }
    .blog-content p:last-child { margin-bottom: 0; }
    
    .blog-content h2, .blog-content h3 {
      font-family: 'Exo 2', sans-serif; color: var(--text);
      margin: 2.5rem 0 1rem; font-weight: 700; line-height: 1.3;
    }
    .blog-content h2:first-child, .blog-content h3:first-child { margin-top: 0; }
    
    .blog-content ul, .blog-content ol { margin-bottom: 1.5rem; padding-left: 1.5rem; }
    .blog-content li { margin-bottom: 0.5rem; }
    
    /* SIDEBAR */
    .blog-sidebar { position: relative; }
    .sidebar-box {
      background: rgba(255, 255, 255, 0.45);
      backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
      border: 1px solid var(--border); border-radius: var(--radius);
      padding: 2rem; position: sticky; top: 120px;
      box-shadow: 0 10px 30px rgba(26,92,255,0.03);
    }
    .sidebar-title {
      font-family: 'Exo 2', sans-serif; font-size: 1.15rem; font-weight: 700;
      margin-bottom: 1.25rem; color: var(--text);
      border-bottom: 1.5px solid var(--border); padding-bottom: 0.75rem;
    }
    .recent-post-item {
      display: block; text-decoration: none;
      margin-bottom: 1.25rem; padding-bottom: 1.25rem;
      border-bottom: 1px solid rgba(26,92,255,0.06);
    }
    .recent-post-item:last-child { margin-bottom: 0; padding-bottom: 0; border-bottom: none; }
    .recent-post-date {
      display: block; font-family: 'JetBrains Mono', monospace;
      font-size: 10px; color: var(--text3); margin-bottom: 4px;
    }
    .recent-post-title {
      font-family: 'DM Sans', sans-serif; font-size: 0.95rem;
      font-weight: 600; color: var(--text); line-height: 1.4;
      transition: color 0.2s;
    }
    .recent-post-item:hover .recent-post-title { color: var(--accent); }

    /* FOOTER */
    footer {
      background: var(--surface); border-top: 1px solid var(--border);
      padding: 2.5rem 0; margin-top: auto;
    }
    .footer-inner { display: flex; justify-content: space-between; align-items: center; }
    .footer-logo { font-family: 'Exo 2', sans-serif; font-weight: 700; font-size: 0.9rem; color: var(--text2); }
    .footer-links { display: flex; gap: 2rem; }
    .footer-links a { font-size: 0.85rem; color: var(--text3); text-decoration: none; transition: color 0.2s; }
    .footer-links a:hover { color: var(--accent); }
    .footer-legal { font-size: 0.8rem; color: var(--text3); }

    /* RESPONSIVE */
    @media (max-width: 900px) {
      .blog-detail-grid { grid-template-columns: 1fr; gap: 3rem; }
      .sidebar-box { position: static; }
    }
    @media (max-width: 768px) {
      .header-inner { flex-direction: column; text-align: center; gap: 1.25rem; }
      nav { flex-direction: column; text-align: center; gap: 0.75rem; }
      .blog-header { padding: 3rem 0 1.5rem; }
      .blog-content { padding: 1.75rem; }
    }
    @media (max-width: 580px) {
      .footer-inner { flex-direction: column; gap: 1.5rem; text-align: center; }
    }
  </style>
</head>
<body>

  <!-- Background Ambient Blobs -->
  <div class="blob blob-1"></div>
  <div class="blob blob-2"></div>

  <!-- HEADER -->
  <header>
    <div class="container">
      <div class="header-inner">
        <a href="index.php" class="logo-link"><div class="logo">SURPRISE<span>VILLE</span> PARTNERS</div></a>
        <nav>
          <a href="index.php">Home</a>
          <a href="vendor_journey.php">Vendor Journeys</a>
          <a href="blogs.php" style="color: var(--accent); font-weight: 600;">Blog</a>
          <a href="vendor/login.php">Partner Login</a>
          <a href="vendor/register.php" class="btn btn-primary">Join Network</a>
        </nav>
      </div>
    </div>
  </header>

  <!-- BLOG TITLE HEADER -->
  <section class="blog-header">
    <div class="container">
      <a href="blogs.php" class="back-link">&larr; Back to Blogs</a>
      <h1 class="blog-post-title"><?php echo htmlspecialchars($blog['title']); ?></h1>
      <div class="blog-post-date"><?php echo htmlspecialchars(date('F d, Y', strtotime($blog['created_at']))); ?></div>
    </div>
  </section>

  <!-- MAIN SECTION -->
  <section class="blog-body-section">
    <div class="container">
      <div class="blog-detail-grid">
        
        <!-- MAIN CONTENT BLOCK -->
        <main class="blog-main">
          <?php if (!empty($blog['video_url'])): ?>
            <div class="video-container">
              <?php if ($youtube_embed_url): ?>
                <iframe src="<?php echo htmlspecialchars($youtube_embed_url); ?>" 
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                        allowfullscreen></iframe>
              <?php else: ?>
                <video controls src="<?php echo htmlspecialchars($blog['video_url']); ?>" preload="metadata"></video>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          
          <div class="blog-content">
            <?php 
              // Output HTML content as is because it can contain list tags, bold tags etc.
              // Note: If entering content from a simple text area, it could use nl2br.
              // But since we populated it with html tags, we render it directly.
              echo $blog['content']; 
            ?>
          </div>
        </main>
        
        <!-- SIDEBAR -->
        <aside class="blog-sidebar">
          <div class="sidebar-box">
            <h3 class="sidebar-title">Recent Articles</h3>
            <?php if ($recent_result && $recent_result->num_rows > 0): ?>
              <?php while ($recent = $recent_result->fetch_assoc()): ?>
                <a href="blog_detail.php?slug=<?php echo urlencode($recent['slug']); ?>" class="recent-post-item">
                  <span class="recent-post-date"><?php echo htmlspecialchars(date('M d, Y', strtotime($recent['created_at']))); ?></span>
                  <span class="recent-post-title"><?php echo htmlspecialchars($recent['title']); ?></span>
                </a>
              <?php endwhile; ?>
            <?php else: ?>
              <p style="font-size: 0.9rem; color: var(--text3);">No other articles available.</p>
            <?php endif; ?>
          </div>
        </aside>

      </div>
    </div>
  </section>

  <!-- FOOTER -->
  <footer>
    <div class="container">
      <div class="footer-inner">
        <div>
          <div class="footer-logo">SURPRISEVILLE PARTNERS</div>
          <div class="footer-legal" style="margin-top: 0.4rem;">&copy; 2025 Surpriseville. All rights reserved.</div>
        </div>
        <div class="footer-links">
          <a href="privacy-policy.php">Privacy Policy</a>
          <a href="#">Terms of Service</a>
          <a href="apps/partners-app.apk" style="color: var(--accent); font-weight: 600;">Download APK</a>
        </div>
      </div>
    </div>
  </footer>

</body>
</html>
