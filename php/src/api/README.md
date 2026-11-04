# OTP API

มี endpoint สำหรับเว็บปลายทาง 2 จุด:

## 1. ขอรหัส 6 หลัก

`POST /api/request-code.php`

Request JSON:

```json
{
  "identifier": "user@example.com",
  "purpose": "login"
}
```

Response:

```json
{
  "ok": true,
  "request_id": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
  "expires_at": "2026-09-30 12:34:56",
  "message": "สร้างรหัสแล้ว กรุณาตรวจสอบช่องทางปลายทางของคุณ"
}
```

`request_id` ต้องเก็บไว้ส่งไปตรวจสอบพร้อมรหัสในขั้นตอนถัดไป รหัสมีอายุ 10 นาที และขอใหม่ได้ทุก 60 วินาทีต่อ identifier/purpose

## 2. ตรวจรหัส

`POST /api/verify-code.php`

Request JSON:

```json
{
  "request_id": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
  "code": "123456"
}
```

Response สำเร็จ:

```json
{
  "ok": true,
  "message": "ยืนยันรหัสสำเร็จ"
}
```

รหัสผิดจะถูกจำกัดไว้ 5 ครั้ง และรหัสที่ผ่านแล้วใช้ซ้ำไม่ได้

## 3. ยืนยันรหัสเพื่อเปิดประตู (Raspberry Pi เรียก)

`POST /api/door-authenticate.php`

Header: `X-Device-Key: <DOOR_DEVICE_KEY>` (บังคับ) และ `X-Device-Id: pi-door-1` (ไม่บังคับ ใช้บันทึก log)

Request JSON:

```json
{ "room": "101", "code": "123456" }
```

Response สำเร็จ (200):

```json
{ "ok": true, "open": true, "room": "101", "result": "granted", "reason": "ok", "code_type": "main", "message": "ยืนยันสำเร็จ สามารถเปิดประตูได้" }
```

ไม่สำเร็จ: 403 (`invalid_code`, `not_in_stay`, `temporary_expired`), 429 (`locked_out` เมื่อผิด 5 ครั้งใน 5 นาที), 422 (`bad_format`), 401 (device key ผิด) ทุกครั้งบันทึกลงตาราง `door_access_log` โดยเก็บรหัสแบบปิดบัง เช่น `12****`

Endpoint นี้ตรวจรหัสหลักหรือรหัสชั่วคราวของการจองสถานะ `active` ที่อยู่ในช่วงเวลาเข้าพักเท่านั้น หน้า `/door-index.html` ไม่เรียก API นี้ตรงๆ แล้ว แต่ส่งรหัสผ่าน MQTT ให้ Raspberry Pi เป็นผู้เรียก

## 4. การชำระเงินและการยืนยันของแอดมิน

| Method | Endpoint | ผู้เรียก | หน้าที่ |
| --- | --- | --- | --- |
| POST | `/api/payment-request.php` | ผู้ใช้ (session + `X-CSRF-Token`) | `{booking_id}` → การจองเป็น `awaiting_approval` และเพิ่มแถวในตาราง `payment` |
| GET | `/api/admin-payments.php` | แอดมิน (session) | รายการคำขอที่รอยืนยัน |
| POST | `/api/admin-payment-decision.php` | แอดมิน (session + `X-CSRF-Token`) | `{payment_id, decision: "approve" \| "reject", note}` |
| GET | `/api/door-config.php` | หน้า keypad | บอกที่อยู่ MQTT WebSocket และบัญชี `das_keypad` |

## MQTT topics

| Topic | ผู้ส่ง → ผู้รับ | ข้อมูล |
| --- | --- | --- |
| `das/door/<ห้อง>/code` | keypad → Pi | รหัส 6 หลัก เช่น `123456` |
| `das/door/<ห้อง>/result` | Pi → keypad | JSON `{room, result, reason, message, time}` |
| `das/door/<ห้อง>/status` | Pi (retained, last will) | `online` / `offline` |

## การส่งรหัสไปปลายทาง

ตั้งค่า environment variable `OTP_WEBHOOK_URL` ให้เป็น HTTPS endpoint ของระบบอีเมล/SMS/เว็บปลายทาง โดย API นี้จะส่ง JSON ต่อไปนี้ด้วย `POST`:

```json
{
  "identifier": "user@example.com",
  "purpose": "login",
  "code": "123456"
}
```

ถ้าไม่ตั้งค่า webhook ระบบจะสร้างและเก็บรหัสได้ แต่จะยังไม่มีผู้ให้บริการส่งจริง ใน local development สามารถตั้ง `OTP_DEBUG=true` เพื่อให้ response มี `debug_code` ชั่วคราว ห้ามเปิดค่านี้บน production

ตาราง `otp_requests` จะถูกสร้างอัตโนมัติครั้งแรกที่เรียก API และเก็บเฉพาะ hash ของรหัส ไม่เก็บรหัสจริง
