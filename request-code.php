<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/otp.php';
require_once __DIR__ . '/../config/otp_delivery.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'ใช้เมธอด POST เท่านั้น'], JSON_UNESCAPED_UNICODE);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$input = is_array($input) ? $input : $_POST;
$identifier = trim((string)($input['identifier'] ?? ''));
$purpose = trim((string)($input['purpose'] ?? 'login'));

if ($identifier === '' || mb_strlen($identifier) > 190) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'กรุณาระบุ identifier ที่ถูกต้อง'], JSON_UNESCAPED_UNICODE);
    exit();
}

if (!preg_match('/^[a-zA-Z0-9._-]{1,40}$/', $purpose)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'purpose ไม่ถูกต้อง'], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    ensureOtpTable($conn);
    $otp = createOtpRequest($conn, $identifier, $purpose);

    if (!deliverOtpCode($identifier, $purpose, $otp['code'])) {
        $cleanupStmt = $conn->prepare('DELETE FROM otp_requests WHERE request_id = :request_id');
        $cleanupStmt->execute(['request_id' => $otp['request_id']]);
        http_response_code(502);
        echo json_encode(['ok' => false, 'message' => 'ไม่สามารถส่งรหัสไปยังปลายทางได้'], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $response = [
        'ok' => true,
        'request_id' => $otp['request_id'],
        'expires_at' => $otp['expires_at'],
        'message' => 'สร้างรหัสแล้ว กรุณาตรวจสอบช่องทางปลายทางของคุณ'
    ];

    // Temporary local development aid. Set OTP_DEBUG=true in the environment only.
    if (getenv('OTP_DEBUG') === 'true') {
        $response['debug_code'] = $otp['code'];
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'ไม่สามารถสร้างรหัสได้'], JSON_UNESCAPED_UNICODE);
}
