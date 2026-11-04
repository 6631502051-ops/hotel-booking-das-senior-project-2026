<?php
/*
 * GET /api/admin-payments.php   (polled by admin.php every 5 seconds)
 * Returns the payment requests waiting for an admin decision.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/payment.php';

if (!isset($_SESSION['admin_login'])) {
    json_response(['ok' => false, 'message' => 'สำหรับผู้ดูแลระบบเท่านั้น'], 403);
}

try {
    $rows = list_pending_payments($conn);
    $items = array_map(static function (array $r): array {
        return [
            'payment_id' => (int)$r['payment_id'],
            'booking_id' => (int)$r['booking_id'],
            'guest' => trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? '')) ?: '-',
            'email' => $r['email_account'] ?? '',
            'room' => $r['room_number'] ?? '-',
            'check_in' => $r['check_in'],
            'check_out' => $r['check_out'],
            'guests' => (int)$r['member'],
            'amount' => (float)$r['amount'],
            'method' => $r['method'],
            'requested_at' => $r['requested_at'],
        ];
    }, $rows);
    json_response(['ok' => true, 'count' => count($items), 'items' => $items]);
} catch (Throwable $e) {
    error_log('admin-payments: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'ไม่สามารถโหลดรายการได้'], 500);
}
