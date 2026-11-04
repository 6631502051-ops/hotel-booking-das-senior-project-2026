// Door keypad: sends the 6-digit code to the Raspberry Pi over MQTT
// and shows the result that the Pi publishes back.
//
//   keypad --das/door/<room>/code-->  Mosquitto  --> Raspberry Pi (door_lock.py)
//   keypad <--das/door/<room>/result-- Mosquitto <-- Pi, after it asks api/door-authenticate.php
const RESULT_TIMEOUT_MS = 10000;

const form = document.getElementById("doorForm");
const roomInput = document.getElementById("room");
const codeInput = document.getElementById("code");
const submitButton = document.getElementById("submitButton");
const statusBox = document.getElementById("status");
const connectionNote = document.getElementById("connectionNote");
const doorState = document.getElementById("doorState");

let client = null;
let prefix = "das/door";
let waiting = null; // { room, timer }
const doorOnline = {};

function showStatus(message, type) {
  statusBox.textContent = message;
  statusBox.className = `status ${type}`;
  statusBox.hidden = false;
}

function setBusy(busy) {
  submitButton.disabled = busy || !client || !client.connected;
  submitButton.textContent = busy ? "กำลังตรวจสอบ..." : "ยืนยันเพื่อเปิดประตู";
}

function showDoorState() {
  const room = roomInput.value;
  if (!room) {
    doorState.textContent = "";
    return;
  }
  const state = doorOnline[room];
  doorState.textContent = state === "online"
    ? `ประตูห้อง ${room}: ออนไลน์`
    : state === "offline" ? `ประตูห้อง ${room}: ออฟไลน์` : `ประตูห้อง ${room}: ยังไม่ทราบสถานะ`;
  doorState.className = `door-state ${state || "unknown"}`;
}

function handleResult(room, payload) {
  if (!waiting || waiting.room !== room) return;
  clearTimeout(waiting.timer);
  waiting = null;
  setBusy(false);

  let result;
  try {
    result = JSON.parse(payload);
  } catch (error) {
    showStatus("ได้รับข้อมูลจากประตูไม่ถูกต้อง", "error");
    return;
  }

  if (result.result === "granted") {
    showStatus(`ปลดล็อกแล้ว ห้อง ${room} เปิดประตูได้`, "success");
    codeInput.value = "";
  } else {
    showStatus(result.message || "รหัสไม่ถูกต้อง ประตูยังล็อกอยู่", "error");
  }
}

async function connect() {
  connectionNote.textContent = "กำลังเชื่อมต่อกับประตู...";
  const response = await fetch("api/door-config.php", { cache: "no-store" });
  const config = await response.json();
  if (!response.ok || !config.ok) throw new Error(config.message || "โหลดการตั้งค่าไม่สำเร็จ");

  prefix = config.topic_prefix;
  const scheme = location.protocol === "https:" ? "wss" : "ws";
  const url = config.ws_url || `${scheme}://${location.host}${config.ws_path}`;

  client = mqtt.connect(url, {
    username: config.username,
    password: config.password,
    clientId: `das-keypad-${Math.random().toString(16).slice(2, 10)}`,
    reconnectPeriod: 3000,
    connectTimeout: 8000
  });

  client.on("connect", () => {
    connectionNote.textContent = "เชื่อมต่อกับประตูผ่าน MQTT แล้ว";
    client.subscribe([`${prefix}/+/result`, `${prefix}/+/status`], { qos: 1 });
    setBusy(false);
  });
  client.on("reconnect", () => { connectionNote.textContent = "กำลังเชื่อมต่อใหม่..."; });
  client.on("close", () => { connectionNote.textContent = "ขาดการเชื่อมต่อกับ MQTT"; setBusy(false); });
  client.on("error", (error) => { connectionNote.textContent = `เชื่อมต่อ MQTT ไม่สำเร็จ: ${error.message}`; });

  client.on("message", (topic, message) => {
    const parts = topic.split("/");
    const room = parts.at(-2);
    const kind = parts.at(-1);
    if (kind === "status") {
      doorOnline[room] = message.toString();
      showDoorState();
    } else if (kind === "result") {
      handleResult(room, message.toString());
    }
  });
}

roomInput.addEventListener("change", showDoorState);

form.addEventListener("submit", (event) => {
  event.preventDefault();
  const room = roomInput.value;
  const code = codeInput.value.trim();

  if (!room || !/^\d{6}$/.test(code)) {
    showStatus("กรุณาเลือกห้องและกรอกรหัส 6 หลัก", "error");
    return;
  }
  if (!client || !client.connected) {
    showStatus("ยังไม่ได้เชื่อมต่อกับประตู", "error");
    return;
  }

  statusBox.hidden = true;
  setBusy(true);
  waiting = {
    room,
    timer: setTimeout(() => {
      waiting = null;
      setBusy(false);
      showStatus("ประตูไม่ตอบสนอง ตรวจสอบว่า Raspberry Pi เปิดอยู่", "error");
    }, RESULT_TIMEOUT_MS)
  };
  client.publish(`${prefix}/${room}/code`, code, { qos: 1 });
});

connect().catch((error) => {
  connectionNote.textContent = error.message || "เชื่อมต่อไม่สำเร็จ";
});
