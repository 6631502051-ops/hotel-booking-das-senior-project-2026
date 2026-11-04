<?php
require_once __DIR__ . '/security.php';

$servername = getenv('DB_HOST') ?: 'db';
$database = getenv('DB_NAME') ?: 'digital_authentication_system_das';
$username = getenv('DB_USER') ?: (getenv('MYSQL_USER') ?: 'root');
$password = getenv('DB_PASSWORD') ?: (getenv('MYSQL_PASSWORD') ?: '');

if ($password === '') {
    http_response_code(500);
    exit('Database configuration is missing');
}

try {
    $conn = new PDO(
        "mysql:host={$servername};dbname={$database};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+07:00'"
        ]
    );
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('Database connection failed');
}