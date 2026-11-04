/* =========================================================
   DOI MOK HOUSE — auth page logic
   Front-end only: no real backend, validates shape of input
   and shows what the guest was trying to do before landing here.
   ========================================================= */

const ROOM_NAMES = {
  "R-01": "ห้องยอดหมอก",
  "R-02": "ห้องเชิงเขา",
  "R-03": "ห้องริมไร่ชา",
  "R-04": "ห้องลมเหนือ",
  "R-05": "ห้องแสงแรก",
  "R-06": "ห้องดาวเหนือ"
};

function showIntentContext(){
  const params = new URLSearchParams(window.location.search);
  const room = params.get("room");
  const intent = params.get("intent");
  const checkin = params.get("checkin");

  const box = document.getElementById("authContext");
  const detail = document.getElementById("authContextDetail");
  if(!box || !detail) return;

  if(room && ROOM_NAMES[room]){
    detail.textContent = `การจอง ${ROOM_NAMES[room]} (${room})`;
    box.hidden = false;
  } else if(intent === "search" && checkin){
    detail.textContent = `การค้นหาห้องว่างวันที่ ${checkin}`;
    box.hidden = false;
  }
}

function syncFormsFromAria(){
  const tabLogin = document.getElementById("tabLogin");
  const tabSignup = document.getElementById("tabSignup");
  const loginForm = document.getElementById("loginForm");
  const signupForm = document.getElementById("signupForm");

  const isLoginSelected = tabLogin.getAttribute("aria-selected") === "true";
  const isSignupSelected = tabSignup.getAttribute("aria-selected") === "true";
  const showLogin = isLoginSelected && !isSignupSelected;
  const showSignup = isSignupSelected && !isLoginSelected;
  const useLoginAsFallback = !showLogin && !showSignup;

  tabLogin.classList.toggle("active", showLogin || useLoginAsFallback);
  tabSignup.classList.toggle("active", showSignup);

  // Show exactly one form based on aria-selected state.
  loginForm.hidden = !(showLogin || useLoginAsFallback);
  signupForm.hidden = !showSignup;
}

function switchTab(target){
  const isLogin = target === "login";
  document.getElementById("tabLogin").setAttribute("aria-selected", isLogin ? "true" : "false");
  document.getElementById("tabSignup").setAttribute("aria-selected", isLogin ? "false" : "true");
  syncFormsFromAria();
}

function bindTabs(){
  const tabLogin = document.getElementById("tabLogin");
  const tabSignup = document.getElementById("tabSignup");

  tabLogin.addEventListener("click", () => switchTab("login"));
  tabSignup.addEventListener("click", () => switchTab("signup"));

  // Keep UI in sync if aria-selected is changed from elsewhere.
  const observer = new MutationObserver(syncFormsFromAria);
  observer.observe(tabLogin, { attributes: true, attributeFilter: ["aria-selected"] });
  observer.observe(tabSignup, { attributes: true, attributeFilter: ["aria-selected"] });
}

document.addEventListener("DOMContentLoaded", () => {
  showIntentContext();
  bindTabs();
  syncFormsFromAria();
});
