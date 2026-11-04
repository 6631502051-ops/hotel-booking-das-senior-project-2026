<?php
require_once 'config/security.php';
require_once 'config/db.php';

require_user_login();

require_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('dashboard.php');
}

$bookingId = (int)($_POST['booking_id'] ?? 0);
$action = $_POST['action'] ?? '';
$userId = (int)$_SESSION['id_account'];

if ($bookingId <= 0 || !in_array($action, ['generate_main', 'generate_temporary'], true)) {
    flash_redirect('dashboard.php', 'error', 'คำขอไม่ถูกต้อง');
}

try {
    $bookingStmt = $conn->prepare("SELECT booking_id, check_in, check_out, booking_status FROM booking_info WHERE booking_id = :booking_id AND user_id = :user_id");
    $bookingStmt->bindValue(':booking_id', $bookingId, PDO::PARAM_INT);
    $bookingStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $bookingStmt->execute();
    $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking || !booking_is_active_now($booking)) {
        throw new RuntimeException('สร้างรหัสได้เฉพาะในช่วงเวลาที่เข้าพักเท่านั้น');
    }

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    if ($action === 'generate_main') {
        $updateStmt = $conn->prepare('UPDATE booking_info SET access_code = :code, access_code_updated_at = NOW() WHERE booking_id = :booking_id AND user_id = :user_id');
        $updateStmt->bindValue(':code', $code);
        $updateStmt->bindValue(':booking_id', $bookingId, PDO::PARAM_INT);
        $updateStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $updateStmt->execute();
        $message = 'สร้างรหัสหลักแล้ว รหัสจะใช้งานได้จนถึงวันเช็คเอาท์';
    } else {
        $updateStmt = $conn->prepare('UPDATE booking_info SET temporary_code = :code, temporary_code_expires_at = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE booking_id = :booking_id AND user_id = :user_id');
        $updateStmt->bindValue(':code', $code);
        $updateStmt->bindValue(':booking_id', $bookingId, PDO::PARAM_INT);
        $updateStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $updateStmt->execute();
        $message = 'สร้างรหัสชั่วคราวแล้ว รหัสมีอายุ 30 นาที';
    }

    $_SESSION['issued_code'] = $code;
    $_SESSION['success'] = $message;
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage();
}

redirect_to('dashboard.php');
