# Pavan Midway Residency — Community Management App

Mobile-first web app for a 144-flat residential society. Built to run on Android phones and later wrap into an APK.

## Sprint 1 — Admin Authentication (current)

| File | Purpose |
|---|---|
| `index.html` | Admin login screen (mobile-first) |
| `api/config.php` | DB credentials + app constants |
| `api/helpers.php` | JSON output, auth guards, activity logging |
| `api/login.php` | Login with brute-force protection |
| `api/me.php` | Session validation |
| `api/logout.php` | Session revocation |
| `api/change_password.php` | Password change (forced on first login) |
| `sql/01_schema.sql` | Tables |
| `sql/02_seed.sql` | 144 flats + default admin + settings |

## Setup

**1. Create the database** in hPanel → Databases → MySQL. Note the DB name, user, and password.

**2. Import the SQL** in phpMyAdmin, in order:
```
sql/01_schema.sql
sql/02_seed.sql
```

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

You will be sent to the password change screen on first login.

> **Change this password immediately.** The default is public in this repo.

## Seeded data

- **4 blocks** (A, B, C, D)
- **144 flats** — 9 floors × 4 units per block, numbered `A-101` … `D-904`
- **1 super admin**
- **Default settings** — maintenance amount, due day, and late fee all start at 0 and need committee values

Adjust the block/flat naming in `sql/02_seed.sql` before importing if your actual numbering differs.

## Security notes

- Passwords hashed with bcrypt (`password_hash` / `password_verify`)
- Session tokens are random 32-byte values; only the SHA-256 hash is stored
- Account locks for 15 minutes after 5 failed attempts
- Separate IP-level throttle against distributed guessing
- Login failures return one uniform message so valid accounts cannot be enumerated
- Every login, logout, and password change is written to `activity_log`

Set `DEBUG` to `false` in `config.php` on production (it already is).

## Not built yet

`dashboard.html` and `change-password.html` are referenced by the login redirect but come in the next sprint. Login will succeed and then hit a 404 until they exist.

## Roadmap

| Sprint | Scope |
|---|---|
| 1 | Auth foundation ← current |
| 2 | Resident directory |
| 3 | Notices + push |
| 4 | Maintenance billing |
| 5 | Payments (Razorpay) |
| 6 | Dues dashboard |
| 7 | Expenses + monthly reports |
| 8 | Visitor approvals |
| 9 | Polish |
| 10 | APK build |
