<?php
/*
 * GET /api/door-config.php   (read by door-index.js)
 * Tells the keypad page how to reach the MQTT broker.
 * The das_keypad account can only publish codes and read results (see mosquitto/config/acl),
 * and door-authenticate.php locks a room after 5 wrong codes, so handing this
 * password to a public keypad page is acceptable for this prototype.
 */
require_once __DIR__ . '/../config/security.php';

$password = (string)getenv('MQTT_KEYPAD_PASSWORD');
if ($password === '') {
    json_response(['ok' => false, 'message' => 'ยังไม่ได้ตั้งค่า MQTT บนเซิร์ฟเวอร์'], 503);
}

json_response([
    'ok' => true,
    // Empty = same host as this page at /mqtt (Apache forwards it to Mosquitto).
    'ws_url' => (string)getenv('MQTT_WS_URL'),
    'ws_path' => '/mqtt',
    'username' => 'das_keypad',
    'password' => $password,
    'topic_prefix' => 'das/door',
]);
