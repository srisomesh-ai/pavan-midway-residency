/*
 * Firebase settings for the browser.
 *
 * FILL THIS IN after adding a Web app to your Firebase project.
 * Firebase console -> Project settings -> Your apps -> Web app -> Config
 *
 * These values are not secret. They identify the project publicly and are
 * meant to be visible in the browser. The private key lives in
 * public_html/fcm-key.json and is never sent to the browser.
 *
 * Until this is filled in, the app works exactly as before - notifications
 * appear in the bell, just not on a locked phone.
 */
window.PMR_FIREBASE = {
  apiKey:            "",
  authDomain:        "",
  projectId:         "",
  storageBucket:     "",
  messagingSenderId: "",
  appId:             ""
};

/*
 * Web Push certificate key pair (the "VAPID key").
 * Firebase console -> Project settings -> Cloud Messaging
 *   -> Web configuration -> Web Push certificates -> Key pair
 */
window.PMR_VAPID_KEY = "";
