<?php
// partners.surpriseville.co.in/blogs.php
require 'db.php';

// Check search query
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($search !== '') {
    $stmt = $conn->prepare("SELECT * FROM partners_blogs WHERE title LIKE ? OR content LIKE ? ORDER BY created_at DESC");
    $searchTerm = "%" . $search . "%";
    $stmt->bind_param("ss", $searchTerm, $searchTerm);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query("SELECT * FROM partners_blogs ORDER BY created_at DESC");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Partner Insights Blog | Surpriseville Partners</title>
  <meta name="description" content="Stay updated with latest decoration trends, vendor tool updates, business growth hacks, and success stories from the Surpriseville Partner network.">
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
      --glow2: rgba(0,200,255,0.12);
      --radius: 16px;
      --radius-sm: 8px;
    }

    html { scroll-behavior: smooth; }

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

    .tag {
      display: inline-flex; align-items: center; gap: 6px;
      font-family: 'JetBrains Mono', monospace; font-size: 11px; font-weight: 600;
      letter-spacing: 0.12em; text-transform: uppercase;
      color: var(--accent); background: rgba(26,92,255,0.08);
      border: 1px solid rgba(26,92,255,0.2); border-radius: 40px;
      padding: 5px 14px; margin-bottom: 1.5rem;
    }
    .tag::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: var(--accent); }

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

    /* HERO */
    .blog-hero { padding: 6rem 0 3.5rem; text-align: center; }
    h1 {
      font-family: 'Exo 2', sans-serif; font-size: clamp(2.2rem, 5vw, 3.4rem);
      font-weight: 900; line-height: 1.1; letter-spacing: -0.01em; color: var(--text);
      margin-bottom: 1.25rem;
    }
    h1 em { font-style: normal; color: var(--accent); }
    .hero-sub { font-size: 1.05rem; color: var(--text2); max-width: 600px; margin: 0 auto 2.5rem; }

    /* SEARCH BAR */
    .search-wrap { max-width: 540px; margin: 0 auto; }
    .search-form {
      display: flex; gap: 0.5rem;
      background: rgba(255, 255, 255, 0.45);
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      padding: 6px;
      border-radius: var(--radius);
      border: 1px solid rgba(26,92,255,0.18);
      box-shadow: 0 10px 30px rgba(26,92,255,0.05);
    }
    .search-input {
      flex: 1; background: transparent; border: none;
      padding: 0.75rem 1.25rem; font-family: inherit;
      font-size: 0.95rem; color: var(--text); outline: none;
    }
    .search-input::placeholder { color: var(--text3); }

    /* CARDS GRID */
    .blog-section { padding: 3rem 0 6rem; }
    .grid-container {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
      gap: 2rem;
    }

    /* Glassmorphism Blog Card */
    .blog-card {
      background: rgba(255, 255, 255, 0.45);
      backdrop-filter: blur(14px) saturate(180%);
      -webkit-backdrop-filter: blur(14px) saturate(180%);
      border: 1px solid rgba(26,92,255,0.08);
      border-radius: var(--radius);
      padding: 2rem;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
      position: relative;
      overflow: hidden;
      min-height: 380px;
    }
    .blog-card::before {
      content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
      background: linear-gradient(90deg, var(--accent), var(--accent2));
      opacity: 0; transition: opacity 0.3s;
    }
    .blog-card:hover {
      transform: translateY(-8px);
      border-color: rgba(26,92,255,0.25);
      box-shadow: 0 30px 60px rgba(26,92,255,0.08);
      background: rgba(255, 255, 255, 0.75);
    }
    .blog-card:hover::before { opacity: 1; }

    .blog-meta {
      display: flex; justify-content: space-between; align-items: center;
      font-family: 'JetBrains Mono', monospace; font-size: 11px;
      color: var(--text3); margin-bottom: 1.25rem;
    }
    .blog-tag {
      color: var(--accent); background: rgba(26,92,255,0.06);
      padding: 3px 10px; border-radius: 20px; font-weight: 600;
    }
    .blog-title {
      font-family: 'Exo 2', sans-serif; font-size: 1.35rem; font-weight: 700;
      color: var(--text); line-height: 1.3; margin-bottom: 1rem;
      display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
      overflow: hidden;
    }
    .blog-excerpt {
      font-size: 0.92rem; color: var(--text2); line-height: 1.6;
      margin-bottom: 1.75rem;
      display: -webkit-box; -webkit-line-clamp: 4; -webkit-box-orient: vertical;
      overflow: hidden;
      flex-grow: 1;
    }
    .blog-footer { display: flex; align-items: center; }
    .read-more-btn {
      color: var(--accent); font-weight: 600; text-decoration: none;
      display: inline-flex; align-items: center; gap: 6px;
      font-size: 0.9rem; transition: gap 0.2s;
    }
    .read-more-btn:hover { gap: 10px; color: #0049e0; }

    /* NO RESULTS */
    .no-results {
      text-align: center; padding: 5rem 2rem; background: rgba(255,255,255,0.3);
      backdrop-filter: blur(10px); border-radius: var(--radius);
      border: 1px solid var(--border); max-width: 600px; margin: 0 auto;
    }
    .no-results h3 { font-family: 'Exo 2', sans-serif; margin-bottom: 0.75rem; }
    .no-results p { color: var(--text2); margin-bottom: 1.5rem; }

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
    @media (max-width: 768px) {
      .header-inner { flex-direction: column; text-align: center; gap: 1.25rem; }
      nav { flex-direction: column; text-align: center; gap: 0.75rem; }
      .blog-hero { padding: 4rem 0 2.5rem; }
      .grid-container { grid-template-columns: 1fr; }
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

  <!-- HERO HEADER -->
  <section class="blog-hero">
    <div class="container">
      <span class="tag">Partner Hub</span>
      <h1>Surpriseville <em>Partners</em> Blog</h1>
      <p class="hero-sub">Expert advice, industry trends, and product tutorials designed to help decoration and event businesses scale.</p>
      
      <div class="search-wrap">
        <form action="blogs.php" method="GET" class="search-form">
          <input type="text" name="search" placeholder="Search articles..." value="<?php echo htmlspecialchars($search); ?>" class="search-input">
          <button type="submit" class="btn btn-primary">Search</button>
        </form>
      </div>
    </div>
  </section>

  <!-- BLOGS GRID -->
  <section class="blog-section">
    <div class="container">
      <?php if ($result && $result->num_rows > 0): ?>
        <div class="grid-container">
          <?php while ($row = $result->fetch_assoc()): ?>
            <?php 
              // Create a snippet: strip HTML, trim to 180 chars
              $plain_text = strip_tags($row['content']);
              $excerpt = mb_strimwidth($plain_text, 0, 180, '...');
              $formatted_date = date('M d, Y', strtotime($row['created_at']));
              $has_video = !empty($row['video_url']);
            ?>
            <article class="blog-card">
              <div>
                <div class="blog-meta">
                  <span class="blog-date"><?php echo htmlspecialchars($formatted_date); ?></span>
                  <?php if ($has_video): ?>
                    <span class="blog-tag">Contains Video</span>
                  <?php endif; ?>
                </div>
                <h2 class="blog-title"><?php echo htmlspecialchars($row['title']); ?></h2>
                <div class="blog-excerpt"><?php echo htmlspecialchars($excerpt); ?></div>
              </div>
              <div class="blog-footer">
                <a href="blog_detail.php?slug=<?php echo urlencode($row['slug']); ?>" class="read-more-btn">
                  <span>Read More</span>
                  <span>&rarr;</span>
                </a>
              </div>
            </article>
          <?php endwhile; ?>
        </div>
      <?php else: ?>
        <div class="no-results">
          <h3>No Articles Found</h3>
          <p>We couldn't find any articles matching "<strong><?php echo htmlspecialchars($search); ?></strong>".</p>
          <a href="blogs.php" class="btn btn-primary">Browse All Blogs</a>
        </div>
      <?php endif; ?>
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
