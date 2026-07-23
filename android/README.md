# Building the Android APK

This wraps the website in a real Android app. Nothing is rewritten - the APK
opens the live site fullscreen with no browser bar, so every update you push
to Hostinger reaches the app immediately with no rebuild.

The build runs on **your computer**, not the server.

---

## What you need first

| | |
|---|---|
| Node.js 18 or newer | https://nodejs.org |
| Java JDK 17 | https://adoptium.net |
| Android SDK | Comes with Android Studio, or the command line tools |

Bubblewrap can download the Android SDK for you the first time, so Android
Studio is optional.

---

## 1. Install Bubblewrap

```bash
npm install -g @bubblewrap/cli
```

## 2. Get this folder onto your computer

Clone the repo, or just download the `android` folder.

```bash
git clone https://github.com/srisomesh-ai/pavan-midway-residency.git
cd pavan-midway-residency/android
```

## 3. Create your signing key

**This is the most important step.** The keystore is what proves future updates
come from you. If you lose it you can never update the app - you would have to
publish it again under a new name.

```bash
keytool -genkeypair \
  -v -keystore pmr-release.keystore \
  -alias pmr \
  -keyalg RSA -keysize 2048 -validity 10000
```

It asks for a password and a few details. Write the password down somewhere safe.

> Back up `pmr-release.keystore` to Google Drive or similar **today**. This is
> exactly what went wrong with the BharatGPS app.

## 4. Build

```bash
bubblewrap init --manifest=https://hen-oyster-283741.hostingersite.com/manifest.json
bubblewrap build
```

The first command reads the site's manifest and sets the project up. When it
asks about signing, point it at the keystore you just made.

You will get **`app-release-signed.apk`** in the same folder.

## 5. Install it

Copy the APK to an Android phone and open it. Android will warn about
installing outside the Play Store - that is normal for a file shared directly.
Tap through and it installs.

To share with residents: put the APK on Google Drive and send the link.

---

## Removing the browser address bar

Out of the box the app shows a thin address bar at the top. To remove it,
Android needs proof that you own the website.

**1. Get the fingerprint of your key**

```bash
keytool -list -v -keystore pmr-release.keystore -alias pmr
```

Copy the long `SHA256` line - it looks like `AB:CD:EF:...`

**2. Create the link file**

Make a file called `assetlinks.json` containing:

```json
[{
  "relation": ["delegate_permission/common.handle_all_urls"],
  "target": {
    "namespace": "android_app",
    "package_name": "com.pavanmidway.residency",
    "sha256_cert_fingerprints": ["PASTE_YOUR_SHA256_HERE"]
  }
}]
```

**3. Upload it** to your server so it is reachable at exactly:

```
https://hen-oyster-283741.hostingersite.com/.well-known/assetlinks.json
```

In hPanel File Manager, create a folder named `.well-known` in `public_html`
and put the file inside.

**4. Reinstall the app.** The address bar is gone.

---

## Updating the app later

Website changes need **no rebuild** - the app loads the live site.

You only rebuild when the icon, name, or app settings change:

1. Raise `appVersionCode` and `appVersionName` in `twa-manifest.json`
2. Run `bubblewrap build` again
3. Share the new APK

---

## If you later want the Play Store

You would need a Play Console account (one-time US$25) and an
`app-release-bundle.aab`, which `bubblewrap build` also produces. Play Store
listing requires a privacy policy URL, screenshots, and a description.

Not required for sharing the APK directly.

---

## Notes

- `packageId` is `com.pavanmidway.residency`. Once published this can never
  change, so decide now if you want something different.
- The `host` in `twa-manifest.json` is your Hostinger URL. If you move to a
  custom domain later, update it and rebuild.
- Notifications inside the app work the same as in the browser. Lock-screen
  notifications still need Firebase Cloud Messaging, which is not set up yet.
