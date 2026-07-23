<?php
/**
 * GET  /api/accounts.php            list flats with account status
 * POST /api/accounts.php
 *      { action: "create", flat_id }        create one resident login
 *      { action: "create_all" }             create for every flat with details
 *      { action: "reset", user_id }         issue a new temporary password
 *      { action: "disable", user_id }       suspend an account
 *      { action: "enable",  user_id }       re-activate
 *      { action: "create_guard", name }     create a gate login
 *
 * Admin only. Passwords are generated here and shown once so the
 * committee can hand them over.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

api_init();

$admin = require_admin();

/** Readable temporary password: two short words plus digits. */
function make_password() {
    $a = ['Blue','Green','Swift','Bright','Calm','Neat','Clear','Solid','Prime','Fresh'];
    $b = ['Gate','Lamp','Door','Path','Star','Wave','Rock','Leaf','Bell','Nest'];
    return $a[random_int(0, count($a) - 1)]
         . $b[random_int(0, count($b) - 1)]
         . random_int(10, 99);
}

/** Build a unique username from a flat code, e.g. A-1A -> a1a */
function make_username($flat_code) {
    $base = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $flat_code));
    $try  = $base;
    $n    = 1;
    while (true) {
        $st = db()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $st->execute([$try]);
        if (!$st->fetch()) return $try;
        $n++;
        $try = $base . $n;
        if ($n > 50) return $base . random_int(100, 999);
    }
}

/* ============================================================
   POST
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = param('action');

    /* ---------------- create one ---------------- */
    if ($action === 'create') {
        $flat_id = (int) param('flat_id', 0);
        if ($flat_id <= 0) fail('Missing flat id.');

        $st = db()->prepare(
            'SELECT f.id, f.flat_no, f.flat_code, fd.owner_name, fd.owner_mobile,
                    fd.tenant_name, fd.tenant_mobile, fd.status
             FROM flats f
             LEFT JOIN flat_details fd ON fd.flat_id = f.id
             WHERE f.id = ? LIMIT 1'
        );
        $st->execute([$flat_id]);
        $f = $st->fetch();
        if (!$f) fail('Flat not found.', 404);
        if (!$f['owner_name']) {
            fail('No resident details collected for this flat yet.', 409);
        }

        /* Existing account? */
        $st = db()->prepare('SELECT id FROM users WHERE flat_id = ? AND role = "resident" LIMIT 1');
        $st->execute([$flat_id]);
        if ($st->fetch()) {
            fail('This flat already has a login. Use reset password instead.', 409);
        }

        /* Tenant is the day to day occupant when the flat is rented */
        $rented = $f['status'] === 'rented' && $f['tenant_name'];
        $name   = $rented ? $f['tenant_name']   : $f['owner_name'];
        $mobile = $rented ? $f['tenant_mobile'] : $f['owner_mobile'];
        $rtype  = $rented ? 'tenant' : 'owner';

        /* A mobile can only belong to one account */
        if ($mobile) {
            $st = db()->prepare('SELECT id FROM users WHERE mobile = ? LIMIT 1');
            $st->execute([$mobile]);
            if ($st->fetch()) $mobile = null;
        }

        $username = make_username($f['flat_code']);
        $pw       = make_password();

        $st = db()->prepare(
            'INSERT INTO users (name, username, mobile, password_hash, role, status,
                                flat_id, resident_type, temp_password, must_change_pwd, created_by)
             VALUES (?,?,?,?, "resident", "active", ?,?,?, 1, ?)'
        );
        $st->execute([
            $name, $username, $mobile, password_hash($pw, PASSWORD_BCRYPT),
            $flat_id, $rtype, $pw, $admin['id'],
        ]);

        log_activity($admin['id'], 'account_created', 'user', db()->lastInsertId(), 'Flat ' . $f['flat_code']);

        ok([
            'created'  => 1,
            'flat_no'  => $f['flat_no'],
            'name'     => $name,
            'username' => $username,
            'password' => $pw,
        ]);
    }

    /* ---------------- create for every eligible flat ---------------- */
    if ($action === 'create_all') {
        $rows = db()->query(
            'SELECT f.id, f.flat_no, f.flat_code, fd.owner_name, fd.owner_mobile,
                    fd.tenant_name, fd.tenant_mobile, fd.status
             FROM flats f
             JOIN flat_details fd ON fd.flat_id = f.id
             LEFT JOIN users u ON u.flat_id = f.id AND u.role = "resident"
             WHERE u.id IS NULL
             ORDER BY f.id'
        )->fetchAll();

        $made = [];
        foreach ($rows as $f) {
            $rented = $f['status'] === 'rented' && $f['tenant_name'];
            $name   = $rented ? $f['tenant_name']   : $f['owner_name'];
            $mobile = $rented ? $f['tenant_mobile'] : $f['owner_mobile'];
            $rtype  = $rented ? 'tenant' : 'owner';

            if ($mobile) {
                $st = db()->prepare('SELECT id FROM users WHERE mobile = ? LIMIT 1');
                $st->execute([$mobile]);
                if ($st->fetch()) $mobile = null;
            }

            $username = make_username($f['flat_code']);
            $pw       = make_password();

            $st = db()->prepare(
                'INSERT INTO users (name, username, mobile, password_hash, role, status,
                                    flat_id, resident_type, temp_password, must_change_pwd, created_by)
                 VALUES (?,?,?,?, "resident", "active", ?,?,?, 1, ?)'
            );
            $st->execute([
                $name, $username, $mobile, password_hash($pw, PASSWORD_BCRYPT),
                $f['id'], $rtype, $pw, $admin['id'],
            ]);

            $made[] = [
                'flat_no'  => $f['flat_no'],
                'name'     => $name,
                'username' => $username,
                'password' => $pw,
            ];
        }

        log_activity($admin['id'], 'accounts_bulk_created', 'user', null, count($made) . ' accounts');
        ok(['created' => count($made), 'accounts' => $made]);
    }

    /* ---------------- reset password ---------------- */
    if ($action === 'reset') {
        $uid = (int) param('user_id', 0);
        if ($uid <= 0) fail('Missing user id.');

        $st = db()->prepare('SELECT id, name, username, role FROM users WHERE id = ? LIMIT 1');
        $st->execute([$uid]);
        $u = $st->fetch();
        if (!$u) fail('Account not found.', 404);
        if ($u['role'] === 'super_admin' && $admin['role'] !== 'super_admin') {
            fail('You cannot reset that account.', 403);
        }

        $pw = make_password();
        $st = db()->prepare(
            'UPDATE users SET password_hash = ?, temp_password = ?, must_change_pwd = 1,
                              failed_attempts = 0, locked_until = NULL
             WHERE id = ?'
        );
        $st->execute([password_hash($pw, PASSWORD_BCRYPT), $pw, $uid]);

        /* Force them out of any existing session */
        $st = db()->prepare('UPDATE sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL');
        $st->execute([$uid]);

        log_activity($admin['id'], 'account_reset', 'user', $uid, null);
        ok(['username' => $u['username'], 'name' => $u['name'], 'password' => $pw]);
    }

    /* ---------------- enable / disable ---------------- */
    if ($action === 'disable' || $action === 'enable') {
        $uid = (int) param('user_id', 0);
        if ($uid <= 0) fail('Missing user id.');
        if ($uid === (int) $admin['id']) fail('You cannot disable your own account.', 400);

        $new = $action === 'disable' ? 'suspended' : 'active';
        $st = db()->prepare('UPDATE users SET status = ? WHERE id = ? AND role <> "super_admin"');
        $st->execute([$new, $uid]);

        if ($action === 'disable') {
            $st = db()->prepare('UPDATE sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL');
            $st->execute([$uid]);
        }

        log_activity($admin['id'], 'account_' . $action, 'user', $uid, null);
        ok(['message' => $action === 'disable' ? 'Account disabled.' : 'Account enabled.']);
    }

    /* ---------------- create a gate login ---------------- */
    if ($action === 'create_guard') {
        $name = clean_txt(param('name'), 120);
        if ($name === null || str_len($name) < 2) fail('Please enter a name for the gate account.');

        $username = 'gate' . random_int(10, 99);
        $n = 0;
        while (true) {
            $st = db()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $st->execute([$username]);
            if (!$st->fetch()) break;
            $username = 'gate' . random_int(100, 999);
            if (++$n > 20) break;
        }

        $pw = make_password();
        $st = db()->prepare(
            'INSERT INTO users (name, username, password_hash, role, status, temp_password, must_change_pwd, created_by)
             VALUES (?,?,?, "guard", "active", ?, 1, ?)'
        );
        $st->execute([$name, $username, password_hash($pw, PASSWORD_BCRYPT), $pw, $admin['id']]);

        log_activity($admin['id'], 'guard_created', 'user', db()->lastInsertId(), $name);
        ok(['name' => $name, 'username' => $username, 'password' => $pw]);
    }

    fail('Unknown action.');
}

/* ============================================================
   GET - list
   ============================================================ */
$rows = db()->query(
    'SELECT f.id AS flat_id, f.flat_no, f.flat_code, b.code AS block_code,
            fd.owner_name, fd.tenant_name, fd.status AS flat_status,
            u.id AS user_id, u.name AS account_name, u.username, u.status AS account_status,
            u.resident_type, u.temp_password, u.last_login_at
     FROM flats f
     JOIN blocks b ON b.id = f.block_id
     LEFT JOIN flat_details fd ON fd.flat_id = f.id
     LEFT JOIN users u ON u.flat_id = f.id AND u.role = "resident"
     WHERE f.is_active = 1
     ORDER BY b.sort_order, b.code, f.floor, f.flat_no'
)->fetchAll();

$out = [];
$stats = ['with_details' => 0, 'with_account' => 0, 'never_logged_in' => 0, 'total' => 0];

foreach ($rows as $r) {
    $stats['total']++;
    if ($r['owner_name']) $stats['with_details']++;
    if ($r['user_id'])    $stats['with_account']++;
    if ($r['user_id'] && !$r['last_login_at']) $stats['never_logged_in']++;

    $out[] = [
        'flat_id'        => (int) $r['flat_id'],
        'flat_no'        => $r['flat_no'],
        'flat_code'      => $r['flat_code'],
        'block_code'     => $r['block_code'],
        'has_details'    => $r['owner_name'] !== null,
        'resident_name'  => $r['flat_status'] === 'rented' && $r['tenant_name']
                            ? $r['tenant_name'] : $r['owner_name'],
        'flat_status'    => $r['flat_status'],
        'user_id'        => $r['user_id'] !== null ? (int) $r['user_id'] : null,
        'account_name'   => $r['account_name'],
        'username'       => $r['username'],
        'account_status' => $r['account_status'],
        'resident_type'  => $r['resident_type'],
        'temp_password'  => $r['temp_password'],
        'has_logged_in'  => $r['last_login_at'] !== null,
    ];
}

/* Gate accounts */
$guards = db()->query(
    'SELECT id, name, username, status, temp_password, last_login_at
     FROM users WHERE role = "guard" ORDER BY id'
)->fetchAll();

foreach ($guards as &$g) {
    $g['id'] = (int) $g['id'];
    $g['has_logged_in'] = $g['last_login_at'] !== null;
    unset($g['last_login_at']);
}
unset($g);

ok([
    'stats'  => $stats,
    'flats'  => $out,
    'guards' => $guards,
]);
