<?php
require_once 'config/security.php';
require_once 'config/db.php';

require_user_login();

require_csrf();

$bookingId = (int)($_POST['booking_id'] ?? 0);
$userId = (int)$_SESSION['id_account'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $bookingId <= 0) {
    $_SESSION['error'] = 'ไม่พบรหัสการจอง';
    header('Location: dashboard.php');
    exit();
}

try {
    $cancelStmt = $conn->prepare("UPDATE booking_info SET booking_status = 'cancelled', access_code = NULL, temporary_code = NULL, temporary_code_expires_at = NULL WHERE booking_id = :booking_id AND user_id = :user_id AND booking_status IN (" . BOOKING_HOLDING_STATUSES_SQL . ")");
    $cancelStmt->bindValue(':booking_id', $bookingId, PDO::PARAM_INT);
    $cancelStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $cancelStmt->execute();

    if ($cancelStmt->rowCount() > 0) {
        $_SESSION['success'] = 'ยกเลิกการจองเรียบร้อยแล้ว การยกเลิกนี้ไม่คืนเงิน';
    } else {
        $_SESSION['error'] = 'ไม่พบการจองนี้ หรือการจองถูกยกเลิกไปแล้ว';
    }

    header('Location: dashboard.php');
    exit();
} catch (PDOException $e) {
    $_SESSION['error'] = 'เกิดข้อผิดพลาดในการยกเลิกการจอง';
    header('Location: dashboard.php');
    exit();
}
