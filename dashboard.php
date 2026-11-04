<?php
require_once 'config/security.php';
require_once 'config/db.php';

require_user_login();
enforce_not_banned($conn, (int)$_SESSION['id_account']);

$userId = (int)$_SESSION['id_account'];
$userStmt = $conn->prepare('SELECT firstname, lastname, email_account FROM account WHERE id_account = :user_id');
$userStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
$userStmt->execute();
$user = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$bookingStmt = $conn->prepare("SELECT
    b.booking_id, b.member, b.check_in, b.check_out, b.booking_status,
    b.access_code, b.access_code_updated_at, b.temporary_code, b.temporary_code_expires_at,
    r.room_number
    FROM booking_info b
    LEFT JOIN room r ON b.room_count = r.room_id
    WHERE b.user_id = :user_id
    ORDER BY b.booking_id DESC");
$bookingStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
$bookingStmt->execute();
$bookings = $bookingStmt->fetchAll(PDO::FETCH_ASSOC);

$displayName = trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')) ?: 'Guest';
$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
$issuedCode = $_SESSION['issued_code'] ?? null;
unset($_SESSION['success'], $_SESSION['error'], $_SESSION['issued_code']);

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>บัญชีของฉัน | Log Horizon</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;0,9..144,600;1,9..144,400&family=Work+Sans:wght@300;400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="dashboard.css">
</head>
<body>
<header class="site-header dashboard-header">
  <div class="header-inner">
    <a href="index.php" class="logo"><span class="logo-mark">L</span><span class="logo-text">Log Horizon <em>Hotels</em></span></a>
    <nav class="main-nav"><a href="index.php#rooms">Room</a><a href="booking.php">จองห้องพัก</a><a href="index.php#contact">Contact Us</a></nav>
    <div class="header-actions"><a href="logout.php" class="btn btn-ghost btn-logout">Logout</a></div>
  </div>
</header>

<main class="dashboard-main">
  <div class="dashboard-wrap">
    <section class="dashboard-intro">
      <div><p class="eyebrow">Guest account</p><h1>สวัสดี, <?php echo e($displayName); ?></h1><p>จัดการการจองและรหัสเข้าห้องพักของคุณได้จากหน้านี้</p></div>
      <a href="booking.php" class="btn btn-primary">จองห้องพักเพิ่ม</a>
    </section>

    <?php if ($success): ?><div class="dashboard-alert alert-success"><?php echo e($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="dashboard-alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>
    <?php if ($issuedCode): ?><div class="dashboard-alert code-alert">รหัสที่สร้างล่าสุด: <strong><?php echo e($issuedCode); ?></strong> กรุณาจดรหัสนี้ไว้</div><?php endif; ?>

    <section class="dashboard-panel">
      <div class="panel-heading"><div><p class="eyebrow">Your stays</p><h2>การจองของฉัน</h2></div><span class="booking-count"><?php echo count($bookings); ?> รายการ</span></div>
      <?php if (!$bookings): ?>
        <div class="dashboard-empty">ยังไม่มีการจอง <a href="booking.php">เริ่มเลือกห้องพัก</a></div>
      <?php else: ?>
        <div class="booking-cards">
        <?php foreach ($bookings as $booking):
          $eligible = booking_is_active_now($booking);
          $status = $booking['booking_status'] ?? 'active';
          $cancelled = in_array($status, ['cancelled', 'rejected'], true);
          $pillClass = $cancelled ? 'status-cancelled' : ($status === 'active' ? 'status-active' : 'status-pending');
          $tempActive = $eligible && !empty($booking['temporary_code']) && !empty($booking['temporary_code_expires_at']) && $booking['temporary_code_expires_at'] > date('Y-m-d H:i:s');
        ?>
          <article class="booking-card <?php echo $cancelled ? 'is-cancelled' : ''; ?>">
            <div class="booking-card-top"><div><span class="booking-room">ห้อง <?php echo e($booking['room_number'] ?? '-'); ?></span><span class="booking-id">#<?php echo (int)$booking['booking_id']; ?></span></div><span class="status-pill <?php echo $pillClass; ?>"><?php echo e(booking_status_label($status)); ?></span></div>
            <div class="booking-dates"><div><small>เช็คอิน</small><strong><?php echo e($booking['check_in']); ?></strong></div><div class="date-arrow">→</div><div><small>เช็คเอาท์</small><strong><?php echo e($booking['check_out']); ?></strong></div></div>
            <p class="booking-meta">ผู้เข้าพัก <?php echo (int)$booking['member']; ?> คน</p>
            <?php if (!$cancelled && $eligible): ?>
              <div class="access-box">
                <div class="access-box-head"><div><small>รหัสเข้าห้องหลัก</small><strong class="access-code"><?php echo e($booking['access_code'] ?: 'ยังไม่ได้สร้าง'); ?></strong></div><span class="live-dot">พร้อมใช้งาน</span></div>
                <div class="code-actions">
                  <form action="room_code.php" method="post"><?php echo csrf_field(); ?><input type="hidden" name="booking_id" value="<?php echo (int)$booking['booking_id']; ?>"><input type="hidden" name="action" value="generate_main"><button class="btn btn-outline" type="submit"><?php echo $booking['access_code'] ? 'ขอเปลี่ยนรหัสหลัก' : 'สร้างรหัสเข้าห้อง'; ?></button></form>
                  <form action="room_code.php" method="post"><?php echo csrf_field(); ?><input type="hidden" name="booking_id" value="<?php echo (int)$booking['booking_id']; ?>"><input type="hidden" name="action" value="generate_temporary"><button class="btn btn-quiet" type="submit">สร้างรหัสชั่วคราว</button></form>
                </div>
                <?php if ($tempActive): ?><p class="temporary-code">รหัสชั่วคราว <?php echo e($booking['temporary_code']); ?> หมดอายุ <?php echo e($booking['temporary_code_expires_at']); ?></p><?php endif; ?>
              </div>
            <?php elseif ($status === 'pending_payment'): ?>
              <div class="access-notice">ยังไม่ได้ชำระเงิน <a href="payment.php?booking_id=<?php echo (int)$booking['booking_id']; ?>">ไปหน้าชำระเงิน</a></div>
            <?php elseif ($status === 'awaiting_approval'): ?>
              <div class="access-notice">ส่งคำขอชำระเงินแล้ว กำลังรอแอดมินยืนยัน</div>
            <?php elseif ($status === 'rejected'): ?>
              <div class="access-notice">แอดมินปฏิเสธการชำระเงิน การจองนี้ไม่สำเร็จ</div>
            <?php elseif (!$cancelled): ?>
              <div class="access-notice">ปุ่มสร้างรหัสเข้าห้องจะแสดงเมื่อถึงวันเช็คอิน</div>
            <?php endif; ?>
            <?php if (!$cancelled): ?><form action="cancel_booking.php" method="post" class="cancel-form"><?php echo csrf_field(); ?><input type="hidden" name="booking_id" value="<?php echo (int)$booking['booking_id']; ?>"><button type="submit" class="cancel-link" onclick="return confirm('การยกเลิกจะไม่คืนเงิน ยืนยันหรือไม่?')">ยกเลิกการจอง (ไม่คืนเงิน)</button></form><?php endif; ?>
          </article>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
</main>
</body>
</html>
