# Turning on lock-screen notifications

Right now notifications appear in the app's bell. This makes them arrive on a
locked phone, the same way the BharatGPS technician app works.

This uses its **own Firebase project**, separate from any other app you run.
Keeping them apart means a key change or problem on one never affects the other.

---

## 1. Create the project and add a Web app

1. Open https://console.firebase.google.com
2. **Add project** → name it `pavan-midway-residency`
3. Google Analytics can be switched off - it is not needed
4. Once created: **Project settings** (gear icon) → **Your apps** → click the **web** icon `</>`
5. Nickname it `Pavan Midway Residency`, register, and copy the config block

It looks like this:

```js
const firebaseConfig = {
  apiKey: "AIza...",
  authDomain: "yourproject.firebaseapp.com",
  projectId: "yourproject",
  storageBucket: "yourproject.appspot.com",
  messagingSenderId: "123456789012",
  appId: "1:123456789012:web:abc123"
};
```

## 2. Get the Web Push key

Still in **Project settings** → **Cloud Messaging** tab
→ **Web configuration** → **Web Push certificates** → **Generate key pair**

Copy the key string.

## 3. Fill in two files on the server

**`assets/firebase-config.js`** — paste the config values and the web push key:

```js
window.PMR_FIREBASE = {
  apiKey:            "AIza...",
  authDomain:        "yourproject.firebaseapp.com",
  projectId:         "yourproject",
  storageBucket:     "yourproject.appspot.com",
  messagingSenderId: "123456789012",
  appId:             "1:123456789012:web:abc123"
};
window.PMR_VAPID_KEY = "BN...the web push key...";
```

**`firebase-messaging-sw.js`** — paste the *same six config values* into the
`firebase.initializeApp({...})` block near the top. A service worker cannot read
`window`, which is why they appear twice.

> These values are not secret. They identify the project publicly and are meant
> to be visible in the browser.

## 4. Put the private key on the server

1. **Project settings** → **Service accounts** → **Generate new private key**
2. A `.json` file downloads — this one **is** secret
3. Rename it to **`fcm-key.json`**
4. Upload it to **`public_html/`** — the same folder as `index.html`, *not* inside `api/`

Generate a fresh key from this project. Do not reuse a key file from any other
app - that would tie the two together.

> Never commit this to GitHub. It is already in `.gitignore` and the deploy
> script refuses to push it.

## 5. Check it

Open `api/diag.php`. You want:

```
php_curl:    true
push_ready:  true
```

If `push_ready` is false it tells you exactly why.

## 6. Try it

1. Sign in as a resident on a phone
2. After a few seconds a bar appears: **Turn on notifications** — tap **Turn on**
3. Accept the browser prompt
4. Lock the phone
5. From another device, sign in as admin and post a notice

The phone should buzz with the notice on the lock screen.

---

## What triggers a push

Everything that already fills the bell:

| Event | Who gets it |
|---|---|
| Committee posts a notice | Every resident in the audience |
| Visitor requests entry at the gate | The resident of that flat |
| Resident allows or denies | Nothing pushed - the visitor's screen updates itself |
| Resident raises a complaint | The committee |
| Committee replies or changes status | The resident |
| Resident posts an away notice | The committee |
| Resident submits their details form | The committee |

Visitor requests are marked urgent, so they stay on screen until tapped.

---

## Notes

- **iPhone** needs the site added to the home screen first. Safari only allows
  notifications for installed web apps.
- **Android** works in Chrome directly, and also once installed.
- Each device registers separately, so a phone and a tablet both get notified.
- If a resident reinstalls or clears data, their device registers again
  automatically on next sign-in.
- Dead tokens are removed by themselves when Firebase reports the app was
  uninstalled.

## If nothing arrives

1. `api/diag.php` — is `push_ready` true?
2. Did the resident accept the browser prompt? Chrome → site settings → Notifications
3. Is `fcm-key.json` in `public_html`, not in `api/`?
4. Do the six values in `firebase-messaging-sw.js` match `assets/firebase-config.js`?
5. Check the server error log for lines starting `FCM send failed`
