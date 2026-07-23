/*
 * Pavan Midway Residency - PWA bootstrap
 *
 * Registers the service worker and offers an install prompt the first
 * time someone visits in a browser. Include on every page:
 *   <script src="assets/pwa.js"></script>
 */
(function () {
  "use strict";

  /* ---------- service worker ---------- */
  if ("serviceWorker" in navigator) {
    window.addEventListener("load", function () {
      navigator.serviceWorker.register("sw.js").catch(function () {
        /* Registration fails on http:// and that is fine - the app still works */
      });
    });
  }

  /* ---------- install prompt ---------- */
  var DISMISS_KEY = "pmr_install_dismissed";
  var deferred = null;

  function dismissed() {
    try { return localStorage.getItem(DISMISS_KEY) === "1"; } catch (e) { return false; }
  }
  function remember() {
    try { localStorage.setItem(DISMISS_KEY, "1"); } catch (e) {}
  }

  /* Already installed - nothing to offer */
  function standalone() {
    return window.matchMedia("(display-mode: standalone)").matches ||
           window.navigator.standalone === true;
  }

  window.addEventListener("beforeinstallprompt", function (e) {
    e.preventDefault();
    deferred = e;
    if (!dismissed() && !standalone()) {
      setTimeout(showBar, 2500);
    }
  });

  function showBar() {
    if (document.getElementById("pwaBar")) return;

    var css = document.createElement("style");
    css.textContent = [
      "#pwaBar{position:fixed;left:50%;bottom:18px;transform:translateX(-50%) translateY(140%);",
      "width:calc(100% - 32px);max-width:398px;background:#123B2E;color:#F2EFE6;",
      "border-radius:15px;padding:15px 16px;z-index:250;display:flex;gap:13px;align-items:center;",
      "box-shadow:0 10px 30px rgba(12,42,33,.34);transition:transform .32s cubic-bezier(.2,.8,.3,1)}",
      "#pwaBar.on{transform:translateX(-50%) translateY(0)}",
      "#pwaBar img{width:42px;height:42px;border-radius:10px;flex-shrink:0}",
      "#pwaBar .tx{flex:1;min-width:0}",
      "#pwaBar .t1{font-size:14px;font-weight:600;line-height:1.3}",
      "#pwaBar .t2{font-size:11.5px;color:rgba(242,239,230,.6);margin-top:2px;line-height:1.4}",
      "#pwaBar .add{background:#C08A3E;color:#0C2A21;border:0;padding:11px 15px;border-radius:10px;",
      "font-family:inherit;font-size:13.5px;font-weight:600;cursor:pointer;flex-shrink:0}",
      "#pwaBar .no{background:none;border:0;color:rgba(242,239,230,.45);font-family:inherit;",
      "font-size:20px;line-height:1;cursor:pointer;padding:4px 2px;flex-shrink:0}",
      "@media (prefers-reduced-motion:reduce){#pwaBar{transition:none}}"
    ].join("");
    document.head.appendChild(css);

    var bar = document.createElement("div");
    bar.id = "pwaBar";
    bar.innerHTML =
      '<img src="assets/icon-192.png" alt="">' +
      '<div class="tx"><div class="t1">Add to your home screen</div>' +
      '<div class="t2">Open it like an app, without the browser</div></div>' +
      '<button class="add" id="pwaAdd">Add</button>' +
      '<button class="no" id="pwaNo" aria-label="Not now">&times;</button>';
    document.body.appendChild(bar);

    requestAnimationFrame(function () { bar.classList.add("on"); });

    document.getElementById("pwaAdd").addEventListener("click", function () {
      bar.classList.remove("on");
      if (!deferred) return;
      deferred.prompt();
      deferred.userChoice.then(function () { deferred = null; });
    });

    document.getElementById("pwaNo").addEventListener("click", function () {
      bar.classList.remove("on");
      remember();
      setTimeout(function () { bar.remove(); }, 400);
    });
  }

  window.addEventListener("appinstalled", function () {
    remember();
    var b = document.getElementById("pwaBar");
    if (b) b.remove();
  });
})();
