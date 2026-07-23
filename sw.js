/*
 * Pavan Midway Residency - service worker
 *
 * Keeps the app shell available offline so opening the icon never shows
 * a browser error page. API calls always go to the network - stale
 * visitor or notice data would be worse than an honest error.
 */

var VERSION = "pmr-v1";
var SHELL = VERSION + "-shell";

var SHELL_FILES = [
  "./",
  "./index.html",
  "./resident.html",
  "./my-visitors.html",
  "./my-tickets.html",
  "./notices.html",
  "./gate.html",
  "./manifest.json",
  "./assets/notify.js",
  "./assets/icon-192.png",
  "./assets/icon-512.png",
  "./offline.html"
];

/* ---------- install ---------- */
self.addEventListener("install", function (e) {
  e.waitUntil(
    caches.open(SHELL).then(function (c) {
      /* addAll fails entirely if one file is missing, so add individually */
      return Promise.all(
        SHELL_FILES.map(function (f) {
          return c.add(f).catch(function () { /* skip anything unavailable */ });
        })
      );
    }).then(function () {
      return self.skipWaiting();
    })
  );
});

/* ---------- activate: drop old caches ---------- */
self.addEventListener("activate", function (e) {
  e.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(
        keys.filter(function (k) { return k.indexOf(VERSION) !== 0; })
            .map(function (k) { return caches.delete(k); })
      );
    }).then(function () {
      return self.clients.claim();
    })
  );
});

/* ---------- fetch ---------- */
self.addEventListener("fetch", function (e) {
  var req = e.request;

  if (req.method !== "GET") return;

  var url;
  try { url = new URL(req.url); } catch (err) { return; }

  /* Only handle our own origin */
  if (url.origin !== self.location.origin) return;

  /* API: always live. Never serve a cached visitor request or notice. */
  if (url.pathname.indexOf("/api/") !== -1) {
    e.respondWith(
      fetch(req).catch(function () {
        return new Response(
          JSON.stringify({ ok: false, error: "You appear to be offline. Check your connection and try again." }),
          { status: 503, headers: { "Content-Type": "application/json" } }
        );
      })
    );
    return;
  }

  /* Pages: try the network first so updates land, fall back to cache */
  if (req.mode === "navigate" || (req.headers.get("accept") || "").indexOf("text/html") !== -1) {
    e.respondWith(
      fetch(req).then(function (res) {
        var copy = res.clone();
        caches.open(SHELL).then(function (c) { c.put(req, copy); });
        return res;
      }).catch(function () {
        return caches.match(req).then(function (hit) {
          return hit || caches.match("./offline.html");
        });
      })
    );
    return;
  }

  /* Everything else (icons, scripts): cache first, it rarely changes */
  e.respondWith(
    caches.match(req).then(function (hit) {
      if (hit) return hit;
      return fetch(req).then(function (res) {
        if (res && res.status === 200 && res.type === "basic") {
          var copy = res.clone();
          caches.open(SHELL).then(function (c) { c.put(req, copy); });
        }
        return res;
      });
    })
  );
});
