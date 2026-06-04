<?php
header("Location: vendor/login.php");
exit;
?>
$blogs = [];
$bRes = $conn->query("SELECT id, title, slug, content as excerpt, video_url as thumbnail, created_at FROM partners_blogs ORDER BY created_at DESC LIMIT 3");
if ($bRes) while ($b = $bRes->fetch_assoc()) $blogs[] = $b;

// --- Fetch latest 4 vendor journeys (approved) ---
$journeys = [];
$jRes = $conn->query("SELECT vj.id, vj.title, vj.video_url, vj.thumbnail_url, v.name as vendor_name FROM vendor_journey_reviews vj JOIN vendors v ON v.id = vj.vendor_id WHERE vj.status='approved' ORDER BY vj.created_at DESC LIMIT 4");
if ($jRes) while ($j = $jRes->fetch_assoc()) $journeys[] = $j;

// --- Fetch latest 4 client video reviews (approved decorator videos) ---
$client_reviews = [];
$cRes = $conn->query("SELECT dv.id, dv.title, dv.video_url, dv.thumbnail_url, dv.reviewer_name, dv.rating FROM decorator_videos dv WHERE dv.status='approved' ORDER BY dv.created_at DESC LIMIT 4");
if ($cRes) while ($c = $cRes->fetch_assoc()) $client_reviews[] = $c;

// Helper: convert YouTube/Drive URL to embed
function toEmbed($url) {
    if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]+)/', $url, $m))
        return 'https://www.youtube.com/embed/' . $m[1];
    if (preg_match('/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/', $url, $m))
        return 'https://drive.google.com/file/d/' . $m[1] . '/preview';
    return $url;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Surpriseville Partners | Premium Vendor Network</title>
  <meta name="description" content="Join the elite vendor network of Surpriseville. Get real-time job notifications and grow your business with our premium partner app.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Exo+2:ital,wght@0,300;0,400;0,600;0,700;0,900;1,300&family=DM+Sans:wght@300;400;500&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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

    html { scroll-behavior: smooth; }
    body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text); overflow-x: hidden; line-height: 1.65; }

    body::before {
      content: ''; position: fixed; inset: 0;
      background-image: linear-gradient(rgba(26,92,255,0.04) 1px, transparent 1px), linear-gradient(90deg, rgba(26,92,255,0.04) 1px, transparent 1px);
      background-size: 60px 60px; pointer-events: none; z-index: 0;
    }

    .blob { position: fixed; border-radius: 50%; pointer-events: none; z-index: 0; filter: blur(80px); opacity: 0.35; }
    .blob-1 { width: 600px; height: 600px; background: #b3d0ff; top: -150px; right: -100px; animation: drift 18s ease-in-out infinite alternate; }
    .blob-2 { width: 400px; height: 400px; background: #c5f0ff; bottom: 10%; left: -100px; animation: drift 22s ease-in-out infinite alternate-reverse; }
    .blob-3 { width: 300px; height: 300px; background: #d8c9ff; top: 45%; right: 10%; animation: drift 15s ease-in-out infinite alternate; }
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
    .tag::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: var(--accent); animation: blink 1.4s ease-in-out infinite; }
    @keyframes blink { 0%,100% { opacity: 1; } 50% { opacity: 0.2; } }

    /* HEADER */
    header { position: sticky; top: 0; z-index: 100; background: rgba(246,248,255,0.92); backdrop-filter: blur(18px) saturate(180%); border-bottom: 1px solid var(--border); padding: 0.9rem 0; }
    .header-inner { display: flex; justify-content: space-between; align-items: center; }
    .logo { font-family: 'Exo 2', sans-serif; font-weight: 900; font-size: 1.15rem; letter-spacing: 0.06em; color: var(--text); text-decoration: none; }
    .logo span { color: var(--accent); }
    nav { display: flex; align-items: center; gap: 1.5rem; }
    nav a { font-size: 0.875rem; font-weight: 500; color: var(--text2); text-decoration: none; transition: color 0.2s; }
    nav a:hover { color: var(--accent); }
    .btn { display: inline-flex; align-items: center; gap: 10px; font-family: 'DM Sans', sans-serif; font-weight: 500; text-decoration: none; border-radius: var(--radius-sm); transition: all 0.25s; cursor: pointer; border: none; }
    .btn-primary { background: var(--accent); color: #fff; padding: 0.6rem 1.4rem; font-size: 0.9rem; box-shadow: 0 4px 20px rgba(26,92,255,0.3); }
    .btn-primary:hover { background: #0049e0; transform: translateY(-2px); box-shadow: 0 8px 28px rgba(26,92,255,0.4); }
    .btn-outline { background: transparent; color: var(--accent); border: 1.5px solid var(--border2); padding: 0.6rem 1.4rem; font-size: 0.9rem; }
    .btn-outline:hover { background: rgba(26,92,255,0.06); transform: translateY(-2px); }
    .btn-large { padding: 1rem 2.2rem; font-size: 1rem; border-radius: var(--radius); }

    /* HERO */
    .hero { padding: 7rem 0 5rem; }
    .hero-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4rem; align-items: center; }
    .hero-kicker { margin-bottom: 1.75rem; }
    h1 { font-family: 'Exo 2', sans-serif; font-size: clamp(2.6rem, 5vw, 3.8rem); font-weight: 900; line-height: 1.08; letter-spacing: -0.02em; color: var(--text); margin-bottom: 1.5rem; }
    h1 em { font-style: normal; color: var(--accent); }
    .hero-sub { font-size: 1.1rem; color: var(--text2); line-height: 1.7; max-width: 500px; margin-bottom: 2.5rem; }
    .hero-btns { display: flex; gap: 1rem; flex-wrap: wrap; }
    .hero-stats { display: flex; gap: 2.5rem; margin-top: 3rem; padding-top: 2rem; border-top: 1px solid var(--border); }
    .stat-n { font-family: 'Exo 2', sans-serif; font-size: 2rem; font-weight: 700; color: var(--text); }
    .stat-n span { color: var(--accent); }
    .stat-l { font-size: 0.8rem; color: var(--text3); font-weight: 500; letter-spacing: 0.04em; }

    /* PHONE MOCKUP */
    .phone-wrap { position: relative; display: flex; justify-content: center; }
    .phone-glow { position: absolute; width: 260px; height: 260px; border-radius: 50%; background: radial-gradient(circle, rgba(26,92,255,0.18) 0%, transparent 70%); top: 50%; left: 50%; transform: translate(-50%,-50%); animation: pulseGlow 3s ease-in-out infinite; }
    @keyframes pulseGlow { 0%,100% { opacity: 0.8; transform: translate(-50%,-50%) scale(1); } 50% { opacity: 1; transform: translate(-50%,-50%) scale(1.15); } }
    .phone { position: relative; width: 220px; background: var(--surface); border-radius: 32px; border: 2px solid rgba(26,92,255,0.2); box-shadow: 0 40px 80px rgba(26,92,255,0.18), 0 8px 24px rgba(0,0,0,0.06); padding: 28px 16px; overflow: hidden; animation: float 5s ease-in-out infinite; }
    @keyframes float { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-14px); } }
    .phone-notch { width: 60px; height: 18px; background: var(--text); border-radius: 20px; margin: 0 auto 20px; }
    .phone-screen { display: flex; flex-direction: column; gap: 10px; }
    .p-head { font-family: 'Exo 2', sans-serif; font-size: 11px; font-weight: 700; color: var(--accent); letter-spacing: 0.08em; }
    .p-card { background: var(--bg); border-radius: 10px; padding: 10px 12px; border: 1px solid var(--border); position: relative; overflow: hidden; }
    .p-card-title { font-size: 10px; font-weight: 600; color: var(--text); }
    .p-card-sub { font-size: 9px; color: var(--text3); margin-top: 2px; }
    .p-badge { display: inline-block; font-size: 8px; font-weight: 700; padding: 2px 7px; border-radius: 20px; margin-top: 5px; }
    .p-badge.new { background: rgba(26,92,255,0.1); color: var(--accent); }
    .p-badge.live { background: rgba(0,180,100,0.1); color: #009955; }
    .p-bar { height: 4px; border-radius: 2px; background: var(--surface2); overflow: hidden; margin-top: 6px; }
    .p-bar-fill { height: 100%; border-radius: 2px; background: linear-gradient(90deg, var(--accent), var(--accent2)); animation: fillBar 3s ease-in-out infinite alternate; }
    @keyframes fillBar { from { width: 35%; } to { width: 78%; } }
    .p-nav { display: flex; justify-content: space-around; margin-top: 16px; padding-top: 12px; border-top: 1px solid var(--border); }
    .p-nav-dot { width: 30px; height: 4px; border-radius: 2px; background: var(--surface2); }
    .p-nav-dot.active { background: var(--accent); }

    /* SECTION HEADERS */
    h2 { font-family: 'Exo 2', sans-serif; font-size: clamp(2rem, 4vw, 2.8rem); font-weight: 700; line-height: 1.15; color: var(--text); margin-bottom: 1rem; }
    h2 em { font-style: normal; color: var(--accent); }
    .section-header { text-align: center; margin-bottom: 4rem; }
    .section-header p { font-size: 1.05rem; color: var(--text2); max-width: 520px; margin: 0 auto; }

    /* FEATURES */
    .features { padding: 6rem 0; }
    .features-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; }
    .feat-card { background: var(--surface); border-radius: var(--radius); padding: 2rem 1.75rem; border: 1px solid var(--border); position: relative; overflow: hidden; transition: all 0.3s; cursor: default; }
    .feat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, var(--accent), var(--accent2)); opacity: 0; transition: opacity 0.3s; }
    .feat-card:hover { transform: translateY(-6px); border-color: rgba(26,92,255,0.3); box-shadow: 0 20px 50px rgba(26,92,255,0.1); }
    .feat-card:hover::before { opacity: 1; }
    .feat-icon { width: 52px; height: 52px; border-radius: var(--radius-sm); background: rgba(26,92,255,0.08); border: 1px solid rgba(26,92,255,0.15); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-bottom: 1.25rem; }
    .feat-card h3 { font-family: 'Exo 2', sans-serif; font-size: 1.1rem; font-weight: 700; color: var(--text); margin-bottom: 0.6rem; }
    .feat-card p { font-size: 0.9rem; color: var(--text2); line-height: 1.65; }
    .feat-num { position: absolute; right: 1.5rem; bottom: 1.5rem; font-family: 'JetBrains Mono', monospace; font-size: 3.5rem; font-weight: 600; color: rgba(26,92,255,0.05); line-height: 1; }

    /* STATS BAND */
    .stats-band { padding: 4rem 0; }
    .stats-inner { background: var(--surface); border-radius: var(--radius); border: 1px solid var(--border); display: grid; grid-template-columns: repeat(4, 1fr); overflow: hidden; }
    .stat-block { padding: 2.5rem 2rem; text-align: center; border-right: 1px solid var(--border); }
    .stat-block:last-child { border-right: none; }
    .stat-big { font-family: 'Exo 2', sans-serif; font-size: 2.5rem; font-weight: 900; color: var(--text); line-height: 1; }
    .stat-big em { font-style: normal; color: var(--accent); }
    .stat-desc { font-size: 0.82rem; color: var(--text3); margin-top: 0.5rem; font-weight: 500; letter-spacing: 0.03em; }

    /* ── VENDOR JOURNEY SECTION ── */
    .journey-section { padding: 6rem 0; background: var(--surface); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); }
    .video-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1.5rem; }
    .video-card {
      background: var(--bg); border-radius: var(--radius); overflow: hidden;
      border: 1px solid var(--border); transition: all 0.3s;
      box-shadow: 0 4px 20px rgba(26,92,255,0.06);
    }
    .video-card:hover { transform: translateY(-6px); box-shadow: 0 20px 50px rgba(26,92,255,0.12); border-color: rgba(26,92,255,0.25); }
    .video-thumb {
      position: relative; padding-top: 56.25%; background: #0a0e2a;
      overflow: hidden; cursor: pointer;
    }
    .video-thumb iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: none; }
    .video-thumb img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
    .play-overlay {
      position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
      background: rgba(9,14,36,0.35); transition: background 0.3s; cursor: pointer;
    }
    .play-overlay:hover { background: rgba(9,14,36,0.5); }
    .play-btn {
      width: 54px; height: 54px; border-radius: 50%; background: rgba(26,92,255,0.9);
      display: flex; align-items: center; justify-content: center;
      box-shadow: 0 8px 24px rgba(26,92,255,0.5); transition: transform 0.2s;
    }
    .play-btn i { color: white; font-size: 1.2rem; margin-left: 4px; }
    .play-overlay:hover .play-btn { transform: scale(1.1); }
    .video-info { padding: 1.2rem 1.4rem; }
    .video-title { font-family: 'Exo 2', sans-serif; font-size: 1rem; font-weight: 700; color: var(--text); margin-bottom: 0.3rem; line-height: 1.3; }
    .video-meta { font-size: 0.82rem; color: var(--text3); display: flex; align-items: center; gap: 6px; }
    .vendor-badge { background: rgba(26,92,255,0.08); color: var(--accent); padding: 2px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
    .stars { color: #f59e0b; font-size: 0.85rem; letter-spacing: 1px; }

    /* ── CLIENT REVIEWS SECTION ── */
    .reviews-section { padding: 6rem 0; }
    .reviews-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1.5rem; }
    .review-card {
      background: var(--surface); border-radius: var(--radius); overflow: hidden;
      border: 1px solid var(--border); transition: all 0.3s;
      box-shadow: 0 4px 20px rgba(26,92,255,0.06); position: relative;
    }
    .review-card:hover { transform: translateY(-6px); box-shadow: 0 20px 50px rgba(26,92,255,0.12); }
    .review-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, var(--accent3), var(--accent), var(--accent2)); }
    .review-info { padding: 1.2rem 1.4rem 1.4rem; }
    .reviewer-name { font-family: 'Exo 2', sans-serif; font-weight: 700; font-size: 0.95rem; color: var(--text); margin-bottom: 3px; }
    .review-rating { color: #f59e0b; font-size: 0.9rem; letter-spacing: 2px; }

    /* ── BLOG SECTION ── */
    .blog-section { padding: 6rem 0; background: var(--surface); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); }
    .blog-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.75rem; }
    .blog-card {
      background: var(--bg); border-radius: var(--radius); overflow: hidden;
      border: 1px solid var(--border); transition: all 0.3s; text-decoration: none;
      display: flex; flex-direction: column;
      box-shadow: 0 4px 20px rgba(26,92,255,0.06);
    }
    .blog-card:hover { transform: translateY(-6px); box-shadow: 0 20px 50px rgba(26,92,255,0.12); border-color: rgba(26,92,255,0.25); }
    .blog-img { width: 100%; height: 200px; object-fit: cover; display: block; background: linear-gradient(135deg, #eef1fc, #dde4ff); }
    .blog-img-placeholder { height: 200px; background: linear-gradient(135deg, #eef1fc 0%, #c5d5ff 100%); display: flex; align-items: center; justify-content: center; font-size: 3rem; }
    .blog-body { padding: 1.5rem; flex: 1; display: flex; flex-direction: column; }
    .blog-date { font-family: 'JetBrains Mono', monospace; font-size: 0.72rem; color: var(--text3); letter-spacing: 0.06em; margin-bottom: 0.6rem; }
    .blog-title { font-family: 'Exo 2', sans-serif; font-size: 1.1rem; font-weight: 700; color: var(--text); margin-bottom: 0.6rem; line-height: 1.35; }
    .blog-excerpt { font-size: 0.88rem; color: var(--text2); line-height: 1.65; flex: 1; }
    .blog-read-more { margin-top: 1.25rem; font-size: 0.85rem; font-weight: 600; color: var(--accent); display: flex; align-items: center; gap: 6px; }
    .blog-read-more i { transition: transform 0.2s; }
    .blog-card:hover .blog-read-more i { transform: translateX(4px); }
    .empty-state { text-align: center; padding: 4rem 2rem; color: var(--text3); }
    .empty-state i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.4; display: block; }

    /* VIDEO MODAL */
    .vid-modal { display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(9,14,36,0.85); backdrop-filter: blur(10px); align-items: center; justify-content: center; }
    .vid-modal.open { display: flex; }
    .vid-modal-inner { position: relative; width: 90%; max-width: 900px; aspect-ratio: 16/9; border-radius: var(--radius); overflow: hidden; box-shadow: 0 40px 80px rgba(0,0,0,0.5); }
    .vid-modal-inner iframe { width: 100%; height: 100%; border: none; }
    .vid-close { position: absolute; top: -44px; right: 0; background: none; border: none; color: white; font-size: 1.5rem; cursor: pointer; opacity: 0.8; transition: opacity 0.2s; }
    .vid-close:hover { opacity: 1; }

    /* CTA */
    .cta-section { padding: 7rem 0; }
    .cta-box { background: var(--surface); border-radius: 24px; border: 1px solid var(--border); padding: 5rem 3rem; text-align: center; position: relative; overflow: hidden; }
    .cta-box::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, var(--accent3), var(--accent), var(--accent2)); }
    .cta-grid-bg { position: absolute; inset: 0; pointer-events: none; z-index: 0; background-image: linear-gradient(rgba(26,92,255,0.04) 1px, transparent 1px), linear-gradient(90deg, rgba(26,92,255,0.04) 1px, transparent 1px); background-size: 40px 40px; }
    .cta-box > * { position: relative; z-index: 1; }
    .cta-box h2 { font-size: clamp(2rem, 4vw, 3rem); margin-bottom: 1rem; }
    .cta-box p { font-size: 1.1rem; color: var(--text2); max-width: 480px; margin: 0 auto 2.5rem; }
    .cta-btns { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; }
    .cta-badge { margin-top: 2rem; font-family: 'JetBrains Mono', monospace; font-size: 0.75rem; color: var(--text3); letter-spacing: 0.06em; }

    /* VIEW ALL LINK */
    .view-all { display: flex; justify-content: center; margin-top: 3rem; }
    .view-all a { display: inline-flex; align-items: center; gap: 8px; color: var(--accent); font-weight: 600; text-decoration: none; border: 1.5px solid var(--border2); padding: 0.75rem 2rem; border-radius: var(--radius); transition: all 0.2s; }
    .view-all a:hover { background: rgba(26,92,255,0.06); transform: translateY(-2px); }

    /* FOOTER */
    footer { background: var(--surface); border-top: 1px solid var(--border); padding: 2.5rem 0; }
    .footer-inner { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1.5rem; }
    .footer-logo { font-family: 'Exo 2', sans-serif; font-weight: 700; font-size: 0.9rem; color: var(--text2); }
    .footer-links { display: flex; gap: 2rem; flex-wrap: wrap; }
    .footer-links a { font-size: 0.85rem; color: var(--text3); text-decoration: none; transition: color 0.2s; }
    .footer-links a:hover { color: var(--accent); }
    .footer-legal { font-size: 0.8rem; color: var(--text3); }

    .reveal { opacity: 0; transform: translateY(28px); transition: opacity 0.7s ease, transform 0.7s ease; }
    .reveal.visible { opacity: 1; transform: none; }

    /* RESPONSIVE */
    @media (max-width: 900px) {
      .hero-grid { grid-template-columns: 1fr; }
      .phone-wrap { display: none; }
      .features-grid { grid-template-columns: 1fr 1fr; }
      .stats-inner { grid-template-columns: 1fr 1fr; }
      .stat-block:nth-child(2) { border-right: none; }
    }
    @media (max-width: 580px) {
      .features-grid { grid-template-columns: 1fr; }
      .stats-inner { grid-template-columns: 1fr; }
      .stat-block { border-right: none; border-bottom: 1px solid var(--border); }
      .footer-inner { flex-direction: column; text-align: center; }
      nav { gap: 0.75rem; flex-wrap: wrap; justify-content: center; }
      .header-inner { flex-direction: column; gap: 1rem; text-align: center; }
    }
  </style>
</head>
<body>

  <div class="blob blob-1"></div>
  <div class="blob blob-2"></div>
  <div class="blob blob-3"></div>

  <!-- VIDEO MODAL -->
  <div class="vid-modal" id="vidModal" onclick="closeVid(event)">
    <div class="vid-modal-inner">
      <button class="vid-close" onclick="document.getElementById('vidModal').classList.remove('open'); document.getElementById('vidFrame').src='';"><i class="fa-solid fa-xmark"></i></button>
      <iframe id="vidFrame" src="" allowfullscreen allow="autoplay"></iframe>
    </div>
  </div>

  <!-- HEADER -->
  <header>
    <div class="container">
      <div class="header-inner">
        <a href="index.php" class="logo">SURPRISE<span>VILLE</span> PARTNERS</a>
        <nav>
          <a href="index.php" style="color: var(--accent); font-weight: 600;">Home</a>
          <a href="#journeys">Vendor Journeys</a>
          <a href="#reviews">Client Reviews</a>
          <a href="#blogs">Blog</a>
          <a href="vendor/login.php">Partner Login</a>
          <a href="vendor/register.php" class="btn btn-primary">Join Network</a>
        </nav>
      </div>
    </div>
  </header>

  <!-- HERO -->
  <section class="hero">
    <div class="container">
      <div class="hero-grid">
        <div class="hero-content">
          <div class="hero-kicker">
            <span class="tag">India's #1 Event Vendor Network</span>
          </div>
          <h1>
            Elevate Your<br>
            <em>Vendor</em> Success<br>
            <span style="color: var(--text);">To New Heights</span>
          </h1>
          <p class="hero-sub">The smartest way to receive, manage, and deliver event services. Join thousands of premium partners growing their business on autopilot.</p>
          <div class="hero-btns">
            <a href="apps/partners-app.apk" class="btn btn-primary btn-large">
              <span>📲</span><span>Download App</span>
            </a>
            <a href="vendor/register.php" class="btn btn-outline btn-large">Register as Partner</a>
          </div>
          <div class="hero-stats">
            <div><div class="stat-n">2.4<span>K+</span></div><div class="stat-l">Active Partners</div></div>
            <div><div class="stat-n">98<span>%</span></div><div class="stat-l">Satisfaction Rate</div></div>
            <div><div class="stat-n">50<span>+</span></div><div class="stat-l">Cities Covered</div></div>
          </div>
        </div>
        <div class="phone-wrap reveal">
          <div class="phone-glow"></div>
          <div class="phone">
            <div class="phone-notch"></div>
            <div class="phone-screen">
              <div class="p-head">// LIVE DASHBOARD</div>
              <div class="p-card">
                <div class="p-card-title">Birthday Decor — Bandra</div>
                <div class="p-card-sub">Client: Priya S. · ₹12,000</div>
                <div class="p-badge new">NEW ALERT</div>
                <div class="p-bar"><div class="p-bar-fill"></div></div>
              </div>
              <div class="p-card">
                <div class="p-card-title">Wedding Florals — Juhu</div>
                <div class="p-card-sub">Client: Rahul M. · ₹45,000</div>
                <div class="p-badge live">IN PROGRESS</div>
              </div>
              <div class="p-card">
                <div class="p-card-title">Corporate Event — BKC</div>
                <div class="p-card-sub">Client: TechCorp · ₹28,500</div>
                <div class="p-badge new">NEW ALERT</div>
              </div>
              <div class="p-nav">
                <div class="p-nav-dot active"></div>
                <div class="p-nav-dot"></div>
                <div class="p-nav-dot"></div>
                <div class="p-nav-dot"></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- FEATURES -->
  <section class="features">
    <div class="container">
      <div class="section-header reveal">
        <span class="tag">Core Capabilities</span>
        <h2>Engineered for <em>Growth</em></h2>
        <p>Powerful tools designed to put your business on autopilot and stay ahead of the competition.</p>
      </div>
      <div class="features-grid">
        <div class="feat-card reveal"><div class="feat-icon">🔔</div><h3>Instant Alerts</h3><p>Get notified the second a job matches your profile. No delays, no missed leads — ever.</p><div class="feat-num">01</div></div>
        <div class="feat-card reveal"><div class="feat-icon">⚡</div><h3>Lightning Fast</h3><p>Optimized interface for high-speed job acceptance. Be the first to claim every opportunity.</p><div class="feat-num">02</div></div>
        <div class="feat-card reveal"><div class="feat-icon">🛡️</div><h3>Secure Payments</h3><p>Transparent billing and encrypted transaction history for absolute peace of mind.</p><div class="feat-num">03</div></div>
        <div class="feat-card reveal"><div class="feat-icon">📊</div><h3>Smart Analytics</h3><p>Real-time performance dashboards to track earnings, acceptance rates, and growth trends.</p><div class="feat-num">04</div></div>
        <div class="feat-card reveal"><div class="feat-icon">🗺️</div><h3>City Targeting</h3><p>Define your operational zones. Only receive jobs within your preferred cities and radius.</p><div class="feat-num">05</div></div>
        <div class="feat-card reveal"><div class="feat-icon">🤝</div><h3>Dedicated Support</h3><p>A priority support line available 7 days a week to keep your business running smoothly.</p><div class="feat-num">06</div></div>
      </div>
    </div>
  </section>

  <!-- STATS BAND -->
  <div class="stats-band">
    <div class="container">
      <div class="stats-inner reveal">
        <div class="stat-block"><div class="stat-big">2.4<em>K</em></div><div class="stat-desc">Verified Partners</div></div>
        <div class="stat-block"><div class="stat-big">₹12<em>Cr+</em></div><div class="stat-desc">Distributed to Vendors</div></div>
        <div class="stat-block"><div class="stat-big">50<em>+</em></div><div class="stat-desc">Cities Active</div></div>
        <div class="stat-block"><div class="stat-big">98<em>%</em></div><div class="stat-desc">Partner Satisfaction</div></div>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════ -->
  <!-- VENDOR JOURNEY SECTION -->
  <!-- ══════════════════════════════════════════ -->
  <section class="journey-section" id="journeys">
    <div class="container">
      <div class="section-header reveal">
        <span class="tag">Real Vendor Stories</span>
        <h2>Vendor <em>Journey</em> Videos</h2>
        <p>Watch how our partners transformed their businesses after joining the Surpriseville network.</p>
      </div>

      <?php if (!empty($journeys)): ?>
        <div class="video-grid">
          <?php foreach ($journeys as $j): 
            $embedUrl = toEmbed($j['video_url']);
            $thumb = !empty($j['thumbnail_url']) ? htmlspecialchars($j['thumbnail_url']) : null;
          ?>
            <div class="video-card reveal">
              <div class="video-thumb">
                <?php if ($thumb): ?>
                  <img src="<?= $thumb ?>" alt="<?= htmlspecialchars($j['title']) ?>" loading="lazy">
                <?php else: ?>
                  <div style="position:absolute;inset:0;background:linear-gradient(135deg,#0a0e2a,#1a3a7c);display:flex;align-items:center;justify-content:center;font-size:3rem;">🎬</div>
                <?php endif; ?>
                <div class="play-overlay" onclick="playVid('<?= htmlspecialchars($embedUrl) ?>')">
                  <div class="play-btn"><i class="fa-solid fa-play"></i></div>
                </div>
              </div>
              <div class="video-info">
                <div class="video-title"><?= htmlspecialchars($j['title']) ?></div>
                <div class="video-meta">
                  <i class="fa-solid fa-user-tie" style="color: var(--accent); font-size: 0.75rem;"></i>
                  <span class="vendor-badge"><?= htmlspecialchars($j['vendor_name']) ?></span>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="view-all">
          <a href="vendor_journey.php"><i class="fa-solid fa-film"></i> View All Journeys <i class="fa-solid fa-arrow-right"></i></a>
        </div>
      <?php else: ?>
        <div class="empty-state reveal">
          <i class="fa-solid fa-video"></i>
          <p>Vendor journey videos coming soon! <a href="vendor/submit_journey.php" style="color:var(--accent);">Submit yours →</a></p>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- ══════════════════════════════════════════ -->
  <!-- CLIENT VIDEO REVIEWS SECTION -->
  <!-- ══════════════════════════════════════════ -->
  <section class="reviews-section" id="reviews">
    <div class="container">
      <div class="section-header reveal">
        <span class="tag">Client Testimonials</span>
        <h2>What <em>Clients</em> Say About Us</h2>
        <p>Real video feedback from customers who experienced our vendor network firsthand.</p>
      </div>

      <?php if (!empty($client_reviews)): ?>
        <div class="reviews-grid">
          <?php foreach ($client_reviews as $r):
            $embedUrl = toEmbed($r['video_url']);
            $thumb = !empty($r['thumbnail_url']) ? htmlspecialchars($r['thumbnail_url']) : null;
            $stars = str_repeat('★', min(5, max(1, intval($r['rating'] ?? 5)))) . str_repeat('☆', 5 - min(5, max(1, intval($r['rating'] ?? 5))));
          ?>
            <div class="review-card reveal">
              <div class="video-thumb">
                <?php if ($thumb): ?>
                  <img src="<?= $thumb ?>" alt="<?= htmlspecialchars($r['title']) ?>" loading="lazy">
                <?php else: ?>
                  <div style="position:absolute;inset:0;background:linear-gradient(135deg,#1a0530,#4a1a8a);display:flex;align-items:center;justify-content:center;font-size:3rem;">⭐</div>
                <?php endif; ?>
                <div class="play-overlay" onclick="playVid('<?= htmlspecialchars($embedUrl) ?>')">
                  <div class="play-btn"><i class="fa-solid fa-play"></i></div>
                </div>
              </div>
              <div class="review-info">
                <div class="reviewer-name"><?= htmlspecialchars($r['reviewer_name'] ?? $r['title']) ?></div>
                <div class="review-rating"><?= $stars ?></div>
                <?php if (!empty($r['title'])): ?>
                  <div style="font-size:0.82rem; color:var(--text2); margin-top:5px;"><?= htmlspecialchars($r['title']) ?></div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="view-all">
          <a href="vendor_journey.php"><i class="fa-solid fa-star"></i> View All Reviews <i class="fa-solid fa-arrow-right"></i></a>
        </div>
      <?php else: ?>
        <div class="empty-state reveal">
          <i class="fa-solid fa-star"></i>
          <p>Client video reviews coming soon!</p>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- ══════════════════════════════════════════ -->
  <!-- BLOG SECTION -->
  <!-- ══════════════════════════════════════════ -->
  <section class="blog-section" id="blogs">
    <div class="container">
      <div class="section-header reveal">
        <span class="tag">Knowledge Hub</span>
        <h2>From Our <em>Blog</em></h2>
        <p>Tips, guides, and success stories to help you grow your vendor business faster.</p>
      </div>

      <?php if (!empty($blogs)): ?>
        <div class="blog-grid">
          <?php foreach ($blogs as $b):
            $slug = !empty($b['slug']) ? $b['slug'] : $b['id'];
            $date = date('d M Y', strtotime($b['created_at']));
          ?>
            <a href="blog_detail.php?slug=<?= htmlspecialchars($slug) ?>" class="blog-card reveal">
              <?php if (!empty($b['thumbnail'])): ?>
                <img src="<?= htmlspecialchars($b['thumbnail']) ?>" alt="<?= htmlspecialchars($b['title']) ?>" class="blog-img" loading="lazy">
              <?php else: ?>
                <div class="blog-img-placeholder">📝</div>
              <?php endif; ?>
              <div class="blog-body">
                <div class="blog-date"><?= $date ?></div>
                <div class="blog-title"><?= htmlspecialchars($b['title']) ?></div>
                <?php if (!empty($b['excerpt'])): ?>
                  <div class="blog-excerpt"><?= htmlspecialchars(substr($b['excerpt'], 0, 120)) . (strlen($b['excerpt']) > 120 ? '...' : '') ?></div>
                <?php endif; ?>
                <div class="blog-read-more">Read Article <i class="fa-solid fa-arrow-right"></i></div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
        <div class="view-all">
          <a href="blogs.php"><i class="fa-solid fa-newspaper"></i> View All Articles <i class="fa-solid fa-arrow-right"></i></a>
        </div>
      <?php else: ?>
        <div class="empty-state reveal">
          <i class="fa-solid fa-pen-nib"></i>
          <p>Blog posts coming soon! Check back later.</p>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- CTA -->
  <section class="cta-section">
    <div class="container">
      <div class="cta-box reveal">
        <div class="cta-grid-bg"></div>
        <span class="tag">Ready to Scale?</span>
        <h2>Join the Elite <em>5%</em> of Vendors</h2>
        <p>Start receiving premium job alerts within hours of signing up. Your next client is already waiting.</p>
        <div class="cta-btns">
          <a href="vendor/register.php" class="btn btn-primary btn-large">Start Your Journey</a>
          <a href="apps/partners-app.apk" class="btn btn-outline btn-large">📲 Download APK</a>
        </div>
        <div class="cta-badge">// CREDIT-BASED · NO HIDDEN FEES · JOIN FREE</div>
      </div>
    </div>
  </section>

  <!-- FOOTER -->
  <footer>
    <div class="container">
      <div class="footer-inner">
        <div>
          <div class="footer-logo">SURPRISEVILLE PARTNERS</div>
          <div class="footer-legal" style="margin-top: 0.4rem;">© 2025 Surpriseville. All rights reserved.</div>
        </div>
        <div class="footer-links">
          <a href="#journeys">Vendor Journeys</a>
          <a href="#reviews">Client Reviews</a>
          <a href="blogs.php">Blog</a>
          <a href="privacy-policy.php">Privacy Policy</a>
          <a href="apps/partners-app.apk" style="color: var(--accent); font-weight: 600;">Download APK</a>
        </div>
      </div>
    </div>
  </footer>

  <script>
    // Scroll Reveal
    const reveals = document.querySelectorAll('.reveal');
    const obs = new IntersectionObserver((entries) => {
      entries.forEach((e, i) => {
        if (e.isIntersecting) {
          setTimeout(() => e.target.classList.add('visible'), i * 80);
          obs.unobserve(e.target);
        }
      });
    }, { threshold: 0.1 });
    reveals.forEach(el => obs.observe(el));
    document.querySelector('.hero-content')?.classList.add('visible');

    // Video Modal
    function playVid(url) {
      document.getElementById('vidFrame').src = url + '?autoplay=1';
      document.getElementById('vidModal').classList.add('open');
      document.body.style.overflow = 'hidden';
    }

    function closeVid(e) {
      if (e.target === document.getElementById('vidModal')) {
        document.getElementById('vidModal').classList.remove('open');
        document.getElementById('vidFrame').src = '';
        document.body.style.overflow = '';
      }
    }

    // ESC key closes modal
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') {
        document.getElementById('vidModal').classList.remove('open');
        document.getElementById('vidFrame').src = '';
        document.body.style.overflow = '';
      }
    });
  </script>
</body>
</html>