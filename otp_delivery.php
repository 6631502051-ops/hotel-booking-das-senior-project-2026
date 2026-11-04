<?php

function deliverOtpCode(string $identifier, string $purpose, string $code): bool
{
    $webhookUrl = trim((string)getenv('OTP_WEBHOOK_URL'));
    $webhookSecret = trim((string)getenv('OTP_WEBHOOK_SECRET'));

    // Delivery is intentionally provider-agnostic. Configure a trusted HTTPS webhook
    // that forwards the code through email, SMS, or the destination application's API.
    if ($webhookUrl === '') {
        return getenv('OTP_DEBUG') === 'true';
    }

    if ($webhookSecret === '') {
        return false;
    }

    if (!filter_var($webhookUrl, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($webhookUrl), 'https://')) {
        return false;
    }

    $payload = json_encode([
        'identifier' => $identifier,
        'purpose' => $purpose,
        'code' => $code
    ], JSON_UNESCAPED_UNICODE);
    $signature = hash_hmac('sha256', $payload, $webhookSecret);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\nX-OTP-Signature: {$signature}\r\n",
            'content' => $payload,
            'timeout' => 5,
            'ignore_errors' => true
        ]
    ]);

    $response = @file_get_contents($webhookUrl, false, $context);
    return $response !== false;
}
