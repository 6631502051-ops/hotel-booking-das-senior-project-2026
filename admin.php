<?php
require_once 'config/security.php';
require_once 'config/db.php';

require_admin_login();

function updateRoomStatus(PDO $conn, int $roomId): void
{
    $count_stmt = $conn->prepare("SELECT COUNT(*) AS booking_count FROM booking_info WHERE room_count = :room_id AND booking_status = 'active'");
    $count_stmt->bindParam(':room_id', $roomId, PDO::PARAM_INT);
    $count_stmt->execute();
    $count_row = $count_stmt->fetch(PDO::FETCH_ASSOC);

    $room_status = ((int)($count_row['booking_count'] ?? 0) > 0) ? 1 : 0;
    $status_stmt = $conn->prepare("UPDATE room SET room_status = :room_status WHERE room_id = :room_id");
    $status_stmt->bindParam(':room_status', $room_status, PDO::PARAM_INT);
    $status_stmt->bindParam(':room_id', $roomId, PDO::PARAM_INT);
    $status_stmt->execute();
}

if (isset($_POST['toggle_ban_id'])) {
    require_csrf();
    $target_user_id = (int)$_POST['toggle_ban_id'];

    try {
        if ($target_user_id === (int)$_SESSION['admin_login']) {
            throw new Exception('ไม่สามารถแบนบัญชีตัวเองได้');
        }

        $target_stmt = $conn->prepare("SELECT role_account, ban_account FROM account WHERE id_account = :user_id");
        $target_stmt->bindParam(':user_id', $target_user_id, PDO::PARAM_INT);
        $target_stmt->execute();
        $target_user = $target_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$target_user) {
            throw new Exception('ไม่พบข้อมูลผู้ใช้');
        }

        if (($target_user['role_account'] ?? '') === 'admin') {
            throw new Exception('ไม่สามารถจัดการแบนบัญชีแอดมินได้');
        }

        $new_ban_status = ((int)$target_user['ban_account'] === 1) ? 0 : 1;
        $ban_stmt = $conn->prepare("UPDATE account SET ban_account = :ban_account WHERE id_account = :user_id");
        $ban_stmt->bindParam(':ban_account', $new_ban_status, PDO::PARAM_INT);
        $ban_stmt->bindParam(':user_id', $target_user_id, PDO::PARAM_INT);
        $ban_stmt->execute();

        $_SESSION['success'] = $new_ban_status === 1 ? 'แบนผู้ใช้สำเร็จ' : 'ปลดแบนผู้ใช้สำเร็จ';
    } catch (Throwable $e) {
        $_SESSION['error'] = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
    }

    header('location: admin.php');
    exit();
}

if (isset($_POST['delete_id'])) {
    require_csrf();
    $booking_id = (int)$_POST['delete_id'];

    try {
        $conn->beginTransaction();

        $booking_stmt = $conn->prepare("SELECT room_count FROM booking_info WHERE booking_id = :booking_id");
        $booking_stmt->bindParam(':booking_id', $booking_id, PDO::PARAM_INT);
        $booking_stmt->execute();
        $booking_row = $booking_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking_row) {
            throw new Exception('ไม่พบข้อมูลการจอง');
        }

        $delete_stmt = $conn->prepare("DELETE FROM booking_info WHERE booking_id = :booking_id");
        $delete_stmt->bindParam(':booking_id', $booking_id, PDO::PARAM_INT);
        $delete_stmt->execute();

        updateRoomStatus($conn, (int)$booking_row['room_count']);

        $conn->commit();
        $_SESSION['success'] = 'ลบข้อมูลการจองสำเร็จ';
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $_SESSION['error'] = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
    }

    header('location: admin.php');
    exit();
}

if (isset($_POST['save_booking'])) {
    require_csrf();
    $booking_id = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : 0;
    $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $room_id = isset($_POST['room_id']) ? (int)$_POST['room_id'] : 0;
    $checkin = trim($_POST['checkin'] ?? '');
    $checkout = trim($_POST['checkout'] ?? '');
    $guests = trim($_POST['guests'] ?? '');

    try {
        if ($user_id <= 0) {
            throw new Exception('กรุณาเลือกผู้ใช้');
        }
        if ($room_id <= 0) {
            throw new Exception('กรุณาเลือกห้องพัก');
        }
        if ($checkin === '') {
            throw new Exception('กรุณากรอกวันที่เช็คอิน');
        }
        if ($checkout === '') {
            throw new Exception('กรุณากรอกวันที่เช็คเอ้าท์');
        }
        if ($guests === '') {
            throw new Exception('กรุณาเลือกจำนวนผู้เข้าพัก');
        }
        if (strtotime($checkin) >= strtotime($checkout)) {
            throw new Exception('วันที่เช็คเอ้าท์ต้องมากกว่าวันที่เช็คอิน');
        }

        $account_stmt = $conn->prepare("SELECT firstname FROM account WHERE id_account = :user_id");
        $account_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $account_stmt->execute();
        $account_row = $account_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account_row) {
            throw new Exception('ไม่พบข้อมูลผู้ใช้');
        }

        $room_stmt = $conn->prepare("SELECT room_id FROM room WHERE room_id = :room_id");
        $room_stmt->bindParam(':room_id', $room_id, PDO::PARAM_INT);
        $room_stmt->execute();
        $room_row = $room_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$room_row) {
            throw new Exception('ไม่พบข้อมูลห้องพัก');
        }

        $conn->beginTransaction();

        if ($booking_id > 0) {
            $old_stmt = $conn->prepare("SELECT room_count FROM booking_info WHERE booking_id = :booking_id");
            $old_stmt->bindParam(':booking_id', $booking_id, PDO::PARAM_INT);
            $old_stmt->execute();
            $old_row = $old_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$old_row) {
                throw new Exception('ไม่พบข้อมูลการจองเดิม');
            }

            $update_stmt = $conn->prepare("UPDATE booking_info SET user_id = :user_id, name_id = :name_id, room_count = :room_count, member = :member, check_in = :check_in, check_out = :check_out WHERE booking_id = :booking_id");
            $update_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $update_stmt->bindParam(':name_id', $account_row['firstname']);
            $update_stmt->bindParam(':room_count', $room_id, PDO::PARAM_INT);
            $update_stmt->bindParam(':member', $guests);
            $update_stmt->bindParam(':check_in', $checkin);
            $update_stmt->bindParam(':check_out', $checkout);
            $update_stmt->bindParam(':booking_id', $booking_id, PDO::PARAM_INT);
            $update_stmt->execute();

            updateRoomStatus($conn, (int)$old_row['room_count']);
            updateRoomStatus($conn, $room_id);

            $_SESSION['success'] = 'แก้ไขข้อมูลการจองสำเร็จ';
        } else {
            $insert_stmt = $conn->prepare("INSERT INTO booking_info (user_id, name_id, room_count, member, check_in, check_out) VALUES (:user_id, :name_id, :room_count, :member, :check_in, :check_out)");
            $insert_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $insert_stmt->bindParam(':name_id', $account_row['firstname']);
            $insert_stmt->bindParam(':room_count', $room_id, PDO::PARAM_INT);
            $insert_stmt->bindParam(':member', $guests);
            $insert_stmt->bindParam(':check_in', $checkin);
            $insert_stmt->bindParam(':check_out', $checkout);
            $insert_stmt->execute();

            updateRoomStatus($conn, $room_id);

            $_SESSION['success'] = 'เพิ่มข้อมูลการจองสำเร็จ';
        }

        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $_SESSION['error'] = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
    }

    header('location: admin.php');
    exit();
}

$admin_id = (int)$_SESSION['admin_login'];
$admin_stmt = $conn->prepare("SELECT firstname, lastname FROM account WHERE id_account = :admin_id");
$admin_stmt->bindParam(':admin_id', $admin_id, PDO::PARAM_INT);
$admin_stmt->execute();
$admin_row = $admin_stmt->fetch(PDO::FETCH_ASSOC) ?: ['firstname' => '', 'lastname' => ''];

$users_stmt = $conn->query("SELECT id_account, firstname, lastname, email_account, role_account, ban_account FROM account ORDER BY firstname ASC, lastname ASC");
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

$rooms_stmt = $conn->query("SELECT room_id, room_number, room_status FROM room ORDER BY room_number ASC");
$rooms = $rooms_stmt->fetchAll(PDO::FETCH_ASSOC);

$status_date_input = $_GET['status_date'] ?? date('Y-m-d');
$status_date_obj = DateTime::createFromFormat('Y-m-d', $status_date_input);

if (!$status_date_obj || $status_date_obj->format('Y-m-d') !== $status_date_input) {
    $status_date_obj = new DateTime();
    $status_date_input = $status_date_obj->format('Y-m-d');
}

$status_date_display = $status_date_obj->format('d/m/Y');

$room_booking_stmt = $conn->prepare("SELECT
        b.room_count,
        b.check_in,
        b.check_out,
        a.firstname,
        a.lastname
    FROM booking_info b
    LEFT JOIN account a ON b.user_id = a.id_account
        WHERE b.booking_status = 'active'
            AND :status_date_from >= b.check_in AND :status_date_to < b.check_out");
// Two names for the same value: with ATTR_EMULATE_PREPARES = false a named
// placeholder cannot be used twice (it caused SQLSTATE[HY093] on admin.php).
$room_booking_stmt->bindValue(':status_date_from', $status_date_input);
$room_booking_stmt->bindValue(':status_date_to', $status_date_input);
$room_booking_stmt->execute();
$room_booking_rows = $room_booking_stmt->fetchAll(PDO::FETCH_ASSOC);

$occupied_rooms = [];
foreach ($room_booking_rows as $booking_row) {
    $room_key = (int)($booking_row['room_count'] ?? 0);
    if ($room_key <= 0) {
        continue;
    }

    $guest_name = trim(($booking_row['firstname'] ?? '') . ' ' . ($booking_row['lastname'] ?? ''));
    if ($guest_name === '') {
        $guest_name = '-';
    }

    $occupied_rooms[$room_key] = [
        'guest_name' => $guest_name,
        'period' => date('d/m/Y', strtotime($booking_row['check_in'])) . ' - ' . date('d/m/Y', strtotime($booking_row['check_out']))
    ];
}

$edit_booking = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $edit_stmt = $conn->prepare("SELECT booking_id, user_id, room_count, member, check_in, check_out FROM booking_info WHERE booking_id = :booking_id");
    $edit_stmt->bindParam(':booking_id', $edit_id, PDO::PARAM_INT);
    $edit_stmt->execute();
    $edit_booking = $edit_stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$edit_booking) {
        $_SESSION['error'] = 'ไม่พบข้อมูลการจองที่ต้องการแก้ไข';
        header('location: admin.php');
        exit();
    }
}

$booking_stmt = $conn->prepare("SELECT 
        b.booking_id,
        b.user_id,
        b.name_id,
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
    ORDER BY b.booking_id DESC");
$booking_stmt->execute();
$bookings = $booking_stmt->fetchAll(PDO::FETCH_ASSOC);

/* ---- ตัวแปรสำหรับสรุปสถิติบนแดชบอร์ด (คำนวณจากข้อมูลที่ดึงมาแล้วเท่านั้น ไม่มีการ query เพิ่ม) ---- */
$total_bookings = count($bookings);
$total_rooms = count($rooms);
$total_users = count($users);
$occupied_today_count = count($occupied_rooms);
$available_today_count = $total_rooms - $occupied_today_count;
$banned_users_count = 0;
foreach ($users as $u) {
    if ((int)($u['ban_account'] ?? 0) === 1) {
        $banned_users_count++;
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,400;0,9..144,500;1,9..144,400&family=Work+Sans:wght@300;400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="admin.css">
</head>
<body>

    <!-- ปุ่มเปิด/ปิด sidebar สำหรับหน้าจอขนาดเล็ก -->
    <button id="sidebarToggle" class="btn btn-brand d-lg-none position-fixed" style="top: 1rem; left: 1rem; z-index: 1100; border-radius: 10px;">
        <i class="bi bi-list"></i>
    </button>

    <!-- ================= Sidebar ================= -->
    <aside class="sidebar">
        <div class="brand">
            <i class="bi bi-building"></i>
            <span>Hotel Admin</span>
        </div>

        <div class="nav-section-label">เมนูหลัก</div>
        <nav>
            <a href="admin.php" class="nav-link active"><i class="bi bi-grid-1x2-fill"></i> แดชบอร์ด</a>
            <a href="#payment-panel" class="nav-link"><i class="bi bi-cash-coin"></i> รอยืนยันชำระเงิน <span class="badge bg-warning text-dark ms-1" id="pendingNavCount" hidden>0</span></a>
            <a href="#booking-panel" class="nav-link"><i class="bi bi-journal-check"></i> การจอง</a>
            <a href="#room-status-panel" class="nav-link"><i class="bi bi-door-open"></i> สถานะห้อง</a>
            <a href="#user-panel" class="nav-link"><i class="bi bi-people-fill"></i> ผู้ใช้งาน</a>
        </nav>

        <div class="admin-box">
            <div class="avatar"><?php echo htmlspecialchars(mb_substr(trim($admin_row['firstname']) !== '' ? $admin_row['firstname'] : 'A', 0, 1)); ?></div>
            <div>
                <div class="name"><?php echo htmlspecialchars(trim($admin_row['firstname'] . ' ' . $admin_row['lastname'])); ?></div>
                <div class="role">ผู้ดูแลระบบ</div>
            </div>
        </div>
        <a href="logout.php" class="logout-btn"><i class="bi bi-box-arrow-right me-1"></i> ออกจากระบบ</a>
    </aside>

    <!-- ================= Main ================= -->
    <div class="main">

        <div class="topbar">
            <div>
                <h2>สวัสดี, <?php echo htmlspecialchars(trim($admin_row['firstname'] . ' ' . $admin_row['lastname'])); ?> 👋</h2>
                <div class="sub">ภาพรวมและจัดการข้อมูลการจองของโรงแรม</div>
            </div>
        </div>

        <?php if (isset($_SESSION['error'])) { ?>
            <div class="alert alert-danger d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <div><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
            </div>
        <?php } ?>

        <?php if (isset($_SESSION['success'])) { ?>
            <div class="alert alert-success d-flex align-items-center gap-2">
                <i class="bi bi-check-circle-fill"></i>
                <div><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
            </div>
        <?php } ?>

        <!-- ================= Stat cards ================= -->
        <div class="row g-3 mb-2">
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="icon-wrap bg-indigo-soft"><i class="bi bi-journal-check"></i></div>
                    <div>
                        <div class="value"><?php echo (int)$total_bookings; ?></div>
                        <div class="label">การจองทั้งหมด</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="icon-wrap bg-green-soft"><i class="bi bi-door-open-fill"></i></div>
                    <div>
                        <div class="value"><?php echo (int)$available_today_count; ?> / <?php echo (int)$total_rooms; ?></div>
                        <div class="label">ห้องว่างวันนี้</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="icon-wrap bg-amber-soft"><i class="bi bi-people-fill"></i></div>
                    <div>
                        <div class="value"><?php echo (int)$total_users; ?></div>
                        <div class="label">ผู้ใช้ทั้งหมด</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="icon-wrap bg-red-soft"><i class="bi bi-person-fill-slash"></i></div>
                    <div>
                        <div class="value"><?php echo (int)$banned_users_count; ?></div>
                        <div class="label">ผู้ใช้ถูกแบน</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================= Payment requests waiting for approval ================= -->
        <!-- Filled by admin.js from api/admin-payments.php, refreshed every 5 seconds. -->
        <div class="panel" id="payment-panel">
            <div class="panel-header">
                <h5><i class="bi bi-cash-coin"></i> คำขอชำระเงินรอยืนยัน</h5>
                <span class="badge badge-soft-primary rounded-pill px-3 py-2"><span id="pendingCount">0</span> รายการ</span>
            </div>
            <div class="panel-body pt-0">
                <div id="paymentAlert" class="alert mb-3" role="status" aria-live="polite" hidden></div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#จอง</th>
                                <th>ผู้จอง</th>
                                <th>ห้อง</th>
                                <th>Check-in / Check-out</th>
                                <th>ยอดเงิน</th>
                                <th>เวลาที่ส่งคำขอ</th>
                                <th class="text-end">ยืนยันการชำระเงิน</th>
                            </tr>
                        </thead>
                        <tbody id="pendingPaymentsBody">
                            <tr><td colspan="7" class="text-muted">กำลังโหลด...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ================= Booking form + table ================= -->
        <div class="panel" id="booking-panel">
            <div class="panel-header">
                <h5><i class="bi bi-<?php echo $edit_booking ? 'pencil-square' : 'plus-circle'; ?>"></i> <?php echo $edit_booking ? 'แก้ไขข้อมูลการจอง' : 'เพิ่มข้อมูลการจอง'; ?></h5>
            </div>
            <div class="panel-body">
                <form method="POST" action="admin.php">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="booking_id" value="<?php echo htmlspecialchars($edit_booking['booking_id'] ?? ''); ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="user_id">ผู้ใช้</label>
                            <select class="form-select" id="user_id" name="user_id" required>
                                <option value="" disabled <?php echo !$edit_booking ? 'selected' : ''; ?>>-- เลือกผู้ใช้ --</option>
                                <?php foreach ($users as $user) { 
                                    $user_fullname = trim($user['firstname'] . ' ' . $user['lastname']);
                                    $selected = ($edit_booking && (int)$edit_booking['user_id'] === (int)$user['id_account']) ? 'selected' : '';
                                ?>
                                    <option value="<?php echo htmlspecialchars($user['id_account']); ?>" <?php echo $selected; ?>>
                                        <?php echo htmlspecialchars($user_fullname); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="room_id">ห้องพัก</label>
                            <select class="form-select" id="room_id" name="room_id" required>
                                <option value="" disabled <?php echo !$edit_booking ? 'selected' : ''; ?>>-- เลือกห้อง --</option>
                                <?php foreach ($rooms as $room) { 
                                    $selected = ($edit_booking && (int)$edit_booking['room_count'] === (int)$room['room_id']) ? 'selected' : '';
                                ?>
                                    <option value="<?php echo htmlspecialchars($room['room_id']); ?>" <?php echo $selected; ?>>
                                        ห้อง <?php echo htmlspecialchars($room['room_number']); ?><?php echo ((int)$room['room_status'] === 1) ? ' (มีการจอง)' : ''; ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="checkin">วันที่เช็คอิน</label>
                            <input type="date" class="form-control" id="checkin" name="checkin" value="<?php echo htmlspecialchars($edit_booking['check_in'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="checkout">วันที่เช็คเอ้าท์</label>
                            <input type="date" class="form-control" id="checkout" name="checkout" value="<?php echo htmlspecialchars($edit_booking['check_out'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="guests">จำนวนผู้เข้าพัก</label>
                            <select class="form-select" id="guests" name="guests" required>
                                <option value="" disabled <?php echo !$edit_booking ? 'selected' : ''; ?>>-- เลือกจำนวนคน --</option>
                                <option value="1" <?php echo (($edit_booking['member'] ?? '') === '1') ? 'selected' : ''; ?>>1 คน</option>
                                <option value="2" <?php echo (($edit_booking['member'] ?? '') === '2') ? 'selected' : ''; ?>>2 คน</option>
                                <option value="3" <?php echo (($edit_booking['member'] ?? '') === '3') ? 'selected' : ''; ?>>3 คน</option>
                                <option value="4" <?php echo (($edit_booking['member'] ?? '') === '4') ? 'selected' : ''; ?>>4 คน</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" name="save_booking" class="btn btn-brand">
                            <i class="bi bi-<?php echo $edit_booking ? 'save' : 'plus-lg'; ?> me-1"></i>
                            <?php echo $edit_booking ? 'บันทึกการแก้ไข' : 'เพิ่มการจอง'; ?>
                        </button>
                        <?php if ($edit_booking) { ?>
                            <a href="admin.php" class="btn btn-outline-secondary">ยกเลิก</a>
                        <?php } ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <h5><i class="bi bi-list-ul"></i> รายการจองของผู้ใช้</h5>
                <span class="badge badge-soft-primary rounded-pill px-3 py-2"><?php echo (int)$total_bookings; ?> รายการ</span>
            </div>
            <div class="panel-body pt-0">
                <?php if (count($bookings) > 0) { ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>ผู้จอง</th>
                                    <th>ห้อง</th>
                                    <th>จำนวนคน</th>
                                    <th>Check-in</th>
                                    <th>Check-out</th>
                                    <th>สถานะ</th>
                                    <th class="text-end">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bookings as $booking_row) {
                                    $guest_name = trim(($booking_row['firstname'] ?? '') . ' ' . ($booking_row['lastname'] ?? ''));
                                    if ($guest_name === '') {
                                        $guest_name = $booking_row['name_id'];
                                    }
                                ?>
                                    <tr>
                                        <td class="text-muted">#<?php echo htmlspecialchars($booking_row['booking_id']); ?></td>
                                        <td><?php echo htmlspecialchars($guest_name); ?></td>
                                        <td><span class="badge badge-soft-primary">ห้อง <?php echo htmlspecialchars($booking_row['room_number'] ?? '-'); ?></span></td>
                                        <td><?php echo htmlspecialchars($booking_row['member']); ?> คน</td>
                                        <td><?php echo htmlspecialchars($booking_row['check_in']); ?></td>
                                        <td><?php echo htmlspecialchars($booking_row['check_out']); ?></td>
                                        <td><span class="badge status-badge status-<?php echo htmlspecialchars($booking_row['booking_status'] ?? 'active'); ?>"><?php echo htmlspecialchars(booking_status_label($booking_row['booking_status'] ?? 'active')); ?></span></td>
                                        <td class="text-end">
                                            <a href="admin.php?edit_id=<?php echo htmlspecialchars($booking_row['booking_id']); ?>" class="btn btn-sm-icon btn-warning"><i class="bi bi-pencil"></i> แก้ไข</a>
                                            <form method="POST" action="admin.php" class="d-inline" data-confirm-form="ยืนยันการลบข้อมูลการจองนี้?"><?php echo csrf_field(); ?><input type="hidden" name="delete_id" value="<?php echo (int)$booking_row['booking_id']; ?>"><button type="submit" class="btn btn-sm-icon btn-danger"><i class="bi bi-trash"></i> ลบ</button></form>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php } else { ?>
                    <div class="alert alert-info mb-0">ยังไม่มีข้อมูลการจอง</div>
                <?php } ?>
            </div>
        </div>

        <!-- ================= Room status ================= -->
        <div class="panel" id="room-status-panel">
            <div class="panel-header">
                <h5><i class="bi bi-door-open"></i> เช็คสถานะห้อง</h5>
                <span class="badge bg-light text-dark border">วันที่ที่เลือก: <?php echo htmlspecialchars($status_date_display); ?></span>
            </div>
            <div class="panel-body">
                <form method="GET" action="admin.php" class="row g-2 align-items-end mb-3">
                    <div class="col-md-4">
                        <label for="status_date" class="form-label mb-1">เลือกวันที่ (Day/Month/Year)</label>
                        <input type="date" class="form-control" id="status_date" name="status_date" value="<?php echo htmlspecialchars($status_date_input); ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-brand w-100"><i class="bi bi-search me-1"></i>เช็คสถานะ</button>
                    </div>
                </form>

                <?php if (count($rooms) > 0) { ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>ห้อง</th>
                                    <th>สถานะ</th>
                                    <th>ผู้จอง</th>
                                    <th>ช่วงวันที่จอง</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rooms as $room) { ?>
                                    <?php
                                        $room_id = (int)$room['room_id'];
                                        $is_occupied = isset($occupied_rooms[$room_id]);
                                        $booking_guest = $is_occupied ? $occupied_rooms[$room_id]['guest_name'] : '-';
                                        $booking_period = $is_occupied ? $occupied_rooms[$room_id]['period'] : '-';
                                    ?>
                                    <tr>
                                        <td>ห้อง <?php echo htmlspecialchars($room['room_number']); ?></td>
                                        <td>
                                            <?php if ($is_occupied) { ?>
                                                <span class="badge badge-soft-danger"><i class="bi bi-x-circle-fill me-1"></i>ไม่ว่าง</span>
                                            <?php } else { ?>
                                                <span class="badge badge-soft-success"><i class="bi bi-check-circle-fill me-1"></i>ว่าง</span>
                                            <?php } ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($booking_guest); ?></td>
                                        <td><?php echo htmlspecialchars($booking_period); ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php } else { ?>
                    <div class="alert alert-info mb-0">ยังไม่มีข้อมูลห้องพัก</div>
                <?php } ?>
            </div>
        </div>

        <!-- ================= Users management ================= -->
        <div class="panel" id="user-panel">
            <div class="panel-header">
                <h5><i class="bi bi-people-fill"></i> จัดการผู้ใช้</h5>
                <span class="badge badge-soft-primary rounded-pill px-3 py-2"><?php echo (int)$total_users; ?> คน</span>
            </div>
            <div class="panel-body pt-0">
                <?php if (count($users) > 0) { ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>ชื่อ</th>
                                    <th>อีเมล</th>
                                    <th>บทบาท</th>
                                    <th>สถานะ</th>
                                    <th class="text-end">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user) { 
                                    $full_name = trim($user['firstname'] . ' ' . $user['lastname']);
                                    $is_admin = ($user['role_account'] ?? '') === 'admin';
                                    $is_banned = (int)($user['ban_account'] ?? 0) === 1;
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($full_name); ?></td>
                                        <td class="text-muted"><?php echo htmlspecialchars($user['email_account']); ?></td>
                                        <td><?php echo htmlspecialchars($user['role_account']); ?></td>
                                        <td>
                                            <?php if ($is_admin) { ?>
                                                <span class="badge badge-soft-primary">Admin</span>
                                            <?php } elseif ($is_banned) { ?>
                                                <span class="badge badge-soft-danger">ถูกแบน</span>
                                            <?php } else { ?>
                                                <span class="badge badge-soft-success">ปกติ</span>
                                            <?php } ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if (!$is_admin) { ?>
                                                <form method="POST" action="admin.php" class="d-inline" data-confirm-form="<?php echo $is_banned ? 'ยืนยันการปลดแบนผู้ใช้นี้?' : 'ยืนยันการแบนผู้ใช้นี้?'; ?>"><?php echo csrf_field(); ?><input type="hidden" name="toggle_ban_id" value="<?php echo (int)$user['id_account']; ?>"><button type="submit" class="btn btn-sm-icon <?php echo $is_banned ? 'btn-success' : 'btn-danger'; ?>">
                                                    <i class="bi bi-<?php echo $is_banned ? 'unlock' : 'lock'; ?>"></i>
                                                    <?php echo $is_banned ? 'ปลดแบน' : 'แบน'; ?>
                                                </button></form>
                                            <?php } else { ?>
                                                <span class="text-muted">-</span>
                                            <?php } ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php } else { ?>
                    <div class="alert alert-info mb-0">ยังไม่มีข้อมูลผู้ใช้</div>
                <?php } ?>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="admin.js"></script>
</body>
</html>
