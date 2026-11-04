# DAS Door Lock สำหรับ Raspberry Pi

โปรแกรม `door_lock.py` ทำงานแบบ **API-only เป็นค่าเริ่มต้น**: รับรหัสจากอุปกรณ์อ่านรหัสในรูปแบบ `ROOM CODE` แล้วส่ง HTTPS `POST` ไปยังเว็บเซิร์ฟเวอร์ที่ `/api/door-authenticate.php` ผ่าน Cloudflare Tunnel จากนั้นจึงสั่ง LED, buzzer, relay และ servo เมื่อเซิร์ฟเวอร์อนุญาต

```
อุปกรณ์อ่านรหัสบน Raspberry Pi
        │  ROOM CODE
        ▼
door_lock.py ── HTTPS + X-Device-Key ──► Cloudflare Tunnel
                                      │
                                      ▼
                              เว็บเซิร์ฟเวอร์ PHP API
                                      │ granted / denied
                                      ▼
                         Pi แสดงผลและสั่งกลอนประตู
```

MQTT ยังอยู่ในโค้ดเพื่อใช้ทดลองภายหลังเท่านั้น และจะไม่ถูกเปิดในโหมดปกติ เว้นแต่รันด้วย `--mqtt`

## ติดตั้งบน Raspberry Pi

```bash
mkdir -p ~/das-door && cd ~/das-door
# คัดลอก door_lock.py, requirements.txt, door.env.example, das-door.service มาไว้ในโฟลเดอร์นี้
python3 -m venv --system-site-packages .venv
.venv/bin/pip install -r requirements.txt
cp door.env.example door.env
nano door.env
```

ตั้งค่าใน `door.env`

| ค่า | ใส่อะไร |
| --- | --- |
| `DAS_ROOMS` | ห้องที่ Pi รับผิดชอบ เช่น `101,102,103,104,105,106` |
| `DAS_API_URL` | HTTPS URL ของ API เช่น `https://<ชื่อ-tunnel>.trycloudflare.com/api/door-authenticate.php` |
| `DAS_DEVICE_KEY` | ค่าเดียวกับ `DOOR_DEVICE_KEY` ใน `.env` ของเว็บเซิร์ฟเวอร์ |
| `DAS_DEVICE_ID` | ชื่ออุปกรณ์ เช่น `pi-door-1` |
| `DAS_API_TIMEOUT` | timeout ของ HTTPS request เช่น `5` วินาที |

ไม่ต้องตั้ง `DAS_MQTT_URL`, `DAS_MQTT_USER` หรือ `DAS_MQTT_PASSWORD` สำหรับโหมดปกติ

## ทดสอบ API และการรับรหัส

ตรวจรหัสกับ API ครั้งเดียว:

```bash
.venv/bin/python door_lock.py --check 101 123456
```

จำลองอุปกรณ์อ่านรหัสผ่าน stdin:

```bash
printf '101 123456\n' | .venv/bin/python door_lock.py
```

เมื่อต่ออุปกรณ์อ่านรหัสจริง ให้ส่งข้อมูลทีละบรรทัดในรูปแบบ:

```text
101 123456
```

API จะเปิดได้เฉพาะรหัสของ booking สถานะ `active` ที่อยู่ในช่วงเข้าพัก และอุปกรณ์ต้องส่ง `X-Device-Key` ที่ถูกต้อง หากติดต่อ API ไม่ได้ โปรแกรมจะ fail-secure และไม่ปลดล็อก

## รันเป็น systemd service

```bash
sudo cp das-door.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now das-door
journalctl -u das-door -f
```

service ใช้ API-only เป็นค่าเริ่มต้นและอ่าน `ROOM CODE` จาก stdin ของโปรเซส หากอุปกรณ์อ่านรหัสใช้ driver เฉพาะ ให้ต่อ driver ให้เขียนข้อมูลตามรูปแบบนี้เข้าโปรเซส หรือเรียก `door_lock.py --check ROOM CODE` ต่อหนึ่งรายการ

## ทดสอบ servo โดยไม่เรียกเซิร์ฟเวอร์

```bash
.venv/bin/python door_lock.py --test-servo
.venv/bin/python door_lock.py --test-servo 102
```

## MQTT สำหรับการทดลองเท่านั้น

โหมดนี้ไม่ใช่เส้นทางหลักของระบบ:

```bash
.venv/bin/python door_lock.py --mqtt
```

เมื่อต้องการใช้โหมดนี้จึงค่อยตั้งค่า `DAS_MQTT_URL`, `DAS_MQTT_USER` และ `DAS_MQTT_PASSWORD` ใน `door.env` โดย broker ต้องเปิดใช้งานในฝั่ง Docker ตามการตั้งค่าของโปรเจค

## ต่อ LED, buzzer, relay และ servo

| อุปกรณ์ | GPIO (BCM) | ค่าใน door.env |
| --- | --- | --- |
| LED เขียว | 17 | `DAS_GREEN_PIN` |
| LED แดง | 27 | `DAS_RED_PIN` |
| Buzzer | 22 | `DAS_BUZZER_PIN` |
| Relay | ไม่ได้ตั้งไว้ | `DAS_RELAY_PIN` |
| Servo 1 / 2 | 18 / 13 | `DAS_SERVO_PINS` |

ใช้ `DAS_GPIO=auto` เพื่อให้โปรแกรม fallback เป็นการแสดงผลบนหน้าจอเมื่อไม่มี GPIO สำหรับการทดสอบบนคอมพิวเตอร์

สำหรับ servo ให้ใช้ไฟ 5V แยกตามกระแสที่เหมาะสม และต่อ GND ของแหล่งจ่ายร่วมกับ GND ของ Raspberry Pi ห้ามใช้ไฟจาก Pi โดยตรงกับ servo กำลังสูง

ดูค่าตั้งค่า servo ทั้งหมดใน `door.env.example`
