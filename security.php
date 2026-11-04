<?php

// One time zone for PHP and MySQL (db.php sets the MySQL session to +07:00).
date_default_timezone_set('Asia/Bangkok');

if (session_status() !== PHP_SESSION_ACTIVE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_name('log_horizon_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('verify_csrf')) {
    function verify_csrf(?string $token): bool
    {
        return is_string($token)
            && !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('require_csrf')) {
    function require_csrf(): void
    {
        if (!verify_csrf($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            exit('คำขอไม่ถูกต้อง กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง');
        }
    }
}

if (!function_exists('redirect_to')) {
    function redirect_to(string $location): void
    {
        header('Location: ' . $location);
        exit();
    }
}

if (!function_exists('flash_redirect')) {
    function flash_redirect(string $location, string $key, string $message): void
    {
        $_SESSION[$key] = $message;
        redirect_to($location);
    }
}

if (!function_exists('require_user_login')) {
    function require_user_login(): void
    {
        if (!isset($_SESSION['id_account'])) {
            $_SESSION['auth_tab'] = 'login';
            flash_redirect('login.php', 'login_error', 'กรุณาเข้าสู่ระบบก่อน');
        }
    }
}

if (!function_exists('require_admin_login')) {
    function require_admin_login(): void
    {
        if (!isset($_SESSION['admin_login'])) {
            flash_redirect('login.php', 'login_error', 'กรุณาเข้าสู่ระบบ!');
        }
    }
}

if (!function_exists('enforce_not_banned')) {
    function enforce_not_banned(PDO $conn, int $userId): void
    {
        $guardStmt = $conn->prepare('SELECT ban_account FROM account WHERE id_account = :id_account');
        $guardStmt->bindParam(':id_account', $userId, PDO::PARAM_INT);
        $guardStmt->execute();
        $guardRow = $guardStmt->fetch(PDO::FETCH_ASSOC);

        if ((int)($guardRow['ban_account'] ?? 0) === 1) {
            session_unset();
            session_destroy();
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            $_SESSION['auth_tab'] = 'login';
            flash_redirect('login.php', 'login_error', 'บัญชีนี้ถูกแบน');
        }
    }
}

if (!function_exists('booking_is_active_now')) {
    function booking_is_active_now(array $booking): bool
    {
        if (($booking['booking_status'] ?? '') !== 'active') {
            return false;
        }

        try {
            $timezone = new DateTimeZone('Asia/Bangkok');
            $now = new DateTimeImmutable('now', $timezone);
            $checkIn = new DateTimeImmutable((string)$booking['check_in'], $timezone);
            $checkOut = new DateTimeImmutable((string)$booking['check_out'], $timezone);

            return $now >= $checkIn && $now < $checkOut;
        } catch (Exception $exception) {
            return false;
        }
    }
}

/*
 * Booking statuses that keep a room reserved. A booking waiting for payment or
 * for admin approval still holds the room so nobody else can book the same dates.
 */
const BOOKING_HOLDING_STATUSES_SQL = "'pending_payment', 'awaiting_approval', 'active'";

if (!function_exists('booking_status_label')) {
    function booking_status_label(string $status): string
    {
        return [
            'pending_payment' => 'รอชำระเงิน',
            'awaiting_approval' => 'รอแอดมินยืนยัน',
            'active' => 'ยืนยันแล้ว',
            'rejected' => 'ถูกปฏิเสธ',
            'cancelled' => 'ยกเลิกแล้ว',
        ][$status] ?? $status;
    }
}

if (!function_exists('json_response')) {
    function json_response(array $body, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
        exit();
    }
}

if (!function_exists('require_csrf_header')) {
    /* For fetch() calls: the page sends its CSRF token in the X-CSRF-Token header. */
    function require_csrf_header(): void
    {
        if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            json_response(['ok' => false, 'message' => 'คำขอไม่ถูกต้อง กรุณาโหลดหน้าใหม่'], 403);
        }
    }
}

if (!function_exists('booking_amount')) {
    /* Same rule as the payment page: ฿10 per night, at least one night. */
    function booking_amount(string $checkIn, string $checkOut, int $nightlyPrice = 10): array
    {
        try {
            $nights = max(1, (int)(new DateTime($checkIn))->diff(new DateTime($checkOut))->days);
        } catch (Exception $e) {
            $nights = 1;
        }
        return ['nights' => $nights, 'nightly' => $nightlyPrice, 'total' => $nights * $nightlyPrice];
    }
}
