/*
 * Pavan Midway Residency - shared notification bell
 *
 * Include on any signed-in page:
 *   <script src="assets/notify.js"></script>
 * Then place a mount point in the header:
 *   <span id="bell"></span>
 *
 * Polls for new notifications and shows a panel when tapped.
 */
(function () {
  "use strict";

  var API = "api/";
  var KEY = "pmr_token";
  var POLL_MS = 45000;

  var token = null;
  try { token = localStorage.getItem(KEY) || sessionStorage.getItem(KEY); } catch (e) {}
  if (!token) return;

  var mount = document.getElementById("bell");
  if (!mount) return;

  var items = [];
  var unread = 0;
  var notReady = false;
  var open = false;
  var seenIds = {};
  var firstLoad = true;

  /* ---------- styles ---------- */
  var css = document.createElement("style");
  css.textContent = [
    "#bellBtn{position:relative;background:rgba(242,239,230,.09);border:1px solid rgba(242,239,230,.16);",
    "color:#F2EFE6;border-radius:10px;padding:9px;cursor:pointer;display:grid;place-items:center}",
    "#bellBtn:active{background:rgba(242,239,230,.18)}",
    "#bellDot{position:absolute;top:-5px;right:-5px;min-width:18px;height:18px;padding:0 5px;",
    "background:#C08A3E;color:#0C2A21;border-radius:9px;font-family:'Roboto Mono',monospace;",
    "font-size:10px;font-weight:600;display:none;align-items:center;justify-content:center;line-height:1}",
    "#bellDot.on{display:flex}",
    "#nVeil{position:fixed;inset:0;background:rgba(12,42,33,.5);display:none;align-items:flex-end;",
    "justify-content:center;z-index:200}",
    "#nVeil.on{display:flex}",
    "#nSheet{width:100%;max-width:430px;background:#F2EFE6;border-radius:20px 20px 0 0;",
    "padding:8px 0 24px;max-height:82vh;display:flex;flex-direction:column;",
    "animation:nUp .22s cubic-bezier(.2,.8,.3,1)}",
    "@keyframes nUp{from{transform:translateY(100%)}to{transform:translateY(0)}}",
    "#nGrab{width:36px;height:4px;border-radius:3px;background:#CFC8B5;margin:0 auto 14px;flex-shrink:0}",
    "#nHead{display:flex;align-items:center;justify-content:space-between;padding:0 22px 12px;flex-shrink:0}",
    "#nHead h3{font-family:Fraunces,Georgia,serif;font-weight:600;font-size:20px;margin:0;color:#123B2E}",
    "#nClear{background:none;border:0;font-family:'Roboto Mono',monospace;font-size:10px;",
    "letter-spacing:.1em;text-transform:uppercase;color:#6C7A72;cursor:pointer;padding:6px 0}",
    "#nList{overflow-y:auto;padding:0 22px 6px;-webkit-overflow-scrolling:touch}",
    ".nItem{display:flex;gap:11px;padding:13px 0;border-bottom:1px solid #E7E2D4;cursor:pointer}",
    ".nItem:last-child{border-bottom:0}",
    ".nIc{width:34px;height:34px;border-radius:9px;background:#E7E2D4;display:grid;place-items:center;",
    "flex-shrink:0;color:#123B2E}",
    ".nItem.unread .nIc{background:#123B2E;color:#F2EFE6}",
    ".nItem.urgent .nIc{background:#C08A3E;color:#0C2A21}",
    ".nTx{flex:1;min-width:0}",
    ".nTi{font-size:13.5px;font-weight:600;color:#12211C;line-height:1.35}",
    ".nItem.unread .nTi::after{content:'';display:inline-block;width:6px;height:6px;border-radius:50%;",
    "background:#C08A3E;margin-left:6px;vertical-align:middle}",
    ".nBo{font-size:12px;color:#6C7A72;margin-top:3px;line-height:1.45}",
    ".nWh{font-family:'Roboto Mono',monospace;font-size:9px;letter-spacing:.05em;color:#6C7A72;margin-top:5px}",
    "#nEmpty{text-align:center;padding:48px 22px;color:#6C7A72;font-size:13.5px;line-height:1.6}",
    "#nToast{position:fixed;left:50%;top:14px;transform:translateX(-50%) translateY(-140%);",
    "background:#123B2E;color:#F2EFE6;padding:13px 16px;border-radius:13px;z-index:300;",
    "max-width:390px;width:calc(100% - 28px);box-shadow:0 8px 26px rgba(12,42,33,.3);",
    "transition:transform .3s cubic-bezier(.2,.8,.3,1);cursor:pointer;display:flex;gap:11px;align-items:flex-start}",
    "#nToast.on{transform:translateX(-50%) translateY(0)}",
    "#nToast .tIc{width:30px;height:30px;border-radius:8px;background:#C08A3E;color:#0C2A21;",
    "display:grid;place-items:center;flex-shrink:0}",
    "#nToast .tTi{font-size:13.5px;font-weight:600;line-height:1.35}",
    "#nToast .tBo{font-size:12px;color:rgba(242,239,230,.65);margin-top:2px;line-height:1.4}",
    "@media (prefers-reduced-motion:reduce){#nSheet,#nToast{animation:none;transition:none}}"
  ].join("");
  document.head.appendChild(css);

  /* ---------- markup ---------- */
  mount.innerHTML =
    '<button id="bellBtn" aria-label="Notifications">' +
    '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
    '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>' +
    '<span id="bellDot"></span></button>';

  var veil = document.createElement("div");
  veil.id = "nVeil";
  veil.innerHTML =
    '<div id="nSheet"><div id="nGrab"></div>' +
    '<div id="nHead"><h3>Notifications</h3><button id="nClear">Mark all read</button></div>' +
    '<div id="nList"></div></div>';
  document.body.appendChild(veil);

  var toast = document.createElement("div");
  toast.id = "nToast";
  document.body.appendChild(toast);

  /* ---------- helpers ---------- */
  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function ago(ts) {
    if (!ts) return "";
    var d = new Date(String(ts).replace(" ", "T"));
    var s = Math.floor((Date.now() - d.getTime()) / 1000);
    if (isNaN(s)) return "";
    if (s < 60) return "just now";
    if (s < 3600) return Math.floor(s / 60) + "m ago";
    if (s < 86400) return Math.floor(s / 3600) + "h ago";
    return Math.floor(s / 86400) + "d ago";
  }

  var ICONS = {
    notice:  '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
    visitor: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 11h-6"/>',
    complaint: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    complaint_reply: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    away:    '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
    account: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    submission: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
    system:  '<circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><path d="M12 16.5v.01"/>'
  };

  function icon(kind) {
    return '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">' +
           (ICONS[kind] || ICONS.system) + '</svg>';
  }

  function api(path, opts) {
    opts = opts || {};
    opts.headers = opts.headers || {};
    opts.headers["Authorization"] = "Bearer " + token;
    var url = API + path + (path.indexOf("?") === -1 ? "?" : "&") + "token=" + encodeURIComponent(token);
    return fetch(url, opts).then(function (r) {
      if (r.status === 401) throw new Error("unauthorised");
      return r.text().then(function (t) {
        if (!t || !t.trim()) throw new Error("empty");
        try { return JSON.parse(t); } catch (e) { throw new Error("bad json"); }
      });
    });
  }

  /* ---------- render ---------- */
  function paint() {
    var dot = document.getElementById("bellDot");
    dot.textContent = unread > 99 ? "99+" : unread;
    dot.classList.toggle("on", unread > 0);

    var list = document.getElementById("nList");

    if (notReady) {
      list.innerHTML = '<div id="nEmpty" style="color:#B07A20;line-height:1.65">' +
        '<strong style="display:block;margin-bottom:6px;color:#12211C">Notifications are not switched on</strong>' +
        'Ask the committee to import<br><code style="font-family:\'Roboto Mono\',monospace;font-size:12px">sql/07_notifications.sql</code><br>in phpMyAdmin.</div>';
      return;
    }

    if (!items.length) {
      list.innerHTML = '<div id="nEmpty">Nothing yet.<br>You will see visitor requests, notices and replies here.</div>';
      return;
    }

    var h = "";
    items.forEach(function (n) {
      h += '<div class="nItem' + (n.is_read ? "" : " unread") + (n.is_urgent ? " urgent" : "") +
           '" data-id="' + n.id + '" data-link="' + esc(n.link || "") + '">';
      h += '<span class="nIc">' + icon(n.kind) + '</span>';
      h += '<span class="nTx"><span class="nTi">' + esc(n.title) + '</span>';
      if (n.body) h += '<span class="nBo">' + esc(n.body) + '</span>';
      h += '<span class="nWh">' + esc(ago(n.created_at)) + '</span></span>';
      h += '</div>';
    });
    list.innerHTML = h;
  }

  /* ---------- in-app alert for anything new ---------- */
  function popToast(n) {
    toast.innerHTML =
      '<span class="tIc">' + icon(n.kind) + '</span>' +
      '<span><span class="tTi">' + esc(n.title) + '</span>' +
      (n.body ? '<span class="tBo">' + esc(n.body) + '</span>' : '') + '</span>';
    toast.setAttribute("data-link", n.link || "");
    toast.classList.add("on");
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { toast.classList.remove("on"); }, 6000);
  }

  toast.addEventListener("click", function () {
    var link = this.getAttribute("data-link");
    this.classList.remove("on");
    if (link) location.href = link;
  });

  /* ---------- fetch ---------- */
  function refresh() {
    api("notifications.php")
      .then(function (d) {
        if (!d.ok) return;

        /* Table not created yet - say so instead of sitting silently at zero */
        if (d.ready === false) {
          notReady = true;
          unread = 0;
          items = [];
          paint();
          return;
        }
        notReady = false;

        var prevUnread = unread;
        unread = d.unread || 0;
        items = d.items || [];

        /* Announce anything genuinely new, but not on the first load */
        if (!firstLoad) {
          for (var i = items.length - 1; i >= 0; i--) {
            var n = items[i];
            if (!n.is_read && !seenIds[n.id]) { popToast(n); break; }
          }
        }
        items.forEach(function (n) { seenIds[n.id] = 1; });
        firstLoad = false;

        paint();
      })
      .catch(function () { /* stay quiet - never break the page */ });
  }

  /* ---------- interactions ---------- */
  document.getElementById("bellBtn").addEventListener("click", function () {
    open = true;
    veil.classList.add("on");
    paint();
  });

  veil.addEventListener("click", function (e) {
    if (e.target === veil) { veil.classList.remove("on"); open = false; }
  });

  document.getElementById("nList").addEventListener("click", function (e) {
    var it = e.target.closest(".nItem");
    if (!it) return;
    var id = parseInt(it.getAttribute("data-id"), 10);
    var link = it.getAttribute("data-link");

    api("notifications.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "read", id: id })
    }).catch(function () {});

    if (link) { location.href = link; }
    else { it.classList.remove("unread"); if (unread > 0) unread--; paint(); }
  });

  document.getElementById("nClear").addEventListener("click", function () {
    api("notifications.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "read_all" })
    }).then(function () {
      unread = 0;
      items.forEach(function (n) { n.is_read = true; });
      paint();
    }).catch(function () {});
  });

  /* ---------- start ---------- */
  refresh();
  setInterval(refresh, POLL_MS);

  /* Check again when the person returns to the tab */
  document.addEventListener("visibilitychange", function () {
    if (!document.hidden) refresh();
  });
})();
