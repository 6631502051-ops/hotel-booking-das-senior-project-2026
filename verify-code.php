<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/otp.php';

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
$requestId = trim((string)($input['request_id'] ?? ''));
$code = trim((string)($input['code'] ?? ''));

if (!preg_match('/^[a-f0-9-]{36}$/i', $requestId) || !preg_match('/^\d{6}$/', $code)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'request_id หรือรหัสไม่ถูกต้อง'], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    ensureOtpTable($conn);
    $result = verifyOtpRequest($conn, $requestId, $code);

    if (!$result['valid']) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => $result['message']], JSON_UNESCAPED_UNICODE);
        exit();
    }

    echo json_encode(['ok' => true, 'message' => $result['message']], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'ไม่สามารถตรวจสอบรหัสได้'], JSON_UNESCAPED_UNICODE);
}
