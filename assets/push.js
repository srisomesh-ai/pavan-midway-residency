/*
 * Pavan Midway Residency - push notifications
 *
 * Asks for notification permission at a sensible moment, gets a device
 * token from Firebase, and registers it with the server so notifications
 * can reach a locked phone.
 *
 * Does nothing at all until assets/firebase-config.js is filled in, so it
 * is safe to ship before Firebase is set up.
 *
 * Include after notify.js:
 *   <script src="assets/firebase-config.js"></script>
 *   <script src="assets/push.js"></script>
 */
(function () {
  "use strict";

  var KEY = "pmr_token";
  var ASKED = "pmr_push_asked";
  var SAVED = "pmr_push_token";

  var token = null;
  try { token = localStorage.getItem(KEY) || sessionStorage.getItem(KEY); } catch (e) {}
  if (!token) return;

  var cfg = window.PMR_FIREBASE || {};
  var vapid = window.PMR_VAPID_KEY || "";

  /* Not configured yet - stay silent */
  if (!cfg.projectId || !cfg.messagingSenderId || !vapid) return;

  /* Browser cannot do push */
  if (!("Notification" in window) || !("serviceWorker" in navigator)) return;

  function api(path, body) {
    return fetch("api/" + path + "?token=" + encodeURIComponent(token), {
      method: "POST",
      headers: { "Content-Type": "application/json", "Authorization": "Bearer " + token },
      body: JSON.stringify(body || {})
    }).then(function (r) { return r.json(); });
  }

  function loadScript(src) {
    return new Promise(function (res, rej) {
      var s = document.createElement("script");
      s.src = src; s.onload = res; s.onerror = rej;
      document.head.appendChild(s);
    });
  }

  /* ---------- get the device token and send it to the server ---------- */
  function register() {
    return loadScript("https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js")
      .then(function () {
        return loadScript("https://www.gstatic.com/firebasejs/10.12.2/firebase-messaging-compat.js");
      })
      .then(function () {
        if (!firebase.apps.length) firebase.initializeApp(cfg);
        return navigator.serviceWorker.register("firebase-messaging-sw.js");
      })
      .then(function (reg) {
        var messaging = firebase.messaging();
        return messaging.getToken({ vapidKey: vapid, serviceWorkerRegistration: reg })
          .then(function (t) {
            if (!t) return null;

            /* Skip the round trip if this device is already registered */
            var prev = null;
            try { prev = localStorage.getItem(SAVED); } catch (e) {}
            if (prev === t) return t;

            return api("push_register.php", { token: t, platform: "web" })
              .then(function (d) {
                if (d && d.ok) {
                  try { localStorage.setItem(SAVED, t); } catch (e) {}
                }
                return t;
              });
          });
      })
      .catch(function (e) {
        /* Never break the page over a notification failure */
        if (window.console && console.warn) console.warn("push setup skipped:", e && e.message);
      });
  }

  /* ---------- ask permission ---------- */
  function ask() {
    if (Notification.permission === "granted") { register(); return; }
    if (Notification.permission === "denied") return;

    var asked = false;
    try { asked = localStorage.getItem(ASKED) === "1"; } catch (e) {}
    if (asked) return;

    setTimeout(showPrompt, 4000);
  }

  /* A short explanation before the browser's own dialog, so people know
     what they are agreeing to and are less likely to block it forever. */
  function showPrompt() {
    if (document.getElementById("pushBar")) return;

    var css = document.createElement("style");
    css.textContent = [
      "#pushBar{position:fixed;left:50%;bottom:18px;transform:translateX(-50%) translateY(140%);",
      "width:calc(100% - 32px);max-width:398px;background:#123B2E;color:#F2EFE6;border-radius:15px;",
      "padding:16px;z-index:260;box-shadow:0 10px 30px rgba(12,42,33,.34);",
      "transition:transform .32s cubic-bezier(.2,.8,.3,1)}",
      "#pushBar.on{transform:translateX(-50%) translateY(0)}",
      "#pushBar .row{display:flex;gap:12px;align-items:flex-start}",
      "#pushBar .ic{width:38px;height:38px;border-radius:10px;background:rgba(192,138,62,.2);",
      "display:grid;place-items:center;color:#C08A3E;flex-shrink:0}",
      "#pushBar .t1{font-size:14.5px;font-weight:600;line-height:1.35}",
      "#pushBar .t2{font-size:12px;color:rgba(242,239,230,.62);margin-top:3px;line-height:1.5}",
      "#pushBar .btns{display:flex;gap:8px;margin-top:13px}",
      "#pushBar button{flex:1;padding:12px;border:0;border-radius:10px;font-family:inherit;",
      "font-size:13.5px;font-weight:600;cursor:pointer}",
      "#pushYes{background:#C08A3E;color:#0C2A21}",
      "#pushNo{background:rgba(242,239,230,.1);color:rgba(242,239,230,.7)}",
      "@media (prefers-reduced-motion:reduce){#pushBar{transition:none}}"
    ].join("");
    document.head.appendChild(css);

    var bar = document.createElement("div");
    bar.id = "pushBar";
    bar.innerHTML =
      '<div class="row"><span class="ic">' +
      '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
      '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg></span>' +
      '<span><span class="t1">Turn on notifications</span>' +
      '<span class="t2">Know straight away when a visitor is at your gate, even with your phone locked.</span></span></div>' +
      '<div class="btns"><button id="pushNo">Not now</button><button id="pushYes">Turn on</button></div>';
    document.body.appendChild(bar);
    requestAnimationFrame(function () { bar.classList.add("on"); });

    document.getElementById("pushYes").addEventListener("click", function () {
      bar.classList.remove("on");
      try { localStorage.setItem(ASKED, "1"); } catch (e) {}
      Notification.requestPermission().then(function (p) {
        if (p === "granted") register();
      });
      setTimeout(function () { bar.remove(); }, 400);
    });

    document.getElementById("pushNo").addEventListener("click", function () {
      bar.classList.remove("on");
      try { localStorage.setItem(ASKED, "1"); } catch (e) {}
      setTimeout(function () { bar.remove(); }, 400);
    });
  }

  /* ---------- notifications arriving while the app is open ---------- */
  function listenForeground() {
    if (Notification.permission !== "granted") return;
    loadScript("https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js")
      .then(function () {
        return loadScript("https://www.gstatic.com/firebasejs/10.12.2/firebase-messaging-compat.js");
      })
      .then(function () {
        if (!firebase.apps.length) firebase.initializeApp(cfg);
        firebase.messaging().onMessage(function () {
          /* notify.js already polls and shows an in-app banner, so nothing
             extra is needed here - this just keeps the connection warm */
        });
      })
      .catch(function () {});
  }

  if (document.readyState === "complete") { ask(); listenForeground(); }
  else { window.addEventListener("load", function () { ask(); listenForeground(); }); }
})();
