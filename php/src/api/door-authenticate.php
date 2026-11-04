<?php
/*
 * POST /api/door-authenticate.php   (called by the Raspberry Pi door lock)
 * Headers: X-Device-Key: <DOOR_DEVICE_KEY from .env>
 *          X-Device-Id:  <optional name of the device, stored in the log>
 * Body:    {"room": "101", "code": "123456"}
 *
 * Opens only when the code is the main code or an unexpired temporary code of
 * a confirmed ('active') booking for that room, and now is inside the stay.
 * Every attempt is written to door_access_log. After 5 wrong codes in
 * 5 minutes the room is locked out for the rest of that window.
 */
require_once __DIR__ . '/../config/db.php';

const DOOR_MAX_FAILURES = 5;
const DOOR_LOCKOUT_MINUTES = 5;

$messages = [
    'ok' => 'ยืนยันสำเร็จ สามารถเปิดประตูได้',
    'bad_format' => 'กรุณาระบุห้องและรหัส 6 หลัก',
    'invalid_code' => 'รหัสไม่ถูกต้อง',
    'not_in_stay' => 'รหัสนี้ใช้ได้เฉพาะในช่วงเวลาเข้าพัก',
    'temporary_expired' => 'รหัสชั่วคราวหมดอายุแล้ว',
    'locked_out' => 'กรอกรหัสผิดหลายครั้ง กรุณารอ ' . DOOR_LOCKOUT_MINUTES . ' นาที',
];

function door_log(PDO $conn, string $room, string $code, string $result, string $reason, ?int $bookingId = null, ?string $codeType = null): void
{
    try {
        $masked = preg_match('/^\d{6}$/', $code) ? substr($code, 0, 2) . '****' : '******';
        $source = substr(preg_replace('/[^A-Za-z0-9._-]/', '', (string)($_SERVER['HTTP_X_DEVICE_ID'] ?? '')), 0, 60) ?: null;
        $conn->prepare('INSERT INTO door_access_log (room_number, booking_id, code_type, code_masked, result, reason, source)
                        VALUES (:room, :booking_id, :code_type, :masked, :result, :reason, :source)')
            ->execute([
                'room' => substr($room, 0, 20),
                'booking_id' => $bookingId,
                'code_type' => $codeType,
                'masked' => $masked,
                'result' => $result,
                'reason' => $reason,
                'source' => $source,
            ]);
    } catch (Throwable $e) {
        error_log('door_log: ' . $e->getMessage());
    }
}

function door_reply(string $room, string $result, string $reason, array $messages, int $status, array $extra = []): void
{
    $open = $result === 'granted';
    json_response(array_merge([
        'ok' => $open,
        'open' => $open,
        'room' => $room,
        'result' => $result,
        'reason' => $reason,
        'message' => $messages[$reason] ?? 'ไม่สามารถเปิดประตูได้',
    ], $extra), $status);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'open' => false, 'message' => 'ใช้เมธอด POST เท่านั้น'], 405);
}

// Only the door device may call this endpoint.
$deviceKey = trim((string)getenv('DOOR_DEVICE_KEY'));
if ($deviceKey === '') {
    json_response(['ok' => false, 'open' => false, 'message' => 'ยังไม่ได้ตั้งค่า DOOR_DEVICE_KEY บนเซิร์ฟเวอร์'], 503);
}
if (!hash_equals($deviceKey, (string)($_SERVER['HTTP_X_DEVICE_KEY'] ?? ''))) {
    json_response(['ok' => false, 'open' => false, 'message' => 'อุปกรณ์ไม่ได้รับอนุญาต'], 401);
}

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : [];
$room = trim((string)($input['room'] ?? ''));
$code = trim((string)($input['code'] ?? ''));

if (!preg_match('/^[A-Za-z0-9-]{1,20}$/', $room) || !preg_match('/^\d{6}$/', $code)) {
    door_log($conn, $room === '' ? '-' : $room, $code, 'denied', 'bad_format');
    door_reply($room, 'denied', 'bad_format', $messages, 422);
}

try {
    $failStmt = $conn->prepare("SELECT COUNT(*) FROM door_access_log
        WHERE room_number = :room AND result = 'denied' AND reason <> 'locked_out'
          AND created_at > NOW() - INTERVAL " . DOOR_LOCKOUT_MINUTES . " MINUTE");
    $failStmt->execute(['room' => $room]);
    if ((int)$failStmt->fetchColumn() >= DOOR_MAX_FAILURES) {
        door_log($conn, $room, $code, 'denied', 'locked_out');
        door_reply($room, 'denied', 'locked_out', $messages, 429);
    }

    $stmt = $conn->prepare("SELECT b.booking_id, b.check_in, b.check_out, b.booking_status,
            b.access_code, b.temporary_code,
            (b.temporary_code_expires_at IS NOT NULL AND b.temporary_code_expires_at > NOW()) AS temporary_valid
        FROM booking_info b
        JOIN room r ON r.room_id = b.room_count
        WHERE r.room_number = :room
          AND b.booking_status = 'active'
          AND (b.access_code = :main_code OR b.temporary_code = :temp_code)");
    $stmt->execute(['room' => $room, 'main_code' => $code, 'temp_code' => $code]);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $reason = 'invalid_code';
    foreach ($matches as $booking) {
        if (!booking_is_active_now($booking)) {
            $reason = 'not_in_stay';
            continue;
        }
        if ($booking['access_code'] !== null && hash_equals((string)$booking['access_code'], $code)) {
            door_log($conn, $room, $code, 'granted', 'ok', (int)$booking['booking_id'], 'main');
            door_reply($room, 'granted', 'ok', $messages, 200, ['code_type' => 'main']);
        }
        if ($booking['temporary_code'] !== null && hash_equals((string)$booking['temporary_code'], $code)) {
            if ((int)$booking['temporary_valid'] === 1) {
                door_log($conn, $room, $code, 'granted', 'ok', (int)$booking['booking_id'], 'temporary');
                door_reply($room, 'granted', 'ok', $messages, 200, ['code_type' => 'temporary']);
            }
            $reason = 'temporary_expired';
        }
    }

    door_log($conn, $room, $code, 'denied', $reason);
    door_reply($room, 'denied', $reason, $messages, 403);
} catch (Throwable $e) {
    error_log('door-authenticate: ' . $e->getMessage());
    json_response(['ok' => false, 'open' => false, 'room' => $room, 'result' => 'denied', 'reason' => 'server_error', 'message' => 'ระบบตรวจสอบรหัสขัดข้อง'], 500);
}
