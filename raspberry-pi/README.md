# DAS Door Lock สำหรับ Raspberry Pi

`door_lock.py` จำลองประตูล็อกดิจิทัล โปรแกรมรอรับรหัส 6 หลักจาก MQTT แล้วถามเซิร์ฟเวอร์ว่ารหัสนี้เปิดประตูได้หรือไม่ จากนั้นแสดงผล **UNLOCKED** หรือ **ACCESS DENIED**

```
หน้า keypad (door-index.html)
   │  publish  das/door/<ห้อง>/code   "123456"
   ▼
Mosquitto (MQTT broker)
   │
   ▼
Raspberry Pi  door_lock.py
   │  POST api/door-authenticate.php  {room, code}  + X-Device-Key
   ▼
เว็บเซิร์ฟเวอร์ (PHP + MySQL) ตอบ granted / denied
   │
   ▼
Pi แสดง UNLOCKED หรือ ACCESS DENIED, สั่ง LED, buzzer, relay และหมุน servo 90°
   │  publish  das/door/<ห้อง>/result  {"result":"granted", ...}
   ▼
หน้า keypad แสดงผล
```

## ติดตั้งบน Pi

```bash
mkdir -p ~/das-door && cd ~/das-door
# คัดลอก door_lock.py, requirements.txt, door.env.example, das-door.service มาไว้ในโฟลเดอร์นี้
python3 -m venv --system-site-packages .venv   # --system-site-packages เพื่อใช้ gpiozero ที่มากับ Raspberry Pi OS
.venv/bin/pip install -r requirements.txt
cp door.env.example door.env
nano door.env
```

ค่าที่ต้องแก้ใน `door.env`

| ค่า | ใส่อะไร |
| --- | --- |
| `DAS_ROOMS` | ห้องที่ Pi เครื่องนี้จำลอง เช่น `101,102,103,104,105,106` |
| `DAS_MQTT_URL` | ทาง A (ผ่าน Cloudflare tunnel): `wss://<tunnel>.trycloudflare.com/mqtt` · ทาง B (LAN เดียวกัน): `mqtt://<IP เซิร์ฟเวอร์>:1883` |
| `DAS_MQTT_PASSWORD` | ค่าเดียวกับ `MQTT_DOOR_PASSWORD` ใน `.env` ของเซิร์ฟเวอร์ |
| `DAS_API_URL` | ทาง A: `https://<tunnel>.trycloudflare.com/api/door-authenticate.php` · ทาง B: `http://<IP เซิร์ฟเวอร์>:8080/api/door-authenticate.php` |
| `DAS_DEVICE_KEY` | ค่าเดียวกับ `DOOR_DEVICE_KEY` ใน `.env` ของเซิร์ฟเวอร์ |

ทาง A ใช้ได้จากทุกที่ แต่ URL ของ quick tunnel จะเปลี่ยนทุกครั้งที่รัน tunnel ใหม่ ต้องมาแก้ `door.env` ทุกครั้ง ส่วนทาง B ต้องตั้ง `WEB_BIND=0.0.0.0` และ `MQTT_BIND=0.0.0.0` ใน `.env` ของเซิร์ฟเวอร์

## ทดสอบ

```bash
# 1) ตรวจรหัสกับ API อย่างเดียว ไม่ผ่าน MQTT
.venv/bin/python door_lock.py --check 101 123456

# 2) รันจริง แล้วเปิดหน้า https://<เว็บ>/door-index.html เลือกห้องและกรอกรหัส
.venv/bin/python door_lock.py
```

ถ้าไม่มีหน้าเว็บ ส่งรหัสด้วย `mosquitto_pub` ได้ (ใช้บัญชี `das_keypad`)

```bash
mosquitto_pub -h <IP เซิร์ฟเวอร์> -u das_keypad -P <MQTT_KEYPAD_PASSWORD> -t das/door/101/code -m 123456
```

## ต่อ LED, buzzer และ relay (ไม่บังคับ)

| อุปกรณ์ | GPIO (BCM) | ค่าใน door.env |
| --- | --- | --- |
| LED เขียว (ปลดล็อก) | 17 | `DAS_GREEN_PIN` |
| LED แดง (ปฏิเสธ) | 27 | `DAS_RED_PIN` |
| Buzzer | 22 | `DAS_BUZZER_PIN` |
| Relay สำหรับกลอนไฟฟ้า | ไม่ได้ตั้งไว้ | `DAS_RELAY_PIN` |

LED ทุกดวงต้องต่อตัวต้านทาน 220–330 Ω อนุกรมไว้ ถ้าไม่ได้ต่ออุปกรณ์ หรือรันบนโน้ตบุ๊ก โปรแกรมจะแสดงผลบนหน้าจออย่างเดียว (`DAS_GPIO=auto`)

## ต่อเซอร์โวมอเตอร์ 2 ตัว (หมุน 90° เพื่อเปิดประตู)

เมื่อรหัสถูกต้อง servo จะหมุนจากมุมปิด (0°) ไปมุมเปิด (90°) ค้างไว้ `DAS_UNLOCK_SECONDS` วินาที แล้วหมุนกลับไปปิด ค่าเริ่มต้นใช้ 2 ตัว

| สาย servo | ต่อที่ |
| --- | --- |
| สีส้ม/เหลือง (สัญญาณ) servo 1 | GPIO 18 (ขา 12) |
| สีส้ม/เหลือง (สัญญาณ) servo 2 | GPIO 13 (ขา 33) |
| สีแดง (+5V) ทั้งสองตัว | **แหล่งจ่าย 5V แยก** (เช่น adapter 5V 2A) |
| สีน้ำตาล/ดำ (GND) ทั้งสองตัว | GND ของแหล่งจ่ายแยก **และ** GND ของ Pi (ขา 6) ต้องต่อถึงกัน |

- ห้ามดึงไฟ servo จากขา 5V ของ Pi โดยตรงถ้าเป็น MG996R หรือหมุนพร้อมกัน 2 ตัว กระแสกระชากจะทำให้ Pi รีบูต (SG90 ตัวเล็ก 2 ตัวพอใช้ได้สำหรับทดสอบ แต่แนะนำแยกไฟ)
- GND ต้องต่อร่วมกัน ไม่งั้น servo จะไม่รับสัญญาณหรือสั่นมั่ว

ตั้งค่าใน `door.env` (ดูคำอธิบายทั้งหมดใน `door.env.example`)

| ค่า | ค่าเริ่มต้น | ความหมาย |
| --- | --- | --- |
| `DAS_SERVO_PINS` | `18,13` | ขา GPIO ของ servo แต่ละตัว (เว้นว่าง = ไม่ใช้ servo) |
| `DAS_SERVO_ROOMS` | `*,*` | ห้องของ servo แต่ละตัว `*` = ทุกห้อง (ประตูบานคู่บานเดียวในโมเดล) หรือ `101,102` = แยกห้องละตัว |
| `DAS_SERVO_REVERSE` | `0,1` | `1` = ติดตั้งกลับด้าน หมุนสวนทาง (บานที่ 2 ของประตูบานคู่: ปิด 180° → เปิด 90°) |
| `DAS_SERVO_CLOSED_ANGLE` / `DAS_SERVO_OPEN_ANGLE` | `0` / `90` | มุมตอนล็อกและตอนเปิด |
| `DAS_SERVO_MIN_PULSE_MS` / `DAS_SERVO_MAX_PULSE_MS` | `0.5` / `2.5` | ความกว้างพัลส์ที่ 0° และ 180° (SG90/MG90S) ถ้าหมุนไม่ถึง 90° หรือมีเสียงครืดที่ปลาย ลองปรับ |
| `DAS_SERVO_MOVE_SECONDS` | `0.8` | เวลาที่ใช้หมุน หลังจากนั้นตัดสัญญาณเพื่อไม่ให้ servo สั่น |
| `DAS_SERVO_HOLD` | `false` | `true` = จ่ายสัญญาณค้างไว้ให้ servo ล็อกตำแหน่งแน่น (ร้อนและอาจมีเสียง) |

ทดสอบ servo โดยไม่ต้องใช้เซิร์ฟเวอร์หรือ MQTT

```bash
.venv/bin/python door_lock.py --test-servo        # ห้องแรกใน DAS_ROOMS
.venv/bin/python door_lock.py --test-servo 102    # เฉพาะ servo ของห้อง 102
```

ถ้า servo สั่น ให้ใช้ pigpio ซึ่งสร้าง PWM ได้นิ่งกว่า (ใช้ได้กับ Pi 4 ลงไป ไม่รองรับ Pi 5)

```bash
sudo apt install pigpio python3-pigpio && sudo systemctl enable --now pigpiod
GPIOZERO_PIN_FACTORY=pigpio .venv/bin/python door_lock.py --test-servo
```

(ใส่ `Environment=GPIOZERO_PIN_FACTORY=pigpio` ใน `das-door.service` ถ้าจะใช้ตอนรันเป็น service)

## ให้รันเองตอนเปิดเครื่อง

```bash
sudo cp das-door.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now das-door
journalctl -u das-door -f        # ดู log
```

ถ้าชื่อผู้ใช้บน Pi ไม่ใช่ `pi` ให้แก้ `User=` และ path ใน `das-door.service` ก่อน

## ผลที่ประตูตอบกลับ (`reason`)

| reason | ความหมาย |
| --- | --- |
| `ok` | รหัสถูกต้องและอยู่ในช่วงเข้าพัก ประตูปลดล็อก (ค่าเริ่มต้น 5 วินาที) |
| `invalid_code` | ไม่มีรหัสนี้ในการจองที่ยืนยันแล้วของห้องนี้ |
| `not_in_stay` | รหัสถูกต้อง แต่ตอนนี้ยังไม่ถึงวันเช็คอิน หรือเลยวันเช็คเอาท์แล้ว |
| `temporary_expired` | รหัสชั่วคราวเกิน 30 นาทีแล้ว |
| `locked_out` | ห้องนี้กรอกรหัสผิด 5 ครั้งใน 5 นาที |
| `bad_format` | ข้อความที่ได้รับไม่ใช่ตัวเลข 6 หลัก (Pi ตรวจเอง ไม่เรียก API) |
| `server_unreachable` / `server_error` | ติดต่อเซิร์ฟเวอร์ไม่ได้ ประตูยังล็อกอยู่ (fail-secure) |
| `device_unauthorized` | `DAS_DEVICE_KEY` ไม่ตรงกับเซิร์ฟเวอร์ |

ข้อความรหัสที่ broker เก็บค้างไว้ (retained) จะไม่ถูกนำมาใช้เปิดประตูตอน Pi เชื่อมต่อใหม่ เพื่อกันการเปิดประตูซ้ำด้วยรหัสเก่า
