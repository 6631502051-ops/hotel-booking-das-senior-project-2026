<?php

const OTP_EXPIRES_MINUTES = 10;
const OTP_MAX_ATTEMPTS = 5;
const OTP_REQUEST_LIMIT_SECONDS = 60;

function ensureOtpTable(PDO $conn): void
{
    $conn->exec("CREATE TABLE IF NOT EXISTS otp_requests (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_id CHAR(36) NOT NULL UNIQUE,
        identifier VARCHAR(190) NOT NULL,
        purpose VARCHAR(40) NOT NULL DEFAULT 'login',
        code_hash VARCHAR(255) NOT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        verified_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_otp_identifier_created (identifier, created_at),
        INDEX idx_otp_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function createOtpRequest(PDO $conn, string $identifier, string $purpose): array
{
    $recentStmt = $conn->prepare(
        'SELECT created_at FROM otp_requests
         WHERE identifier = :identifier AND purpose = :purpose
         ORDER BY id DESC LIMIT 1'
    );
    $recentStmt->execute(['identifier' => $identifier, 'purpose' => $purpose]);
    $recent = $recentStmt->fetch(PDO::FETCH_ASSOC);

    if ($recent && strtotime($recent['created_at']) > time() - OTP_REQUEST_LIMIT_SECONDS) {
        throw new RuntimeException('กรุณารอ 60 วินาทีก่อนขอรหัสใหม่');
    }

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $requestId = sprintf(
        '%s-%s-%s-%s-%s',
        bin2hex(random_bytes(4)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(6))
    );
    $expiresAt = date('Y-m-d H:i:s', time() + OTP_EXPIRES_MINUTES * 60);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);

    $stmt = $conn->prepare(
        'INSERT INTO otp_requests
         (request_id, identifier, purpose, code_hash, expires_at)
         VALUES (:request_id, :identifier, :purpose, :code_hash, :expires_at)'
    );
    $stmt->execute([
        'request_id' => $requestId,
        'identifier' => $identifier,
        'purpose' => $purpose,
        'code_hash' => $codeHash,
        'expires_at' => $expiresAt
    ]);

    return [
        'request_id' => $requestId,
        'code' => $code,
        'expires_at' => $expiresAt
    ];
}

function verifyOtpRequest(PDO $conn, string $requestId, string $code): array
{
    $stmt = $conn->prepare(
        'SELECT id, code_hash, attempts, expires_at, verified_at
         FROM otp_requests WHERE request_id = :request_id LIMIT 1'
    );
    $stmt->execute(['request_id' => $requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request || $request['verified_at'] !== null) {
        return ['valid' => false, 'message' => 'รหัสไม่ถูกต้องหรือถูกใช้งานแล้ว'];
    }

    if (strtotime($request['expires_at']) < time()) {
        return ['valid' => false, 'message' => 'รหัสหมดอายุแล้ว'];
    }

    if ((int)$request['attempts'] >= OTP_MAX_ATTEMPTS) {
        return ['valid' => false, 'message' => 'กรอกรหัสผิดเกินจำนวนที่กำหนด'];
    }

    if (!password_verify($code, $request['code_hash'])) {
        $updateStmt = $conn->prepare('UPDATE otp_requests SET attempts = attempts + 1 WHERE id = :id');
        $updateStmt->execute(['id' => $request['id']]);
        return ['valid' => false, 'message' => 'รหัสไม่ถูกต้อง'];
    }

    $updateStmt = $conn->prepare('UPDATE otp_requests SET verified_at = NOW() WHERE id = :id');
    $updateStmt->execute(['id' => $request['id']]);

    return ['valid' => true, 'message' => 'ยืนยันรหัสสำเร็จ'];
}
