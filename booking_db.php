<?php
require_once 'config/security.php';
require_once 'config/db.php';

require_user_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['submit'])) {
    header('Location: booking.php');
    exit();
}

require_csrf();

$checkin = trim($_POST['checkin'] ?? '');
$checkout = trim($_POST['checkout'] ?? '');
$guests = trim($_POST['guests'] ?? '');
$roomId = (int)($_POST['room'] ?? 0);
$userId = (int)$_SESSION['id_account'];

$redirectQuery = http_build_query([
    'checkin' => $checkin,
    'checkout' => $checkout,
    'guests' => $guests,
    'search' => '1'
]);

if ($checkin === '' || $checkout === '' || $guests === '' || $roomId <= 0) {
    $_SESSION['error'] = 'ข้อมูลการจองไม่ครบถ้วน';
    header('Location: booking.php?' . $redirectQuery);
    exit();
}

if (strtotime($checkin) >= strtotime($checkout)) {
    $_SESSION['error'] = 'วันที่เช็คเอ้าท์ต้องมากกว่าวันที่เช็คอิน';
    header('Location: booking.php?' . $redirectQuery);
    exit();
}

try {
    $availabilityStmt = $conn->prepare("SELECT room_id FROM room WHERE room_id = :room_id AND NOT EXISTS (
        SELECT 1
        FROM booking_info b
        WHERE b.room_count = room.room_id
        AND b.booking_status IN (" . BOOKING_HOLDING_STATUSES_SQL . ")
        AND b.check_in < :checkout
        AND b.check_out > :checkin
    )");
    $availabilityStmt->bindParam(':room_id', $roomId, PDO::PARAM_INT);
    $availabilityStmt->bindParam(':checkin', $checkin);
    $availabilityStmt->bindParam(':checkout', $checkout);
    $availabilityStmt->execute();

    if (!$availabilityStmt->fetch(PDO::FETCH_ASSOC)) {
        $_SESSION['error'] = 'ห้องนี้ไม่ว่างแล้ว กรุณาเลือกห้องอื่น';
        header('Location: booking.php?' . $redirectQuery);
        exit();
    }

    $insertStmt = $conn->prepare("INSERT INTO booking_info (user_id, room_count, member, check_in, check_out, booking_status) VALUES (:user_id, :room_count, :member, :check_in, :check_out, 'pending_payment')");
    $insertStmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $insertStmt->bindParam(':room_count', $roomId, PDO::PARAM_INT);
    $insertStmt->bindParam(':member', $guests, PDO::PARAM_INT);
    $insertStmt->bindParam(':check_in', $checkin);
    $insertStmt->bindParam(':check_out', $checkout);
    $insertStmt->execute();

    $bookingId = (int)$conn->lastInsertId();
    header('Location: payment.php?booking_id=' . $bookingId);
    exit();
} catch (PDOException $e) {
    $_SESSION['error'] = 'เกิดข้อผิดพลาดในการจอง กรุณาลองใหม่อีกครั้ง';
    header('Location: booking.php?' . $redirectQuery);
    exit();
}
