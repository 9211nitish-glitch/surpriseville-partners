<?php
// partners.surpriseville.co.in/vendor_journey.php
require 'db.php';

// Fetch approved vendor journey reviews
$query = "
    SELECT vjr.*, v.name, v.business_name, v.city
    FROM vendor_journey_reviews vjr
    JOIN vendors v ON vjr.vendor_id = v.id
    WHERE vjr.status = 'approved'
    ORDER BY vjr.created_at DESC
";
$result = $conn->query($query);

// Helper function to check/convert YouTube URLs to embed URL
function get_youtube_embed($url) {
    if (empty($url)) return null;
    $pattern = '/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/|youtube\.com\/shorts\/)([a-zA-Z0-9_-]{11})/';
    if (preg_match($pattern, $url, $matches)) {
        return "https://www.youtube.com/embed/" . $matches[1];
    }
    return null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Partner Journeys & Success Stories | Surpriseville Partners</title>
  <meta name="description" content="Hear directly from our verified decoration partners. Watch video testimonials and read how event decorators are growing their businesses with Surpriseville.">
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
    .btn-large { padding: 1rem 2.2rem; font-size: 1rem; border-radius: var(--radius); }

    /* HERO */
    .journey-hero { padding: 6rem 0 3rem; text-align: center; }
    h1 {
      font-family: 'Exo 2', sans-serif; font-size: clamp(2.2rem, 5vw, 3.4rem);
      font-weight: 900; line-height: 1.1; letter-spacing: -0.01em; color: var(--text);
      margin-bottom: 1.25rem;
    }
    h1 em { font-style: normal; color: var(--accent); }
    .hero-sub { font-size: 1.05rem; color: var(--text2); max-width: 600px; margin: 0 auto; }

    /* JOURNEY GRID */
    .journey-section { padding: 2rem 0 5rem; }
    .journey-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
      gap: 2.5rem;
    }

    /* Journey Card Style */
    .journey-card {
      background: rgba(255, 255, 255, 0.45);
      backdrop-filter: blur(14px) saturate(180%);
      -webkit-backdrop-filter: blur(14px) saturate(180%);
      border: 1px solid rgba(26,92,255,0.08);
      border-radius: var(--radius);
      overflow: hidden;
      display: flex;
      flex-direction: column;
      transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
      box-shadow: 0 10px 30px rgba(26,92,255,0.02);
    }
    .journey-card:hover {
      transform: translateY(-8px);
      border-color: rgba(26,92,255,0.25);
      box-shadow: 0 30px 60px rgba(26,92,255,0.08);
      background: rgba(255, 255, 255, 0.75);
    }

    .journey-video-wrap {
      position: relative; width: 100%;
      padding-bottom: 56.25%; /* 16:9 Ratio */
      height: 0; background: #000;
      border-bottom: 1px solid var(--border);
    }
    .journey-video-wrap iframe,
    .journey-video-wrap video {
      position: absolute; top: 0; left: 0;
      width: 100%; height: 100%; border: 0;
      object-fit: cover;
    }

    .journey-info {
      padding: 2rem; display: flex;
      flex-direction: column; flex-grow: 1;
    }
    .vendor-biz-name {
      font-family: 'Exo 2', sans-serif; font-size: 1.25rem; font-weight: 700;
      color: var(--text); margin-bottom: 0.25rem;
    }
    .vendor-details {
      font-size: 0.85rem; color: var(--text3);
      margin-bottom: 0.75rem; display: flex; gap: 8px; align-items: center;
    }
    .vendor-city {
      background: rgba(26,92,255,0.06); padding: 2px 8px;
      border-radius: 20px; color: var(--accent); font-weight: 600;
    }
    .stars-row {
      color: #ffb800; font-size: 0.95rem; margin-bottom: 1.25rem;
      display: flex; gap: 3px;
    }
    .feedback-text {
      font-size: 0.95rem; color: var(--text2); line-height: 1.65;
      font-style: italic; position: relative; padding-left: 1.25rem;
      flex-grow: 1;
    }
    .feedback-text::before {
      content: '“'; position: absolute; left: 0; top: -5px;
      font-family: Georgia, serif; font-size: 2.2rem; line-height: 1;
      color: rgba(26,92,255,0.18);
    }

    /* CTA BANNER */
    .cta-section { padding: 4rem 0 7rem; }
    .cta-box {
      background: var(--surface); border-radius: 24px; border: 1px solid var(--border);
      padding: 5rem 3rem; text-align: center; position: relative; overflow: hidden;
    }
    .cta-box::before {
      content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
      background: linear-gradient(90deg, var(--accent3), var(--accent), var(--accent2));
    }
    .cta-grid-bg {
      position: absolute; inset: 0; pointer-events: none; z-index: 0;
      background-image:
        linear-gradient(rgba(26,92,255,0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(26,92,255,0.04) 1px, transparent 1px);
      background-size: 40px 40px;
    }
    .cta-box > * { position: relative; z-index: 1; }
    .cta-box h2 { font-family: 'Exo 2', sans-serif; font-size: clamp(1.8rem, 4vw, 2.6rem); margin-bottom: 1rem; font-weight: 700; color: var(--text); }
    .cta-box p { font-size: 1.05rem; color: var(--text2); max-width: 500px; margin: 0 auto 2.5rem; }
    .cta-badge {
      margin-top: 2rem; font-family: 'JetBrains Mono', monospace; font-size: 0.75rem;
      color: var(--text3); letter-spacing: 0.06em;
    }

    /* NO TESTIMONIALS */
    .no-reviews {
      text-align: center; padding: 5rem 2rem; background: rgba(255,255,255,0.3);
      backdrop-filter: blur(10px); border-radius: var(--radius);
      border: 1px solid var(--border); max-width: 600px; margin: 0 auto;
    }
    .no-reviews h3 { font-family: 'Exo 2', sans-serif; margin-bottom: 0.75rem; }
    .no-reviews p { color: var(--text2); margin-bottom: 1.5rem; }

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
      .journey-hero { padding: 4rem 0 2rem; }
      .journey-grid { grid-template-columns: 1fr; gap: 2rem; }
      .cta-box { padding: 3.5rem 1.5rem; }
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
          <a href="vendor_journey.php" style="color: var(--accent); font-weight: 600;">Vendor Journeys</a>
          <a href="blogs.php">Blog</a>
          <a href="vendor/login.php">Partner Login</a>
          <a href="vendor/register.php" class="btn btn-primary">Join Network</a>
        </nav>
      </div>
    </div>
  </header>

  <!-- HERO SECTION -->
  <section class="journey-hero">
    <div class="container">
      <span class="tag">Partner Stories</span>
      <h1>Surpriseville <em>Vendor</em> Journeys</h1>
      <p class="hero-sub">Success stories, growth stats, and honest feedback from decorators who have transformed their business with us.</p>
    </div>
  </section>

  <!-- JOURNEYS GRID -->
  <section class="journey-section">
    <div class="container">
      <?php if ($result && $result->num_rows > 0): ?>
        <div class="journey-grid">
          <?php while ($row = $result->fetch_assoc()): ?>
            <?php 
              $youtube_embed_url = get_youtube_embed($row['video_url']);
            ?>
            <div class="journey-card">
              <!-- Video Testimonial Player -->
              <div class="journey-video-wrap">
                <?php if ($youtube_embed_url): ?>
                  <iframe src="<?php echo htmlspecialchars($youtube_embed_url); ?>" 
                          allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                          allowfullscreen></iframe>
                <?php else: ?>
                  <video controls src="<?php echo htmlspecialchars($row['video_url']); ?>" preload="metadata"></video>
                <?php endif; ?>
              </div>
              
              <!-- Vendor Info -->
              <div class="journey-info">
                <h3 class="vendor-biz-name"><?php echo htmlspecialchars($row['business_name']); ?></h3>
                <div class="vendor-details">
                  <span class="vendor-owner">By <?php echo htmlspecialchars($row['name']); ?></span>
                  <?php if (!empty($row['city'])): ?>
                    <span class="vendor-city"><?php echo htmlspecialchars($row['city']); ?></span>
                  <?php endif; ?>
                </div>
                
                <!-- Testimonial Stars (Premium Star Graphic) -->
                <div class="stars-row">
                  <span>★</span><span>★</span><span>★</span><span>★</span><span>★</span>
                </div>
                
                <p class="feedback-text"><?php echo htmlspecialchars($row['review_text']); ?></p>
              </div>
            </div>
          <?php endwhile; ?>
        </div>
      <?php else: ?>
        <div class="no-reviews">
          <h3>No Testimonials Available</h3>
          <p>We are currently gathering testimonials from our partners. Check back soon!</p>
          <a href="index.php" class="btn btn-primary">Go to Home</a>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- CTA BANNER -->
  <section class="cta-section">
    <div class="container">
      <div class="cta-box">
        <div class="cta-grid-bg"></div>
        <span class="tag">Become a Partner</span>
        <h2>Ready to scale your decoration business?</h2>
        <p>Join Surpriseville Partners today! Get real-time job alerts in your city and withdraw your earnings instantly.</p>
        <div class="cta-btns">
          <a href="vendor/register.php" class="btn btn-primary btn-large">Join Surpriseville Partners</a>
        </div>
        <div class="cta-badge">// QUICK 5-MINUTE REGISTRATION · ZERO UPFRONT COST</div>
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
