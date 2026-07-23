<?php
/**
 * POST /api/login.php
 * Body: { "identifier": "admin", "password": "....", "remember": true }
 * identifier = email OR username
 *
 * Success: { ok, token, expires_at, user:{...} }
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

api_init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed', 405);
}

$identifier = param('identifier');
$password   = param('password');
$remember   = (bool) param('remember', false);

if ($identifier === null || $identifier === '' || $password === null || $password === '') {
    fail('Please enter both your login ID and password.');
}

if (strlen($identifier) > 150 || strlen($password) > 200) {
    fail('Invalid input.');
}

$ip = client_ip();

/* -----------------------------------------------------------
   1. IP-level throttle: too many failures from this IP
   ----------------------------------------------------------- */
$st = db()->prepare(
    'SELECT COUNT(*) FROM login_attempts
     WHERE ip_address = ? AND success = 0
       AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)'
);
$st->execute([$ip, LOCKOUT_MINUTES]);
$ip_fails = (int) $st->fetchColumn();

if ($ip_fails >= (MAX_ATTEMPTS * 4)) {
    record_attempt($identifier, $ip, 0);
    fail('Too many failed attempts from this device. Please try again after ' . LOCKOUT_MINUTES . ' minutes.', 429);
}

/* -----------------------------------------------------------
   2. Look up the user by email, username or mobile.

   The flat columns only exist once sql/06_resident_app.sql has
   been imported. Fall back to a plain query so the committee can
   still sign in on a database that has not been migrated yet.
   ----------------------------------------------------------- */
$has_flat_cols = false;
try {
    $c = db()->query("SHOW COLUMNS FROM users LIKE 'flat_id'")->fetch();
    $has_flat_cols = (bool) $c;
} catch (Exception $e) {
    $has_flat_cols = false;
}

if ($has_flat_cols) {
    $st = db()->prepare(
        'SELECT u.id, u.name, u.email, u.username, u.mobile, u.password_hash, u.role, u.designation,
                u.status, u.photo_url, u.failed_attempts, u.locked_until, u.must_change_pwd,
                u.flat_id, u.resident_type,
                f.flat_no, f.flat_code, f.floor_label, b.name AS block_name
         FROM users u
         LEFT JOIN flats f  ON f.id = u.flat_id
         LEFT JOIN blocks b ON b.id = f.block_id
         WHERE (u.email = ? OR u.username = ? OR u.mobile = ?)
         LIMIT 1'
    );
} else {
    $st = db()->prepare(
        'SELECT u.id, u.name, u.email, u.username, u.mobile, u.password_hash, u.role, u.designation,
                u.status, u.photo_url, u.failed_attempts, u.locked_until, u.must_change_pwd,
                NULL AS flat_id, NULL AS resident_type,
                NULL AS flat_no, NULL AS flat_code, NULL AS floor_label, NULL AS block_name
         FROM users u
         WHERE (u.email = ? OR u.username = ? OR u.mobile = ?)
         LIMIT 1'
    );
}
$st->execute([$identifier, $identifier, $identifier]);
$user = $st->fetch();

/* Uniform failure message so we never reveal which accounts exist. */
$BAD = 'Invalid login ID or password.';

if (!$user) {
    record_attempt($identifier, $ip, 0);
    fail($BAD, 401);
}

/* -----------------------------------------------------------
   3. Account lock check
   ----------------------------------------------------------- */
if ($user['locked_until'] !== null && strtotime($user['locked_until']) > time()) {
    $mins = max(1, (int) ceil((strtotime($user['locked_until']) - time()) / 60));
    record_attempt($identifier, $ip, 0);
    fail('Account temporarily locked. Try again in ' . $mins . ' minute(s).', 423);
}

/* -----------------------------------------------------------
   4. Password check
   ----------------------------------------------------------- */
if (empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
    $fails = (int) $user['failed_attempts'] + 1;

    if ($fails >= MAX_ATTEMPTS) {
        $up = db()->prepare(
            'UPDATE users SET failed_attempts = ?, locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?'
        );
        $up->execute([$fails, LOCKOUT_MINUTES, $user['id']]);
        record_attempt($identifier, $ip, 0);
        log_activity($user['id'], 'login_locked', 'user', $user['id'], 'Locked after ' . $fails . ' failures');
        fail('Too many failed attempts. Account locked for ' . LOCKOUT_MINUTES . ' minutes.', 423);
    }

    $up = db()->prepare('UPDATE users SET failed_attempts = ? WHERE id = ?');
    $up->execute([$fails, $user['id']]);
    record_attempt($identifier, $ip, 0);

    $left = MAX_ATTEMPTS - $fails;
    fail($BAD, 401, ['attempts_left' => $left]);
}

/* -----------------------------------------------------------
   5. Status check
   ----------------------------------------------------------- */
if ($user['status'] === 'pending') {
    record_attempt($identifier, $ip, 0);
    fail('Your account is awaiting committee approval.', 403);
}
if ($user['status'] === 'suspended') {
    record_attempt($identifier, $ip, 0);
    fail('Your account has been suspended. Please contact the committee.', 403);
}

/* Gate staff no longer sign in - the gate page is reached by QR code. */
if ($user['role'] === 'guard') {
    record_attempt($identifier, $ip, 0);
    fail('The gate screen is now opened by scanning the QR code at the gate. No login is needed.', 403);
}

/* -----------------------------------------------------------
   6. Everyone with an active account may log in.
      The client decides which app to show based on role.
   ----------------------------------------------------------- */

/* -----------------------------------------------------------
   7. Issue session token
   ----------------------------------------------------------- */
$raw_token  = bin2hex(random_bytes(32));
$token_hash = hash('sha256', $raw_token);
$days       = $remember ? (int) SESSION_DAYS : 1;

$st = db()->prepare(
    'INSERT INTO sessions (user_id, token_hash, device_info, ip_address, expires_at)
     VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))'
);
$st->execute([$user['id'], $token_hash, user_agent(), $ip, $days]);

/* Reset counters, stamp last login */
$st = db()->prepare(
    'UPDATE users
     SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW(), last_login_ip = ?
     WHERE id = ?'
);
$st->execute([$ip, $user['id']]);

/* Housekeeping: clear expired sessions occasionally */
if (random_int(1, 20) === 1) {
    db()->exec('DELETE FROM sessions WHERE expires_at < NOW() OR revoked_at IS NOT NULL');
    db()->exec('DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
}

/* Clear the temporary password once they have logged in */
/* Clear the temporary password once they have logged in.
   Column only exists after sql/06_resident_app.sql. */
try {
    $st = db()->prepare('UPDATE users SET temp_password = NULL WHERE id = ? AND temp_password IS NOT NULL');
    $st->execute([$user['id']]);
} catch (Exception $e) {
    // not migrated yet - nothing to clear
}

record_attempt($identifier, $ip, 1);
log_activity($user['id'], 'login', 'user', $user['id'], $user['role'] . ' login');

$is_admin = in_array($user['role'], ['super_admin', 'admin'], true);

ok([
    'token'      => $raw_token,
    'expires_at' => date('c', strtotime('+' . $days . ' days')),
    'home'       => $is_admin ? 'dashboard.html' : 'resident.html',
    'user'       => [
        'id'              => (int) $user['id'],
        'name'            => $user['name'],
        'email'           => $user['email'],
        'username'        => $user['username'],
        'mobile'          => $user['mobile'],
        'role'            => $user['role'],
        'designation'     => $user['designation'],
        'photo_url'       => $user['photo_url'],
        'must_change_pwd' => (int) $user['must_change_pwd'] === 1,
        'flat_id'         => $user['flat_id'] !== null ? (int) $user['flat_id'] : null,
        'flat_no'         => $user['flat_no'],
        'flat_code'       => $user['flat_code'],
        'floor_label'     => $user['floor_label'],
        'block_name'      => $user['block_name'],
        'resident_type'   => $user['resident_type'],
    ],
]);


/* ---------------------------------------------------------- */
function record_attempt($identifier, $ip, $success) {
    try {
        $st = db()->prepare(
            'INSERT INTO login_attempts (identifier, ip_address, success, user_agent)
             VALUES (?, ?, ?, ?)'
        );
        $st->execute([substr($identifier, 0, 150), $ip, $success, user_agent()]);
    } catch (Exception $e) {
        // never block login on logging failure
    }
}
