/*
 * Firebase messaging service worker.
 *
 * MUST sit at the site root - Firebase looks for it at exactly
 * /firebase-messaging-sw.js and nowhere else.
 *
 * This runs even when the app is closed. It is what puts a notification
 * on a locked phone.
 */

importScripts("https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js");
importScripts("https://www.gstatic.com/firebasejs/10.12.2/firebase-messaging-compat.js");

/* Same values as assets/firebase-config.js. A service worker cannot read
   window, so they are repeated here. Not secret - they identify the
   project publicly. */
firebase.initializeApp({
  apiKey:            "",
  authDomain:        "",
  projectId:         "",
  storageBucket:     "",
  messagingSenderId: "",
  appId:             ""
});

try {
  var messaging = firebase.messaging();

  messaging.onBackgroundMessage(function (payload) {
    var n = payload.notification || {};
    var d = payload.data || {};

    self.registration.showNotification(n.title || "Pavan Midway Residency", {
      body: n.body || "",
      icon: "/assets/icon-192.png",
      badge: "/assets/icon-192.png",
      tag: (d.entity || "pmr") + "-" + (d.entity_id || Date.now()),
      renotify: true,
      requireInteraction: d.kind === "visitor",
      data: { link: d.link || "index.html" }
    });
  });
} catch (e) {
  /* Config not filled in yet - nothing to do */
}

/* Tapping the notification opens the right page, reusing an open tab if there is one */
self.addEventListener("notificationclick", function (e) {
  e.notification.close();
  var link = (e.notification.data && e.notification.data.link) || "index.html";
  var url = new URL(link, self.location.origin).href;

  e.waitUntil(
    clients.matchAll({ type: "window", includeUncontrolled: true }).then(function (list) {
      for (var i = 0; i < list.length; i++) {
        if (list[i].url.indexOf(self.location.origin) === 0 && "focus" in list[i]) {
          list[i].navigate(url);
          return list[i].focus();
        }
      }
      if (clients.openWindow) return clients.openWindow(url);
    })
  );
});
