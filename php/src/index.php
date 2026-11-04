<?php
require_once 'config/security.php';
$isLoggedIn = isset($_SESSION['id_account']);
$displayName = $isLoggedIn ? ($_SESSION['firstname'] ?? 'Guest') : '';
$paymentSuccess = $_SESSION['payment_success'] ?? null;
if ($paymentSuccess) {
  unset($_SESSION['payment_success']);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log Horizon | Hotels Chiang Rai</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;0,9..144,600;1,9..144,400&family=Work+Sans:wght@300;400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body data-authenticated="<?php echo $isLoggedIn ? '1' : '0'; ?>">

<!-- ===== HEADER ===== -->
<header class="site-header" id="siteHeader">
  <div class="header-inner">
    <a href="index.php" class="logo">
      <span class="logo-mark">L</span>
      <span class="logo-text">Log Horizon <em>Hotels</em></span>
    </a>

    <nav class="main-nav" id="mainNav">
      <a href="#rooms">Room</a>
      <a href="#amenities">Facilities</a>
      <a href="#location">Location</a>
      <a href="#contact">Contact Us</a>
    </nav>

    <div class="header-actions">
      <?php if ($isLoggedIn): ?>
        <span class="user-badge">สวัสดี, <?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></span>
        <a href="dashboard.php" class="btn btn-ghost">บัญชีของฉัน</a>
        <a href="logout.php" class="btn btn-ghost btn-logout">Logout</a>
      <?php else: ?>
        <a href="login.php" class="btn btn-ghost">LOGIN</a>
      <?php endif; ?>
      <button class="nav-toggle" id="navToggle" aria-label="เปิดเมนู">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>
</header>

<!-- ===== HERO ===== -->
<section class="hero">
  <div class="hero-mist" aria-hidden="true">
    <svg class="mist-layer mist-1" viewBox="0 0 1440 320" preserveAspectRatio="none"><path d="M0,160 C240,220 480,100 720,140 C960,180 1200,80 1440,150 L1440,320 L0,320 Z"/></svg>
    <svg class="mist-layer mist-2" viewBox="0 0 1440 320" preserveAspectRatio="none"><path d="M0,200 C300,140 600,240 900,190 C1100,160 1300,220 1440,190 L1440,320 L0,320 Z"/></svg>
    <svg class="mist-layer mist-3" viewBox="0 0 1440 320" preserveAspectRatio="none"><path d="M0,260 C360,230 720,280 1080,240 C1250,220 1350,260 1440,250 L1440,320 L0,320 Z"/></svg>
  </div>

  <div class="hero-content">
    <?php if ($paymentSuccess): ?>
      <p class="hero-toast"><?php echo htmlspecialchars($paymentSuccess, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <p class="eyebrow">Chiang Rai · Altitude of 680 meters</p>
    <h1>Sleeping amidst the mist.<br><em>Wake up with the mountain view</em></h1>
    <p class="hero-sub">Small guesthouse by the tea garden with 6 rooms, designed so you can open the curtains every morning and see the real mist, not just in pictures.</p>
  </div>

  <form class="search-card" id="searchForm">
    <div class="search-field">
      <label for="checkin">Check-in</label>
      <input type="date" id="checkin" name="checkin" required>
    </div>
    <div class="search-field">
      <label for="checkout">Check-out</label>
      <input type="date" id="checkout" name="checkout" required>
    </div>
    <div class="search-field">
      <label for="guests">Guests</label>
      <select id="guests" name="guests">
        <option value="1">1 Guest</option>
        <option value="2" selected>2 Guests</option>
      </select>
    </div>
    <button type="submit" class="btn btn-primary search-submit">Search</button>
  </form>
</section>

<!-- ===== ROOMS ===== -->
<section class="rooms" id="rooms">
  <div class="section-head">
    <p class="eyebrow">All Rooms</p>
    <h2>Six Rooms, Six Misty Corners</h2>
    <p class="section-sub">Each room faces a different corner of the valley. Choose the one with the sunrise view you like.</p>
  </div>

  <div class="room-grid" id="roomGrid">
    <!-- room cards injected by script.js -->
  </div>
</section>

<!-- ===== AMENITIES ===== -->
<section class="amenities" id="amenities">
  <div class="section-head">
    <p class="eyebrow">Facilities</p>
    <h2>Everything You Need, Nothing More</h2>
  </div>
  <div class="amenity-grid">
    <div class="amenity-item">
      <span class="amenity-icon">☕</span>
      <h3>Homegrown Tea</h3>
      <p>Steeped from leaves grown in the hotel's own garden, served free every morning.</p>
    </div>
    <div class="amenity-item">
      <span class="amenity-icon">🛁</span>
      <h3>Outdoor Bathtub</h3>
      <p>Every room has a private outdoor bathtub facing the valley.</p>
    </div>
    <div class="amenity-item">
      <span class="amenity-icon">🔥</span>
      <h3>Fireplace in Room</h3>
      <p>Cool evenings have a real fireplace for you to warm up.</p>
    </div>
    <div class="amenity-item">
      <span class="amenity-icon">🥾</span>
      <h3>Mist Viewing Trail</h3>
      <p>20-minute walk to the morning mist viewing spot.</p>
    </div>
  </div>
</section>

<!-- ===== LOCATION ===== -->
<section class="location" id="location">
  <div class="location-text">
    <p class="eyebrow">Location</p>
    <h2>Tea Garden, Mae Sariang District<br>Chiang Rai Province</h2>
    <p>45 minutes from Chiang Rai city center. Scenic winding road with good conditions for a comfortable drive. Parking available on-site.</p>
    <ul class="location-list">
      <li>55 km from Mae Fah Luang Airport</li>
      <li>8 km from Mae Sariang Market</li>
      <li>Transfer services from the city center available at an additional cost</li>
    </ul>
  </div>
  <div class="location-map" aria-hidden="true">
    <svg viewBox="0 0 400 300" class="map-sketch">
      <path d="M20,250 Q100,180 160,220 T280,190 Q340,170 380,210" class="map-road"/>
      <circle cx="220" cy="150" r="6" class="map-pin"/>
      <path d="M0,300 Q80,220 180,260 T400,240 L400,300 Z" class="map-hill"/>
    </svg>
  </div>
</section>

<!-- ===== FOOTER ===== -->
<footer class="site-footer" id="contact">
  <div class="footer-inner">
    <div class="footer-brand">
      <span class="logo-text">Log Horizon | <em>Hotels Chiang Rai</em></span>
      <p>123 หมู่ 4 ต.ศรีถ้อย อ.แม่สรวย จ.เชียงราย 57180</p>
    </div>
    <div class="footer-col">
      <h4>Contact</h4>
      <p>Phone 08x-xxx-xxxx</p>
      <p>[email protected]</p>
    </div>
    <div class="footer-col">
      <h4>User Account</h4>
      <?php if ($isLoggedIn): ?>
        <a href="logout.php">Logout</a>
      <?php else: ?>
        <a href="login.php">Login / Register</a>
      <?php endif; ?>
    </div>
  </div>
  <p class="footer-note">© 2569 Log Horizon | Hotels Chiang Rai สงวนลิขสิทธิ์</p>
</footer>

<script src="script.js"></script>
</body>
</html>
