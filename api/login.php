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
   2. Look up the user by email or username
   ----------------------------------------------------------- */
$st = db()->prepare(
    'SELECT id, name, email, username, mobile, password_hash, role, designation,
            status, photo_url, failed_attempts, locked_until, must_change_pwd
     FROM users
     WHERE (email = ? OR username = ?)
     LIMIT 1'
);
$st->execute([$identifier, $identifier]);
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

/* -----------------------------------------------------------
   6. This login page is admin-only
   ----------------------------------------------------------- */
if (!in_array($user['role'], ['super_admin', 'admin'], true)) {
    record_attempt($identifier, $ip, 0);
    fail('This login is for committee members only.', 403);
}

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

record_attempt($identifier, $ip, 1);
log_activity($user['id'], 'login', 'user', $user['id'], 'Admin login');

ok([
    'token'      => $raw_token,
    'expires_at' => date('c', strtotime('+' . $days . ' days')),
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
