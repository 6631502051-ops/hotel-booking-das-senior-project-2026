#!/usr/bin/env python3
"""DAS Digital Door Lock simulation for Raspberry Pi.

The default mode reads ``ROOM CODE`` lines from stdin, asks the hotel server
over HTTPS whether the code may open the door
(``api/door-authenticate.php``), and shows UNLOCKED or ACCESS DENIED. This
keeps the production path API-only. MQTT support remains available for
experiments with the optional ``--mqtt`` flag.

Servo motors (e.g. SG90 / MG90S / MG996R): when a code is accepted, each servo
assigned to that room turns 90 degrees to open the door, then turns back after
DAS_UNLOCK_SECONDS. Two servos are set up by default (GPIO 18 and 13).

Run:            python3 door_lock.py
Check one code: python3 door_lock.py --check 101 123456   (calls the API only, no MQTT)
Read codes:     echo "101 123456" | python3 door_lock.py
Optional MQTT:  python3 door_lock.py --mqtt
Test servos:    python3 door_lock.py --test-servo           (open -> wait -> close, no server needed)
Settings come from environment variables or door.env next to this file
(see door.env.example).
"""
from __future__ import annotations

import argparse
import json
import os
import queue
import re
import signal
import socket
import sys
import threading
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import TYPE_CHECKING
from urllib.parse import urlparse

import requests

if TYPE_CHECKING:
    import paho.mqtt.client as mqtt

CODE_PATTERN = re.compile(r"\d{6}")
ROOM_PATTERN = re.compile(r"[A-Za-z0-9-]{1,20}")

MESSAGES = {
    "ok": "ยืนยันสำเร็จ สามารถเปิดประตูได้",
    "bad_format": "กรุณากรอกรหัส 6 หลัก",
    "invalid_code": "รหัสไม่ถูกต้อง",
    "not_in_stay": "รหัสนี้ใช้ได้เฉพาะในช่วงเวลาเข้าพัก",
    "temporary_expired": "รหัสชั่วคราวหมดอายุแล้ว",
    "locked_out": "กรอกรหัสผิดหลายครั้ง กรุณารอสักครู่",
    "device_unauthorized": "อุปกรณ์ประตูไม่ได้รับอนุญาต (ตรวจ DAS_DEVICE_KEY)",
    "server_unreachable": "ติดต่อเซิร์ฟเวอร์ไม่ได้ ประตูยังล็อกอยู่",
    "server_error": "ระบบตรวจสอบรหัสขัดข้อง ประตูยังล็อกอยู่",
}


# --------------------------------------------------------------------------- settings
def load_env_file(path: Path) -> None:
    """Read KEY=value lines into os.environ (values already set win)."""
    if not path.is_file():
        return
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))


def env_list(name: str, default: str = "") -> list[str]:
    return [part.strip() for part in os.getenv(name, default).split(",") if part.strip()]


def env_flag(name: str, default: bool) -> bool:
    value = os.getenv(name, "").strip().lower()
    if not value:
        return default
    return value in ("1", "true", "yes", "on")


@dataclass
class ServoConfig:
    """One servo motor. room '*' = turns for every room this Pi handles."""

    pin: int
    room: str
    reverse: bool
    closed_angle: float
    open_angle: float
    min_pulse_ms: float
    max_pulse_ms: float

    def serves(self, room: str) -> bool:
        return self.room in ("*", room)

    @classmethod
    def list_from_env(cls, rooms: list[str]) -> list["ServoConfig"]:
        """DAS_SERVO_PINS=18,13  DAS_SERVO_ROOMS=*,*  DAS_SERVO_REVERSE=0,1  (lists line up by position)."""
        try:
            pins = [int(p) for p in env_list("DAS_SERVO_PINS", "18,13")]
        except ValueError:
            raise SystemExit("DAS_SERVO_PINS ต้องเป็นเลข GPIO คั่นด้วยจุลภาค เช่น 18,13")
        servo_rooms = env_list("DAS_SERVO_ROOMS")
        reverse = [v.lower() in ("1", "true", "yes", "on") for v in env_list("DAS_SERVO_REVERSE")]
        if servo_rooms and len(servo_rooms) != len(pins):
            raise SystemExit("DAS_SERVO_ROOMS ต้องมีจำนวนเท่ากับ DAS_SERVO_PINS (ใช้ * = ทุกห้อง)")
        if reverse and len(reverse) != len(pins):
            raise SystemExit("DAS_SERVO_REVERSE ต้องมีจำนวนเท่ากับ DAS_SERVO_PINS เช่น 0,1")
        unknown = [r for r in servo_rooms if r != "*" and r not in rooms]
        if unknown:
            raise SystemExit(f"DAS_SERVO_ROOMS มีห้องที่ไม่อยู่ใน DAS_ROOMS: {unknown}")

        closed = float(os.getenv("DAS_SERVO_CLOSED_ANGLE", "0"))
        opened = float(os.getenv("DAS_SERVO_OPEN_ANGLE", "90"))
        if not (0 <= closed <= 180 and 0 <= opened <= 180):
            raise SystemExit("DAS_SERVO_CLOSED_ANGLE / DAS_SERVO_OPEN_ANGLE ต้องอยู่ระหว่าง 0-180")
        min_pulse = float(os.getenv("DAS_SERVO_MIN_PULSE_MS", "0.5"))
        max_pulse = float(os.getenv("DAS_SERVO_MAX_PULSE_MS", "2.5"))
        return [
            cls(
                pin=pin,
                room=servo_rooms[i] if servo_rooms else "*",
                reverse=reverse[i] if reverse else False,
                closed_angle=closed,
                open_angle=opened,
                min_pulse_ms=min_pulse,
                max_pulse_ms=max_pulse,
            )
            for i, pin in enumerate(pins)
        ]


@dataclass
class Settings:
    rooms: list[str]
    mqtt_url: str
    mqtt_user: str
    mqtt_password: str
    api_url: str
    device_key: str
    device_id: str
    unlock_seconds: float
    gpio_mode: str
    green_pin: int
    red_pin: int
    buzzer_pin: int
    relay_pin: int | None
    api_timeout: float
    servos: list["ServoConfig"]
    servo_move_seconds: float
    servo_hold: bool

    @classmethod
    def from_env(cls) -> "Settings":
        rooms = [r.strip() for r in os.getenv("DAS_ROOMS", "101").split(",") if r.strip()]
        bad = [r for r in rooms if not ROOM_PATTERN.fullmatch(r)]
        if not rooms or bad:
            raise SystemExit(f"DAS_ROOMS ไม่ถูกต้อง: {bad or 'ว่าง'}")
        relay = os.getenv("DAS_RELAY_PIN", "").strip()
        settings = cls(
            rooms=rooms,
            mqtt_url=os.getenv("DAS_MQTT_URL", "mqtt://localhost:1883"),
            mqtt_user=os.getenv("DAS_MQTT_USER", "das_door"),
            mqtt_password=os.getenv("DAS_MQTT_PASSWORD", ""),
            api_url=os.getenv("DAS_API_URL", "http://localhost:8080/api/door-authenticate.php"),
            device_key=os.getenv("DAS_DEVICE_KEY", ""),
            device_id=os.getenv("DAS_DEVICE_ID", socket.gethostname()),
            unlock_seconds=float(os.getenv("DAS_UNLOCK_SECONDS", "5")),
            gpio_mode=os.getenv("DAS_GPIO", "auto").lower(),
            green_pin=int(os.getenv("DAS_GREEN_PIN", "17")),
            red_pin=int(os.getenv("DAS_RED_PIN", "27")),
            buzzer_pin=int(os.getenv("DAS_BUZZER_PIN", "22")),
            relay_pin=int(relay) if relay else None,
            api_timeout=float(os.getenv("DAS_API_TIMEOUT", "5")),
            servos=ServoConfig.list_from_env(rooms),
            servo_move_seconds=float(os.getenv("DAS_SERVO_MOVE_SECONDS", "0.8")),
            servo_hold=env_flag("DAS_SERVO_HOLD", False),
        )
        if not settings.device_key:
            raise SystemExit("ยังไม่ได้ตั้งค่า DAS_DEVICE_KEY (ต้องตรงกับ DOOR_DEVICE_KEY ใน .env ของเซิร์ฟเวอร์)")
        return settings


# --------------------------------------------------------------------------- output
def now_text() -> str:
    return datetime.now().strftime("%Y-%m-%d %H:%M:%S")


def log(message: str) -> None:
    print(f"[{now_text()}] {message}", flush=True)


def banner(room: str, title: str, detail: str) -> None:
    line = "=" * 44
    print(f"\n{line}\n  ห้อง {room}  |  {title}\n  {detail}\n  {now_text()}\n{line}\n", flush=True)


class DoorServo:
    """A servo that turns between the closed and open angle (0-180 degrees scale).

    After each move the PWM signal is switched off (unless DAS_SERVO_HOLD=true),
    which stops the jitter/buzz of software PWM and keeps the servo cool.
    """

    def __init__(self, config: ServoConfig, move_seconds: float, hold: bool):
        from gpiozero import AngularServo

        self.config = config
        self.move_seconds = move_seconds
        self.hold = hold
        self._timer: threading.Timer | None = None
        self._lock = threading.Lock()
        self.servo = AngularServo(
            config.pin,
            min_angle=0,
            max_angle=180,
            min_pulse_width=config.min_pulse_ms / 1000,
            max_pulse_width=config.max_pulse_ms / 1000,
            initial_angle=None,  # do not move until we say so
        )

    def _physical(self, angle: float) -> float:
        # A reversed servo is mounted mirrored (e.g. the second leaf of a double door),
        # so it turns the other way for the same "open".
        return 180 - angle if self.config.reverse else angle

    def move(self, angle: float) -> None:
        with self._lock:
            if self._timer:
                self._timer.cancel()
            self.servo.angle = self._physical(angle)
            if not self.hold:
                self._timer = threading.Timer(self.move_seconds, self.servo.detach)
                self._timer.daemon = True
                self._timer.start()

    def open(self) -> None:
        self.move(self.config.open_angle)

    def close(self) -> None:
        self.move(self.config.closed_angle)

    def release(self) -> None:
        with self._lock:
            if self._timer:
                self._timer.cancel()
            self.servo.detach()
            self.servo.close()


class Hardware:
    """LEDs, buzzer, optional relay and servo motors. Falls back to screen output only when GPIO is missing."""

    def __init__(self, settings: Settings):
        self.settings = settings
        self.green = self.red = self.buzzer = self.relay = None
        self.servos: list[DoorServo] = []
        if settings.gpio_mode == "off":
            log("GPIO ปิดอยู่ (DAS_GPIO=off) แสดงผลบนหน้าจออย่างเดียว")
            return
        try:
            from gpiozero import LED, Buzzer, OutputDevice

            self.green = LED(settings.green_pin)
            self.red = LED(settings.red_pin)
            self.buzzer = Buzzer(settings.buzzer_pin)
            if settings.relay_pin is not None:
                self.relay = OutputDevice(settings.relay_pin, active_high=True, initial_value=False)
            log(f"GPIO พร้อม: LED เขียว={settings.green_pin} LED แดง={settings.red_pin} "
                f"buzzer={settings.buzzer_pin} relay={settings.relay_pin or '-'}")
        except Exception as error:  # not a Pi, or gpiozero missing
            if settings.gpio_mode == "on":
                raise
            log(f"ไม่ได้ใช้ GPIO ({error.__class__.__name__}) แสดงผลบนหน้าจออย่างเดียว")
            return

        for config in settings.servos:
            try:
                self.servos.append(DoorServo(config, settings.servo_move_seconds, settings.servo_hold))
                log(f"Servo พร้อม: GPIO{config.pin} ห้อง={config.room} "
                    f"ปิด={config.closed_angle:g}° เปิด={config.open_angle:g}°"
                    f"{' (กลับทิศ)' if config.reverse else ''}")
            except Exception as error:
                if settings.gpio_mode == "on":
                    raise
                log(f"ใช้ servo GPIO{config.pin} ไม่ได้ ({error.__class__.__name__}: {error})")

    def _servos_for(self, room: str | None) -> list[DoorServo]:
        return [s for s in self.servos if room is None or s.config.serves(room)]

    def unlocked(self, room: str) -> None:
        if self.red:
            self.red.off()
        if self.green:
            self.green.on()
        if self.relay:
            self.relay.on()
        targets = self._servos_for(room)
        for servo in targets:
            servo.open()
        if targets:
            log(f"ห้อง {room}: servo {', '.join(f'GPIO{s.config.pin}' for s in targets)} หมุนเปิดประตู")

    def locked(self, room: str | None = None, any_room_open: bool = False) -> None:
        """Lock one room (room=...) or every room (room=None)."""
        if not any_room_open:
            if self.green:
                self.green.off()
            if self.relay:
                self.relay.off()
        for servo in self._servos_for(room):
            # A servo shared by several rooms ('*') stays open while any room is still unlocked.
            if servo.config.room == "*" and any_room_open:
                continue
            servo.close()

    def denied(self) -> None:
        if self.red:
            self.red.blink(on_time=0.2, off_time=0.2, n=3, background=True)
        if self.buzzer:
            self.buzzer.beep(on_time=0.15, off_time=0.1, n=3, background=True)

    def shutdown(self) -> None:
        """Close every servo, give it time to turn, then free the pins."""
        self.locked()
        if self.servos:
            threading.Event().wait(self.settings.servo_move_seconds)
            for servo in self.servos:
                servo.release()


# --------------------------------------------------------------------------- server check
def verify_with_server(settings: Settings, room: str, code: str) -> dict:
    """Ask api/door-authenticate.php. Any failure keeps the door locked (fail-secure)."""
    try:
        response = requests.post(
            settings.api_url,
            json={"room": room, "code": code},
            headers={"X-Device-Key": settings.device_key, "X-Device-Id": settings.device_id},
            timeout=settings.api_timeout,
        )
    except requests.RequestException as error:
        log(f"เรียก API ไม่สำเร็จ: {error.__class__.__name__}")
        return {"result": "denied", "reason": "server_unreachable"}

    if response.status_code == 401:
        return {"result": "denied", "reason": "device_unauthorized"}
    try:
        body = response.json()
    except ValueError:
        return {"result": "denied", "reason": "server_error"}

    granted = response.status_code == 200 and body.get("open") is True and body.get("result") == "granted"
    return {
        "result": "granted" if granted else "denied",
        "reason": body.get("reason") or ("ok" if granted else "server_error"),
        "message": body.get("message"),
        "code_type": body.get("code_type"),
    }


# --------------------------------------------------------------------------- door
class Door:
    def __init__(self, settings: Settings, hardware: Hardware):
        self.settings = settings
        self.hardware = hardware
        self.jobs: queue.Queue[tuple[str, str, mqtt.Client | None]] = queue.Queue()
        self.timers: dict[str, threading.Timer] = {}
        self.lock = threading.Lock()
        threading.Thread(target=self._worker, name="door-worker", daemon=True).start()

    def submit(self, room: str, code: str, client: mqtt.Client | None = None) -> None:
        self.jobs.put((room, code, client))

    def _worker(self) -> None:
        while True:
            room, code, client = self.jobs.get()
            try:
                self.handle(room, code, client)
            except Exception as error:  # keep the door service alive
                log(f"ผิดพลาดขณะตรวจรหัสห้อง {room}: {error!r}")

    def handle(self, room: str, code: str, client: mqtt.Client | None = None) -> dict:
        masked = code[:2] + "****" if CODE_PATTERN.fullmatch(code) else "******"
        log(f"ห้อง {room}: ได้รับรหัส {masked}")

        if not CODE_PATTERN.fullmatch(code):
            outcome = {"result": "denied", "reason": "bad_format"}
        else:
            outcome = verify_with_server(self.settings, room, code)

        outcome["message"] = outcome.get("message") or MESSAGES.get(outcome["reason"], "ประตูยังล็อกอยู่")
        if outcome["result"] == "granted":
            self.unlock(room, outcome)
        else:
            banner(room, "ACCESS DENIED", f"{outcome['message']} ({outcome['reason']})")
            self.hardware.denied()

        payload = {"room": room, "time": now_text(), **{k: v for k, v in outcome.items() if v is not None}}
        if client is not None:
            client.publish(
                f"das/door/{room}/result",
                json.dumps(payload, ensure_ascii=False),
                qos=1,
            )
        return payload

    def unlock(self, room: str, outcome: dict) -> None:
        kind = "รหัสชั่วคราว" if outcome.get("code_type") == "temporary" else "รหัสหลัก"
        banner(room, "UNLOCKED", f"ปลดล็อก {self.settings.unlock_seconds:g} วินาที ({kind})")
        with self.lock:
            old = self.timers.pop(room, None)
            if old:
                old.cancel()
            self.hardware.unlocked(room)
            timer = threading.Timer(self.settings.unlock_seconds, self.relock, args=(room,))
            timer.daemon = True
            self.timers[room] = timer
            timer.start()

    def relock(self, room: str) -> None:
        with self.lock:
            self.timers.pop(room, None)
            self.hardware.locked(room, any_room_open=bool(self.timers))
        log(f"ห้อง {room}: LOCKED")


# --------------------------------------------------------------------------- MQTT
def make_client(settings: Settings, room: str, door: Door) -> mqtt.Client:
    import paho.mqtt.client as mqtt

    url = urlparse(settings.mqtt_url)
    websockets = url.scheme in ("ws", "wss")
    secure = url.scheme in ("wss", "mqtts")
    port = url.port or {"mqtt": 1883, "mqtts": 8883, "ws": 80, "wss": 443}.get(url.scheme, 1883)

    client = mqtt.Client(
        callback_api_version=mqtt.CallbackAPIVersion.VERSION2,
        client_id=f"das-door-{room}-{settings.device_id}"[:64],
        transport="websockets" if websockets else "tcp",
        clean_session=True,
    )
    if websockets:
        client.ws_set_options(path=url.path or "/mqtt")
    if secure:
        client.tls_set()
    client.username_pw_set(settings.mqtt_user, settings.mqtt_password)
    client.will_set(f"das/door/{room}/status", "offline", qos=1, retain=True)
    client.reconnect_delay_set(min_delay=1, max_delay=30)
    code_topic = f"das/door/{room}/code"

    def on_connect(c, userdata, flags, reason_code, properties):
        if reason_code.is_failure:
            log(f"ห้อง {room}: เชื่อมต่อ MQTT ไม่สำเร็จ ({reason_code})")
            return
        c.subscribe(code_topic, qos=1)
        c.publish(f"das/door/{room}/status", "online", qos=1, retain=True)
        log(f"ห้อง {room}: เชื่อมต่อแล้ว รอรหัสที่ {code_topic}")

    def on_disconnect(c, userdata, flags, reason_code, properties):
        log(f"ห้อง {room}: ขาดการเชื่อมต่อ MQTT ({reason_code}) กำลังเชื่อมต่อใหม่...")

    def on_message(c, userdata, msg):
        if msg.retain:  # never act on an old stored code
            return
        door.submit(room, msg.payload.decode("utf-8", errors="ignore").strip(), c)

    client.on_connect = on_connect
    client.on_disconnect = on_disconnect
    client.on_message = on_message
    client.connect_async(url.hostname or "localhost", port, keepalive=60)
    return client


def main() -> int:
    load_env_file(Path(__file__).with_name("door.env"))
    parser = argparse.ArgumentParser(description="DAS digital door lock simulation")
    parser.add_argument("--check", nargs=2, metavar=("ROOM", "CODE"), help="ตรวจรหัสกับ API ครั้งเดียว แล้วออก")
    parser.add_argument("--mqtt", action="store_true", help="เปิดโหมดทดลอง MQTT แทน API-only stdin")
    parser.add_argument("--test-servo", nargs="?", const="*", metavar="ROOM",
                        help="ทดสอบ servo: หมุนเปิด รอ DAS_UNLOCK_SECONDS แล้วหมุนปิด (ไม่ต้องใช้เซิร์ฟเวอร์)")
    args = parser.parse_args()

    if args.test_servo:
        os.environ.setdefault("DAS_DEVICE_KEY", "servo-test")  # no server call in this mode
    settings = Settings.from_env()
    hardware = Hardware(settings)
    door = Door(settings, hardware)
    hardware.locked()

    if args.test_servo:
        room = settings.rooms[0] if args.test_servo == "*" else args.test_servo
        if not hardware.servos:
            log("ไม่พบ servo ที่ใช้งานได้ (ตรวจ DAS_GPIO, DAS_SERVO_PINS และว่ารันบน Raspberry Pi)")
            return 1
        threading.Event().wait(settings.servo_move_seconds)
        door.unlock(room, {"code_type": "main"})
        threading.Event().wait(settings.unlock_seconds + settings.servo_move_seconds + 0.2)
        hardware.shutdown()
        return 0

    if args.check:
        result = door.handle(args.check[0], args.check[1])
        print(json.dumps(result, ensure_ascii=False, indent=2))
        if result["result"] == "granted":
            threading.Event().wait(settings.unlock_seconds + 0.2)
        hardware.shutdown()
        return 0 if result["result"] == "granted" else 1

    stop = threading.Event()
    signal.signal(signal.SIGTERM, lambda *_: stop.set())
    signal.signal(signal.SIGINT, lambda *_: stop.set())

    if args.mqtt:
        log(f"DAS Door Lock เริ่มโหมด MQTT ทดลอง ห้อง {', '.join(settings.rooms)} | API {settings.api_url}")
        clients = [make_client(settings, room, door) for room in settings.rooms]
        for client in clients:
            client.loop_start()
        stop.wait()
        for room, client in zip(settings.rooms, clients):
            try:
                client.publish(f"das/door/{room}/status", "offline", qos=1, retain=True).wait_for_publish(2)
            except (RuntimeError, ValueError):
                pass
            client.disconnect()
            client.loop_stop()
    else:
        log(f"DAS Door Lock เริ่มโหมด API-only ห้อง {', '.join(settings.rooms)} | API {settings.api_url}")
        log("รอรหัสรูปแบบ ROOM CODE เช่น 101 123456 (คั่นด้วยช่องว่าง)")
        for line in sys.stdin:
            if stop.is_set():
                break
            parts = line.strip().split()
            if len(parts) != 2:
                log("รูปแบบไม่ถูกต้อง ต้องเป็น ROOM CODE เช่น 101 123456")
                continue
            room, code = parts
            if room not in settings.rooms:
                log(f"ห้องไม่อยู่ใน DAS_ROOMS: {room}")
                continue
            result = door.handle(room, code)
            print(json.dumps(result, ensure_ascii=False), flush=True)

    log("กำลังปิดโปรแกรม...")
    hardware.shutdown()
    return 0


if __name__ == "__main__":
    sys.exit(main())
