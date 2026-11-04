<?php
/*
 * POST /api/admin-payment-decision.php   (Approve / Reject buttons on admin.php)
 * Header: X-CSRF-Token: <token from the admin page>
 * Body:   {"payment_id": 7, "decision": "approve" | "reject", "note": "optional"}
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/payment.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'ใช้เมธอด POST เท่านั้น'], 405);
}
if (!isset($_SESSION['admin_login'])) {
    json_response(['ok' => false, 'message' => 'สำหรับผู้ดูแลระบบเท่านั้น'], 403);
}
require_csrf_header();

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$paymentId = (int)($input['payment_id'] ?? 0);
$decision = (string)($input['decision'] ?? '');
$note = trim((string)($input['note'] ?? ''));

if ($paymentId <= 0) {
    json_response(['ok' => false, 'message' => 'ไม่พบรหัสคำขอชำระเงิน'], 422);
}

$result = decide_payment($conn, $paymentId, $decision, (int)$_SESSION['admin_login'], $note);
$status = $result['status'];
unset($result['status']);
json_response($result, $status);
