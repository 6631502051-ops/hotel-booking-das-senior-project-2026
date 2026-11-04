<?php
require_once 'config/security.php';
require_once 'config/db.php';
require_once 'config/payment.php';

require_user_login();

$bookingId = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : (int)($_POST['booking_id'] ?? 0);
$userId = (int)$_SESSION['id_account'];

if ($bookingId <= 0) {
  $_SESSION['error'] = 'ไม่พบข้อมูลการจอง';
  header('Location: booking.php');
  exit();
}

try {
  $bookingStmt = $conn->prepare("SELECT
    b.booking_id,
    b.user_id,
    b.member,
    b.check_in,
    b.check_out,
    b.booking_status,
    r.room_number,
    a.firstname,
    a.lastname
    FROM booking_info b
    LEFT JOIN room r ON b.room_count = r.room_id
    LEFT JOIN account a ON b.user_id = a.id_account
    WHERE b.booking_id = :booking_id AND b.user_id = :user_id");
  $bookingStmt->bindParam(':booking_id', $bookingId, PDO::PARAM_INT);
  $bookingStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
  $bookingStmt->execute();
  $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  $_SESSION['error'] = 'ไม่สามารถดึงข้อมูลการจองได้';
  header('Location: booking.php');
  exit();
}

if (!$booking) {
  $_SESSION['error'] = 'ไม่พบข้อมูลการจองของคุณ';
  header('Location: booking.php');
  exit();
}

$displayName = trim(($booking['firstname'] ?? '') . ' ' . ($booking['lastname'] ?? ''));
if ($displayName === '') {
  $displayName = $_SESSION['firstname'] ?? 'Guest';
}

$checkin = $booking['check_in'];
$checkout = $booking['check_out'];
$guests = (string)($booking['member'] ?? '1');
$roomLabel = 'ห้อง ' . ($booking['room_number'] ?? '-');
$nightlyPrice = 10;

try {
  $inDate = new DateTime($checkin);
  $outDate = new DateTime($checkout);
  $diffDays = (int)$inDate->diff($outDate)->days;
  $nights = max(1, $diffDays);
} catch (Exception $e) {
  $nights = 1;
}

$totalAmount = $nightlyPrice * $nights;

function emvQrCrc(string $payload): string
{
  $crc = 0xFFFF;
  $length = strlen($payload);

  for ($i = 0; $i < $length; $i++) {
    $crc ^= ord($payload[$i]) << 8;

    for ($j = 0; $j < 8; $j++) {
      if (($crc & 0x8000) !== 0) {
        $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
      } else {
        $crc = ($crc << 1) & 0xFFFF;
      }
    }
  }

  return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
}

function buildQrPayload(float $amount): string
{
  $amountText = number_format($amount, 2, '.', '');
  $payloadWithoutCrc = '00020101021129370016A00000067701011101130066944562017530376454'
    . str_pad((string)strlen($amountText), 2, '0', STR_PAD_LEFT)
    . $amountText
    . '5802TH6304';

  return $payloadWithoutCrc . emvQrCrc($payloadWithoutCrc);
}

$qrPayload = buildQrPayload((float)$totalAmount);
$qrCodeUrl = 'https://quickchart.io/qr?size=320&margin=2&text=' . urlencode($qrPayload);

$bookingStatus = $booking['booking_status'] ?? 'active';

// Fallback for browsers without JavaScript. Normally the button calls
// api/payment-request.php with fetch() (see the script at the end of the page).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
  require_csrf();
  $result = submit_payment_request($conn, $bookingId, $userId);
  $_SESSION[$result['ok'] ? 'success' : 'error'] = $result['ok']
    ? 'ส่งคำขอชำระเงินแล้ว การจองจะสำเร็จเมื่อแอดมินยืนยันการชำระเงิน'
    : $result['message'];
  header('Location: dashboard.php');
  exit();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payment | Log Horizon</title>
<meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;0,9..144,600;1,9..144,400&family=Work+Sans:wght@300;400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body>
<header class="site-header" id="siteHeader">
  <div class="header-inner">
    <a href="index.php" class="logo">
      <span class="logo-mark">L</span>
      <span class="logo-text">Log Horizon <em>Hotels</em></span>
    </a>

    <nav class="main-nav" id="mainNav">
      <a href="index.php#rooms">Room</a>
      <a href="index.php#amenities">Facilities</a>
      <a href="index.php#location">Location</a>
      <a href="index.php#contact">Contact Us</a>
    </nav>

    <div class="header-actions">
      <span class="user-badge">สวัสดี, <?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></span>
      <a href="logout.php" class="btn btn-ghost btn-logout">Logout</a>
      <button class="nav-toggle" id="navToggle" aria-label="เปิดเมนู">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>
</header>

<main class="payment-main">
  <div class="payment-wrap">
    <section class="payment-head">
      <h1>ยืนยันการชำระเงิน</h1>
      <p>ทำรายการจ่ายเงินให้เสร็จเพื่อยืนยันการจองห้องพักของคุณ</p>
    </section>

    <section class="payment-grid">
      <article class="payment-card-surface">
        <h2 class="payment-title">สรุปรายการ</h2>
        <div class="payment-list">
          <div class="payment-item">
            <span class="payment-label">สถานะ</span>
            <span class="payment-value"><?php echo htmlspecialchars(booking_status_label($bookingStatus), ENT_QUOTES, 'UTF-8'); ?></span>
          </div>
          <div class="payment-item">
            <span class="payment-label">ผู้ทำรายการ</span>
            <span class="payment-value"><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></span>
          </div>
          <div class="payment-item">
            <span class="payment-label">ห้อง</span>
            <span class="payment-value"><?php echo htmlspecialchars($roomLabel, ENT_QUOTES, 'UTF-8'); ?></span>
          </div>
          <div class="payment-item">
            <span class="payment-label">เช็คอิน</span>
            <span class="payment-value"><?php echo $checkin !== '' ? htmlspecialchars($checkin, ENT_QUOTES, 'UTF-8') : '-'; ?></span>
          </div>
          <div class="payment-item">
            <span class="payment-label">เช็คเอาท์</span>
            <span class="payment-value"><?php echo $checkout !== '' ? htmlspecialchars($checkout, ENT_QUOTES, 'UTF-8') : '-'; ?></span>
          </div>
          <div class="payment-item">
            <span class="payment-label">ผู้เข้าพัก</span>
            <span class="payment-value"><?php echo htmlspecialchars($guests, ENT_QUOTES, 'UTF-8'); ?> คน</span>
          </div>
        </div>

        <div class="payment-total">
          <div class="payment-label">ยอดรวมโดยประมาณ</div>
          <div class="amount">฿<?php echo number_format($totalAmount); ?></div>
          <div class="payment-note">คำนวณจาก <?php echo $nights; ?> คืน x ฿<?php echo number_format($nightlyPrice); ?>/คืน</div>
        </div>
      </article>

      <article class="payment-card-surface qr-panel">
        <h2 class="payment-title">ช่องทางชำระเงิน</h2>
        <div class="qr-box">
          <img src="<?php echo htmlspecialchars($qrCodeUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Payment QR Code">
        </div>
        <p class="payment-note">สแกนเพื่อจ่ายเงินตามยอดด้านซ้าย แล้วกดยืนยัน</p>

        <?php if ($bookingStatus === 'pending_payment'): ?>
        <form method="post" class="payment-actions" id="paymentForm" data-booking-id="<?php echo (int)$bookingId; ?>">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="booking_id" value="<?php echo (int)$bookingId; ?>">
          <a href="dashboard.php" class="btn btn-outline">ชำระภายหลัง</a>
          <button type="submit" name="confirm_payment" class="btn btn-primary" id="payButton">ยืนยันชำระเงิน</button>
        </form>
        <p class="payment-note" id="paymentStatus" role="status" aria-live="polite">นี่คือการจำลองการชำระเงิน หลังกดยืนยัน คำขอจะถูกส่งไปให้แอดมินตรวจสอบ</p>
        <?php else: ?>
        <p class="payment-note">สถานะการจองนี้: <strong><?php echo htmlspecialchars(booking_status_label($bookingStatus), ENT_QUOTES, 'UTF-8'); ?></strong></p>
        <div class="payment-actions"><a href="dashboard.php" class="btn btn-primary">ไปที่บัญชีของฉัน</a></div>
        <?php endif; ?>
      </article>
    </section>
  </div>
</main>

<script src="script.js"></script>
<script src="payment.js"></script>
</body>
</html>
