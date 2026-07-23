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
| `dashboard.html` | Admin dashboard |
| `resident-form.html` | Public resident form, English and Telugu |
| `submissions.html` | Committee review screen |
| `sql/01_schema.sql` | Tables |
| `sql/02_seed.sql` | 140 flats + default admin + settings |
| `sql/03_migrate_flat_structure.sql` | Migration from the old 144-flat seed |
| `sql/04_resident_form.sql` | Resident form and approval tables |

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

The form asks for owner name and mobile, up to three vehicle numbers, and then branches:

| Flat status | Extra questions |
|---|---|
| Owner is staying | How many people live there |
| Given on rent | Tenant name, mobile, family size, rent, lease dates |
| Vacant | Vacant since when, looking to rent, expected rent |

The form is available in English and Telugu via the toggle in the header. Anyone with the link can submit, which is why nothing appears in the app until the committee approves it. If a flat already has details, the review screen flags that approving will replace them.

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
| 2 | Resident directory |
| 3 | Notices + push |
| 4 | Maintenance billing |
| 5 | Payments (Razorpay) |
| 6 | Dues dashboard |
| 7 | Expenses + monthly reports |
| 8 | Visitor approvals |
| 9 | Polish |
| 10 | APK build |
