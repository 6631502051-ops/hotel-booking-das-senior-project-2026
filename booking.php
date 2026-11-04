<?php
require_once 'config/security.php';
require_once 'config/db.php';

require_user_login();
enforce_not_banned($conn, (int)$_SESSION['id_account']);

$user_stmt = $conn->prepare("SELECT firstname, lastname FROM account WHERE id_account = :id_account");
$user_stmt->bindParam(':id_account', $_SESSION['id_account'], PDO::PARAM_INT);
$user_stmt->execute();
$row = $user_stmt->fetch(PDO::FETCH_ASSOC) ?: ['firstname' => '', 'lastname' => ''];

$display_name = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
if ($display_name === '') {
    $display_name = 'Guest';
}

$search_checkin = trim($_GET['checkin'] ?? '');
$search_checkout = trim($_GET['checkout'] ?? '');
$search_guests = trim($_GET['guests'] ?? '');
$search_requested = isset($_GET['search']);
$available_rooms = [];

$room_profiles = [
  'R-01' => ['name' => 'ห้องยอดหมอก', 'view' => 'ทิศตะวันออก', 'size' => 28, 'price' => 10, 'desc' => 'ห้องมุมสูงสุดของอาคาร มองเห็นทะเลหมอกยามเช้าเต็มผืน มีระเบียงกว้างพร้อมเก้าอี้ผ้าใบ', 'tone' => 'linear-gradient(135deg,#2C4E3C,#1B3A2F)'],
  'R-02' => ['name' => 'ห้องเชิงเขา', 'view' => 'ทิศใต้', 'size' => 26, 'price' => 10, 'desc' => 'อยู่ชั้นล่างติดสวน เดินออกไปถึงทางเดินป่าได้โดยตรง เหมาะกับคนชอบตื่นเช้าไปเดินเล่น', 'tone' => 'linear-gradient(135deg,#3E6350,#1B3A2F)'],
  'R-03' => ['name' => 'ห้องริมไร่ชา', 'view' => 'ทิศตะวันตก', 'size' => 30, 'price' => 10, 'desc' => 'หน้าต่างบานใหญ่หันหาไร่ชาโดยตรง ตกเย็นแสงส้มลอดใบชาสวยที่สุดของที่พัก', 'tone' => 'linear-gradient(135deg,#4A6B4E,#22452F)'],
  'R-04' => ['name' => 'ห้องลมเหนือ', 'view' => 'ทิศเหนือ', 'size' => 24, 'price' => 10, 'desc' => 'ห้องเล็กกะทัดรัด ลมเย็นตลอดวัน มีเตาผิงในตัวสำหรับคืนที่อากาศลงต่ำ', 'tone' => 'linear-gradient(135deg,#355246,#152F25)'],
  'R-05' => ['name' => 'ห้องแสงแรก', 'view' => 'ทิศตะวันออกเฉียงเหนือ', 'size' => 32, 'price' => 10, 'desc' => 'ห้องครอบครัวขนาดใหญ่ที่สุด แบ่งเป็นสองระดับ รับแสงแรกของวันเต็มห้องนอนใหญ่', 'tone' => 'linear-gradient(135deg,#5A7A55,#1B3A2F)'],
  'R-06' => ['name' => 'ห้องดาวเหนือ', 'view' => 'ดาดฟ้าเปิด', 'size' => 27, 'price' => 10, 'desc' => 'หลังคาส่วนหนึ่งเป็นกระจกใส มองเห็นดาวจากบนเตียงได้ในคืนฟ้าใส', 'tone' => 'linear-gradient(135deg,#233F30,#0F241B)']
];

function room_profile_for_number($roomNumber, $roomProfiles)
{
  $roomNumberText = trim((string)$roomNumber);
  $normalized = strtoupper($roomNumberText);

  if (isset($roomProfiles[$normalized])) {
    return $roomProfiles[$normalized];
  }

  if (is_numeric($roomNumberText)) {
    $padded = 'R-' . str_pad((string)((int)$roomNumberText), 2, '0', STR_PAD_LEFT);
    if (isset($roomProfiles[$padded])) {
      return $roomProfiles[$padded];
    }
  }

  if (preg_match('/\d+/', $roomNumberText, $matches) === 1) {
    $padded = 'R-' . str_pad((string)((int)$matches[0]), 2, '0', STR_PAD_LEFT);
    if (isset($roomProfiles[$padded])) {
      return $roomProfiles[$padded];
    }
  }

  return ['name' => 'ห้องพัก', 'view' => '-', 'size' => 25, 'price' => 10, 'desc' => 'ห้องพักพร้อมให้บริการในช่วงวันที่ที่คุณเลือก', 'tone' => 'linear-gradient(135deg,#355246,#152F25)'];
}

if ($search_requested) {
    if ($search_checkin === '') {
        $_SESSION['error'] = 'กรุณากรอกวันที่เช็คอิน';
    } elseif ($search_checkout === '') {
        $_SESSION['error'] = 'กรุณากรอกวันที่เช็คเอ้าท์';
    } elseif (strtotime($search_checkin) >= strtotime($search_checkout)) {
        $_SESSION['error'] = 'วันที่เช็คเอ้าท์ต้องมากกว่าวันที่เช็คอิน';
    } else {
        $available_stmt = $conn->prepare("SELECT room_id, room_number FROM room WHERE NOT EXISTS (
            SELECT 1
            FROM booking_info b
            WHERE b.room_count = room.room_id
            AND b.booking_status IN (" . BOOKING_HOLDING_STATUSES_SQL . ")
            AND b.check_in < :checkout
            AND b.check_out > :checkin
        ) ORDER BY room_number ASC");
        $available_stmt->bindParam(':checkin', $search_checkin);
        $available_stmt->bindParam(':checkout', $search_checkout);
        $available_stmt->execute();
        $available_rooms = $available_stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($available_rooms) === 0) {
            $_SESSION['error'] = 'ไม่มีห้องว่างในช่วงวันที่ที่เลือก';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Booking | Log Horizon</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,400&family=Work+Sans:wght@300;400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="booking.css">
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
        <span class="user-badge">สวัสดี, <?php echo htmlspecialchars($display_name); ?></span>
        <a href="dashboard.php" class="btn btn-ghost">บัญชีของฉัน</a>
        <a href="logout.php" class="btn btn-ghost btn-logout">Logout</a>
        <button class="nav-toggle" id="navToggle" aria-label="เปิดเมนู">
          <span></span><span></span><span></span>
        </button>
      </div>
    </div>
  </header>

  <main class="booking-main">
    <div class="booking-wrap">
      <section class="booking-hero">
        <p class="eyebrow">Smart Booking</p>
        <h1>ค้นหาและจองห้องพักของคุณ</h1>
        <p>เลือกช่วงวันเข้าพัก แล้วระบบจะแสดงห้องว่างที่พร้อมจองทันที</p>
      </section>

      <?php if (isset($_SESSION['error'])) { ?>
        <div class="alert-banner alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
      <?php } ?>

      <?php if (isset($_SESSION['success'])) { ?>
        <div class="alert-banner alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
      <?php } ?>

      <section class="booking-search">
        <form action="booking.php" method="GET" class="booking-search-grid">
          <div class="field-group">
            <label for="checkin">เช็คอิน</label>
            <input type="date" id="checkin" name="checkin" value="<?php echo htmlspecialchars($search_checkin); ?>" required>
          </div>
          <div class="field-group">
            <label for="checkout">เช็คเอ้าท์</label>
            <input type="date" id="checkout" name="checkout" value="<?php echo htmlspecialchars($search_checkout); ?>" required>
          </div>
          <div class="field-group">
            <label for="guests_search">จำนวนผู้เข้าพัก</label>
            <select id="guests_search" name="guests" required>
              <option value="" disabled <?php echo $search_guests === '' ? 'selected' : ''; ?>>เลือกจำนวนคน</option>
              <option value="1" <?php echo $search_guests === '1' ? 'selected' : ''; ?>>1 คน</option>
              <option value="2" <?php echo $search_guests === '2' ? 'selected' : ''; ?>>2 คน</option>
              <option value="3" <?php echo $search_guests === '3' ? 'selected' : ''; ?>>3 คน</option>
              <option value="4" <?php echo $search_guests === '4' ? 'selected' : ''; ?>>4 คน</option>
            </select>
          </div>
          <button type="submit" name="search" value="1" class="btn btn-primary">ค้นหาห้องว่าง</button>
        </form>
      </section>

      <section class="booking-layout">
        <aside class="panel history-panel">
          <div class="panel-head">
            <h2>ประวัติการจอง</h2>
          </div>

          <?php
          $booking_stmt = $conn->prepare("SELECT * FROM booking_info WHERE user_id = :user_id ORDER BY booking_id DESC");
          $booking_stmt->bindParam(':user_id', $_SESSION['id_account'], PDO::PARAM_INT);
          $booking_stmt->execute();

          if ($booking_stmt->rowCount() > 0) {
              echo '<div class="table-wrap"><table class="history-table">';
              echo '<thead><tr><th>ห้อง</th><th>เช็คอิน</th><th>เช็คเอ้าท์</th><th>สถานะ</th><th>จัดการ</th></tr></thead>';
              echo '<tbody>';

              while ($booking_row = $booking_stmt->fetch(PDO::FETCH_ASSOC)) {
                  $room_detail_stmt = $conn->prepare("SELECT room_number FROM room WHERE room_id = :room_id");
                  $room_detail_stmt->bindParam(':room_id', $booking_row['room_count']);
                  $room_detail_stmt->execute();
                  $room_detail = $room_detail_stmt->fetch(PDO::FETCH_ASSOC);
                  $room_number = $room_detail['room_number'] ?? 'N/A';

                  echo '<tr>';
                    $rowStatus = $booking_row['booking_status'] ?? 'active';
                    $isCancelled = in_array($rowStatus, ['cancelled', 'rejected'], true);
                    echo '<td>ห้อง ' . htmlspecialchars($room_number) . '</td>';
                  echo '<td>' . htmlspecialchars($booking_row['check_in']) . '</td>';
                  echo '<td>' . htmlspecialchars($booking_row['check_out']) . '</td>';
                    echo '<td>' . htmlspecialchars(booking_status_label($rowStatus));
                    if ($rowStatus === 'pending_payment') {
                      echo ' <a href="payment.php?booking_id=' . (int)$booking_row['booking_id'] . '">ชำระเงิน</a>';
                    }
                    echo '</td>';
                    echo '<td>';
                    if (!$isCancelled) {
                      echo '<form action="cancel_booking.php" method="post">' . csrf_field() . '<input type="hidden" name="booking_id" value="' . (int)$booking_row['booking_id'] . '"><button type="submit" class="cancel-link" onclick="return confirm(\'การยกเลิกจะไม่คืนเงิน ยืนยันหรือไม่?\')">ยกเลิก (ไม่คืนเงิน)</button></form>';
                    } else {
                      echo '-';
                    }
                    echo '</td>';
                  echo '</tr>';
              }

              echo '</tbody></table></div>';
          } else {
              echo '<div class="empty-state">ไม่มีประวัติการจอง</div>';
          }
          ?>
        </aside>

        <section class="panel result-panel">
          <div class="panel-head">
            <h2>ผลการค้นหา</h2>
            <?php if (count($available_rooms) > 0) { ?>
              <span class="count-badge"><?php echo count($available_rooms); ?> ห้องว่าง</span>
            <?php } ?>
          </div>

          <?php if ($search_requested && $search_checkin !== '' && $search_checkout !== '' && strtotime($search_checkin) < strtotime($search_checkout)) { ?>
            <?php if (count($available_rooms) > 0) { ?>
              <div class="room-grid">
                <?php foreach ($available_rooms as $available_room) { ?>
                  <?php
                    $profile = room_profile_for_number($available_room['room_number'], $room_profiles);
                    $roomLabel = trim((string)$available_room['room_number']);
                    if ($roomLabel === '') {
                        $roomLabel = 'N/A';
                    }
                  ?>
                  <form action="booking_db.php" method="POST" class="room-card">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="checkin" value="<?php echo htmlspecialchars($search_checkin); ?>">
                    <input type="hidden" name="checkout" value="<?php echo htmlspecialchars($search_checkout); ?>">
                    <input type="hidden" name="guests" value="<?php echo htmlspecialchars($search_guests); ?>">
                    <input type="hidden" name="room" value="<?php echo htmlspecialchars($available_room['room_id']); ?>">

                    <div class="room-visual" style="background:<?php echo htmlspecialchars($profile['tone']); ?>">
                      <span class="room-code"><?php echo htmlspecialchars($roomLabel); ?></span>
                      <span class="room-capacity"><?php echo (int)$profile['size']; ?> ตร.ม.</span>
                    </div>

                    <div class="room-body">
                      <h3><?php echo htmlspecialchars($profile['name']); ?></h3>
                      <p><?php echo htmlspecialchars($profile['desc']); ?></p>
                      <div class="room-meta">
                        <span class="room-price">฿<?php echo number_format((int)$profile['price']); ?><span> / คืน</span></span>
                        <span class="room-code" style="color:var(--sage)"><?php echo htmlspecialchars($profile['view']); ?></span>
                      </div>
                      <div class="booking-room-note">
                        <?php echo htmlspecialchars($search_checkin); ?> - <?php echo htmlspecialchars($search_checkout); ?> · <?php echo htmlspecialchars($search_guests); ?> คน
                      </div>
                      <div class="room-actions">
                        <button type="submit" name="submit" class="btn btn-primary">จองห้องนี้</button>
                      </div>
                    </div>
                  </form>
                <?php } ?>
              </div>
            <?php } else { ?>
              <div class="empty-state">ไม่มีห้องว่างในช่วงวันที่ที่เลือก</div>
            <?php } ?>
          <?php } else { ?>
            <div class="empty-state">กรุณาเลือกวันที่และจำนวนผู้เข้าพักเพื่อค้นหาห้องว่าง</div>
          <?php } ?>
        </section>
      </section>
    </div>
  </main>

  <script src="script.js"></script>
</body>
</html>
