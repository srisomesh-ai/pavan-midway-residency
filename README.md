# Pavan Midway Residency — Community Management App

Mobile-first web app for a 140-flat residential society. Built to run on Android phones and later wrap into an APK.

## Sprint 1 — Admin Authentication (current)

| File | Purpose |
|---|---|
| `index.html` | Admin login screen (mobile-first) |
| `api/config.php` | DB credentials + app constants |
| `api/helpers.php` | JSON output, auth guards, activity logging |
| `api/login.php` | Login with brute-force protection |
| `api/me.php` | Session validation |
| `api/logout.php` | Session revocation |
| `api/change_password.php` | Password change |
| `api/dashboard.php` | Summary counters for the admin home |
| `api/flats.php` | Flat register, grouped by block and floor |
| `api/diag.php` | Setup checker - open in a browser to test the install |
| `api/.htaccess` | Passes the Authorization header through on shared hosting |
| `api/public_flats.php` | Flat list for the public form (no login) |
| `api/submit_details.php` | Receives resident form submissions |
| `api/submissions.php` | Admin list, approve, reject |
| `api/flat_detail.php` | Everything known about one flat |
| `api/accounts.php` | Create resident and guard logins |
| `api/resident_home.php` | Resident home screen data |
| `api/visitors.php` | Visitor entries, approvals, gate passes |
| `api/away.php` | Away and travel notices |
| `api/complaints.php` | Complaints, suggestions, replies |
| `api/notifications.php` | Notification inbox and read state |
| `api/notices.php` | Committee announcements |
| `api/gate.php` | Public gate API, no login |
| `dashboard.html` | Admin dashboard |
| `resident-form.html` | Public resident form, English and Telugu |
| `submissions.html` | Committee review screen |
| `accounts.html` | Issue resident and guard logins |
| `resident.html` | Resident home |
| `my-visitors.html` | Resident visitor log and pre-approvals |
| `my-tickets.html` | Complaints - resident and committee views |
| `gate.html` | Security gate screen |
| `notices.html` | Notice board - post and read |
| `assets/notify.js` | Notification bell, shared by every page |
| `assets/qrcode.min.js` | QR generator, runs locally (MIT) |
| `assets/push.js` | Asks permission and registers the device |
| `assets/firebase-config.js` | Firebase settings - fill in to enable push |
| `api/fcm_send.php` | Sends push through Firebase Cloud Messaging |
| `api/push_register.php` | Stores device tokens |
| `firebase-messaging-sw.js` | Shows notifications while the app is closed |
| `sql/01_schema.sql` | Tables |
| `sql/02_seed.sql` | 140 flats + default admin + settings |
| `sql/03_migrate_flat_structure.sql` | Migration from the old 144-flat seed |
| `sql/04_resident_form.sql` | Resident form and approval tables |
| `sql/05_vehicle_types.sql` | Two wheeler / four wheeler per vehicle |
| `sql/06_resident_app.sql` | Resident logins, visitors, away notices, complaints |
| `sql/07_notifications.sql` | Notifications and committee notices |
| `sql/08_open_gate.sql` | Open gate page reached by QR code |

## Building structure (fixed — not editable from the UI)

| | |
|---|---|
| Blocks | 2 — Block A, Block B |
| Floors per block | 5 — Ground, 1st, 2nd, 3rd, 4th |
| Flats per floor | 14 — A to N |
| **Total flats** | **140** (70 per block) |

Flat numbers per floor:

```
Ground : GR-A  GR-B  GR-C … GR-N
1st    : 1A    1B    1C   … 1N
2nd    : 2A    2B    2C   … 2N
3rd    : 3A    3B    3C   … 3N
4th    : 4A    4B    4C   … 4N
```

Flat numbers repeat across blocks, so `1A` exists in both. Each flat therefore has a unique `flat_code` that includes the block:

| Column | Example | Use |
|---|---|---|
| `flat_no` | `1A` | What residents say and see |
| `flat_code` | `A-1A`, `B-1A` | Unique ID for receipts and lookups |

Blocks and flats are seeded with `is_locked = 1`. This is structural data — the app will display it but never offer edit or delete.

## Setup

**1. Create the database** in hPanel → Databases → MySQL. Note the DB name, user, and password.

**2. Import the SQL** in phpMyAdmin.

*Fresh database:*
```
sql/01_schema.sql
sql/02_seed.sql
```

*Already imported the earlier 144-flat version:*
```
sql/03_migrate_flat_structure.sql
sql/02_seed.sql
```
The migration clears the old flats and rebuilds the correct 140. Your admin login is preserved.

**3. Edit `api/config.php`** with your real DB credentials:
```php
define('DB_NAME', 'u000000000_pmr');
define('DB_USER', 'u000000000_pmradmin');
define('DB_PASS', 'your_password');
```

**4. Open the site.** Log in with:

```
Username : admin
Password : Admin@123
```

> **Change this password immediately.** The default is public in this repo.

## Seeded data

- 2 blocks, 140 flats, all marked vacant and locked
- 1 super admin
- Default settings — maintenance amount, due day, and late fee all start at 0 and need committee values

## Collecting resident details

Rather than typing in 140 flats by hand, share the resident form link and let people fill in their own details.

1. Open the dashboard and tap **Share** under Resident details
2. Copy the link and post it in the residents WhatsApp group
3. Residents pick their flat, fill the form, and submit
4. Each submission waits in **Submissions** until a committee member approves it
5. On approval the details go live and the flat occupancy updates automatically

The form asks for owner name and mobile, up to three vehicles (number plus two wheeler or four wheeler), and then branches:

| Flat status | Extra questions |
|---|---|
| Owner is staying | How many people live there |
| Given on rent | Tenant name, mobile, family size, rent, lease dates |
| Vacant | Vacant since when, looking to rent, expected rent |

Tap any flat in the register to see everything collected for it: owner and tenant names, phone numbers as tappable call links, vehicles with their type, family size, rent and lease dates. Flats with nothing collected yet say so.

The form is available in English and Telugu via the toggle in the header. Anyone with the link can submit, which is why nothing appears in the app until the committee approves it. If a flat already has details, the review screen flags that approving will replace them.

## Resident app

Once details are collected, the committee issues logins from **accounts.html**. Each flat gets a username and a readable password like `CalmNest89` to hand over. For rented flats the login goes to the tenant, otherwise the owner.

Residents can then:

- **See their visitors** - who came, when they entered and left
- **Approve or deny** a visitor while the guard waits at the gate
- **Pre-approve** a regular visitor (maid, cook, driver) and get a gate pass code, so they are let in without disturbing the resident again
- **Post an away notice** with dates, a contact number, and who holds the keys
- **Raise a complaint or suggestion**, with an anonymous option, and follow the committee's replies

### The gate

Security staff do not use the app at all. The **visitor** scans a QR code at the gate on their own phone.

1. The visitor fills in their name, mobile number, block, flat and purpose
2. They tap **Request permission**
3. A waiting screen appears with a timer counting up
4. The resident gets a notification and taps allow or deny
5. The visitor's screen turns green with **APPROVED** in large letters, or red with **REJECTED**, stamped with the date and time
6. The visitor shows that screen to security, who simply look at it and let them in

Security tap nothing. If the resident does not answer within 30 minutes the screen shows **NO REPLY**.

The committee gets the QR from **Logins - Gate**: a code to print, the link, and a copy button. The page never shows resident names, phone numbers or flat details - only flat numbers - so it is safe to leave open at a gate.

Three controls sit behind it, all in `settings`:

| Setting | Purpose |
|---|---|
| `gate_open` | Set to `0` to switch the page off completely |
| `gate_key` | Leave empty for an open link. Put a value here and the page needs `?k=<value>`, so the link can be locked down later without reprinting the QR |
| `gate_max_per_hour` | Requests allowed from one device per hour, default 20 |

Residents only ever see their own flat's data - enforced server side, not just hidden in the interface.

## Notifications

A bell sits in the header of every signed-in page with an unread count. Tapping it opens the list; tapping an item marks it read and jumps to the right page. New items also slide in as a banner while the app is open.

What triggers what:

| Event | Who is notified |
|---|---|
| Committee posts a notice | Every resident in the chosen audience |
| Visitor arrives at the gate | The resident of that flat |
| Resident allows or denies | The guard who logged it |
| Resident raises a complaint or suggestion | The whole committee |
| Committee replies or changes status | The resident |
| Resident posts an away notice | The whole committee |
| Resident submits their details form | The whole committee |

Notices can be aimed at everyone, one block, owners only, or tenants only, and urgent ones are highlighted.

Notifications appear in the bell straight away. To make them arrive on a
**locked phone**, follow `FIREBASE-SETUP.md` - it takes about ten minutes and
uses its own Firebase project, kept separate from any other app. Until then the
app works exactly as described, just without lock-screen alerts.

## Installing on a phone

The site is a Progressive Web App. Opening it in Chrome on Android shows an
**Add to home screen** prompt - accepting gives an icon that opens fullscreen
with no browser bar. Nothing to install from a store.

An offline screen appears instead of a browser error if the connection drops.

### Building an APK

`android/` holds everything needed to wrap the site as a real Android app.
See `android/README.md` for the steps. The APK loads the live site, so website
updates reach the app with no rebuild.

## Troubleshooting

Open `https://your-site.com/api/diag.php` in a browser. It reports PHP version, database connection, table and flat counts, and whether the Authorization header survives your host - then lists any problems it finds. No passwords or tokens are echoed.

Common issues:

| Symptom | Cause | Fix |
|---|---|---|
| "Unexpected end of JSON input" | An API file is missing or PHP hit a fatal error | Check `diag.php`; set `DEBUG` true in `config.local.php` |
| Dashboard bounces back to login | Host strips the `Authorization` header | `api/.htaccess` handles this; the token is also sent in the URL as a fallback |
| "Setup incomplete" | `config.local.php` missing | Copy `config.sample.php` to `config.local.php` and fill in credentials |
| Flat count is not 140 | Old seed still loaded | Run `sql/03_migrate_flat_structure.sql` then `sql/02_seed.sql` |

## Security notes

- Passwords hashed with bcrypt (`password_hash` / `password_verify`)
- Session tokens are random 32-byte values; only the SHA-256 hash is stored
- Account locks for 15 minutes after 5 failed attempts
- Separate IP-level throttle against distributed guessing
- Login failures return one uniform message so valid accounts cannot be enumerated
- Every login, logout, and password change is written to `activity_log`

Set `DEBUG` to `false` in `config.php` on production (it already is).

## Roadmap

| Sprint | Scope |
|---|---|
| 1 | Auth foundation + admin dashboard ← done |
| 2 | Resident details form ← done |
| 3 | Resident app, visitors, complaints ← done |
| 4 | Notifications and notice board ← done |
| 5 | Visitor self-service by QR ← done |
| 6 | Installable app, APK build files ← done |
| 7 | Firebase push notifications ← done, needs your keys |
| 3 | Notices + push |
| 4 | Maintenance billing |
| 5 | Payments (Razorpay) |
| 6 | Dues dashboard |
| 7 | Expenses + monthly reports |
| 8 | Visitor approvals |
| 9 | Polish |
| 10 | APK build |
