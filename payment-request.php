<?php
/*
 * POST /api/payment-request.php   (called by payment.php with fetch)
 * Header: X-CSRF-Token: <token from the page>
 * Body:   {"booking_id": 12}
 * Moves the booking from pending_payment to awaiting_approval and queues it
 * on the admin page.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/payment.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'ใช้เมธอด POST เท่านั้น'], 405);
}
if (!isset($_SESSION['id_account'])) {
    json_response(['ok' => false, 'message' => 'กรุณาเข้าสู่ระบบก่อน'], 401);
}
require_csrf_header();

$input = json_decode(file_get_contents('php://input'), true);
$bookingId = (int)(is_array($input) ? ($input['booking_id'] ?? 0) : 0);
if ($bookingId <= 0) {
    json_response(['ok' => false, 'message' => 'ไม่พบรหัสการจอง'], 422);
}

$result = submit_payment_request($conn, $bookingId, (int)$_SESSION['id_account']);
$status = $result['status'];
unset($result['status']);
if ($result['ok']) {
    $_SESSION['success'] = 'ส่งคำขอชำระเงินแล้ว การจองจะสำเร็จเมื่อแอดมินยืนยันการชำระเงิน';
}
json_response($result, $status);
