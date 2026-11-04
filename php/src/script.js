/* =========================================================
   DOI MOK HOUSE — front-end logic
   - renders the 6 room cards
  - any booking / account action routes to login.php
   - viewing content never requires an account
   ========================================================= */

const ROOMS = [
  {
    code: "R-01",
    name: "ห้องยอดหมอก",
    view: "ทิศตะวันออก",
    size: 28,
    price: 10,
    desc: "ห้องมุมสูงสุดของอาคาร มองเห็นทะเลหมอกยามเช้าเต็มผืน มีระเบียงกว้างพร้อมเก้าอี้ผ้าใบ",
    tone: "linear-gradient(135deg,#2C4E3C,#1B3A2F)"
  },
  {
    code: "R-02",
    name: "ห้องเชิงเขา",
    view: "ทิศใต้",
    size: 26,
    price: 10,
    desc: "อยู่ชั้นล่างติดสวน เดินออกไปถึงทางเดินป่าได้โดยตรง เหมาะกับคนชอบตื่นเช้าไปเดินเล่น",
    tone: "linear-gradient(135deg,#3E6350,#1B3A2F)"
  },
  {
    code: "R-03",
    name: "ห้องริมไร่ชา",
    view: "ทิศตะวันตก",
    size: 30,
    price: 10,
    desc: "หน้าต่างบานใหญ่หันหาไร่ชาโดยตรง ตกเย็นแสงส้มลอดใบชาสวยที่สุดของที่พัก",
    tone: "linear-gradient(135deg,#4A6B4E,#22452F)"
  },
  {
    code: "R-04",
    name: "ห้องลมเหนือ",
    view: "ทิศเหนือ",
    size: 24,
    price: 10,
    desc: "ห้องเล็กกะทัดรัด ลมเย็นตลอดวัน มีเตาผิงในตัวสำหรับคืนที่อากาศลงต่ำ",
    tone: "linear-gradient(135deg,#355246,#152F25)"
  },
  {
    code: "R-05",
    name: "ห้องแสงแรก",
    view: "ทิศตะวันออกเฉียงเหนือ",
    size: 32,
    price: 10,
    desc: "ห้องครอบครัวขนาดใหญ่ที่สุด แบ่งเป็นสองระดับ รับแสงแรกของวันเต็มห้องนอนใหญ่",
    tone: "linear-gradient(135deg,#5A7A55,#1B3A2F)"
  },
  {
    code: "R-06",
    name: "ห้องดาวเหนือ",
    view: "ดาดฟ้าเปิด",
    size: 27,
    price: 10,
    desc: "หลังคาส่วนหนึ่งเป็นกระจกใส มองเห็นดาวจากบนเตียงได้ในคืนฟ้าใส",
    tone: "linear-gradient(135deg,#233F30,#0F241B)"
  }
];

function thb(n){
  return n.toLocaleString("th-TH");
}

function renderRooms(){
  const grid = document.getElementById("roomGrid");
  if(!grid) return;

  grid.innerHTML = ROOMS.map(room => `
    <article class="room-card" data-room="${room.code}">
      <div class="room-visual" style="background:${room.tone}">
        <span class="room-code">${room.code}</span>
        <span class="room-capacity">${room.size} ตร.ม.</span>
      </div>
      <div class="room-body">
        <h3>${room.name}</h3>
        <p>${room.desc}</p>
        <div class="room-meta">
          <span class="room-price">฿${thb(room.price)}<span> / คืน</span></span>
          <span class="room-code" style="color:var(--sage)">${room.view}</span>
        </div>
        <div class="room-actions">
          <button type="button" class="btn btn-outline" data-action="details" data-room="${room.code}">ดูรายละเอียด</button>
          <button type="button" class="btn btn-primary" data-action="book" data-room="${room.code}">จองห้องนี้</button>
        </div>
      </div>
    </article>
  `).join("");

  grid.addEventListener("click", handleRoomAction);
}

function isAuthenticated(){
  return document.body?.dataset.authenticated === "1";
}

function goToUserBooking(extraParams = {}){
  const params = new URLSearchParams(extraParams);
  if(!params.has("search") && params.has("checkin") && params.has("checkout")){
    params.set("search", "1");
  }
  window.location.href = `booking.php${params.toString() ? "?" + params.toString() : ""}`;
}

/* Viewing details stays in-page. Booking follows the original booking.php flow
  when logged in, otherwise routes to auth. */
function handleRoomAction(e){
  const btn = e.target.closest("button[data-action]");
  if(!btn) return;

  const roomCode = btn.dataset.room;

  if(btn.dataset.action === "book"){
    routeToAuthOrBooking({ room: roomCode, intent: "book" });
    return;
  }

  if(btn.dataset.action === "details"){
    const room = ROOMS.find(r => r.code === roomCode);
    if(room){
      alert(`${room.name} (${room.code})\n\nวิว: ${room.view}\nขนาด: ${room.size} ตร.ม.\nพักได้: ${room.capacity} ท่าน\nราคา: ฿${thb(room.price)} / คืน\n\n${room.desc}`);
    }
  }
}

/* Any attempt to search/book without a session sends the guest to auth.
  Logged in guests are sent to the original booking flow in booking.php. */
function routeToAuthOrBooking(extraParams = {}){
  if(isAuthenticated()){
    goToUserBooking(extraParams);
    return;
  }
  goToLogin(extraParams);
}

function goToLogin(extraParams = {}){
  const params = new URLSearchParams(extraParams);
  window.location.href = `login.php${params.toString() ? "?" + params.toString() : ""}`;
}

function bindSearchForm(){
  const form = document.getElementById("searchForm");
  if(!form) return;

  form.addEventListener("submit", (e) => {
    e.preventDefault();
    const checkin = form.checkin.value;
    const checkout = form.checkout.value;
    const guests = form.guests.value;

    if(checkin && checkout && checkin >= checkout){
      alert("วันเช็คเอาต์ต้องอยู่หลังวันเช็คอิน");
      return;
    }

    routeToAuthOrBooking({ checkin, checkout, guests, intent: "search", search: "1" });
  });
}

function bindMobileNav(){
  const toggle = document.getElementById("navToggle");
  const nav = document.getElementById("mainNav");
  if(!toggle || !nav) return;

  toggle.addEventListener("click", () => {
    nav.classList.toggle("open");
  });

  nav.querySelectorAll("a").forEach(link => {
    link.addEventListener("click", () => nav.classList.remove("open"));
  });
}

document.addEventListener("DOMContentLoaded", () => {
  renderRooms();
  bindSearchForm();
  bindMobileNav();
});
