<?php
/**
 * POST /api/change_password.php
 * Body: { "current_password": "...", "new_password": "...", "confirm_password": "..." }
 * Requires a valid session. Revokes all other sessions on success.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

api_init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed', 405);
}

$u = require_auth();

$current = param('current_password');
$new     = param('new_password');
$confirm = param('confirm_password');

if (!$current || !$new || !$confirm) {
    fail('All password fields are required.');
}

if ($new !== $confirm) {
    fail('New password and confirmation do not match.');
}

if (strlen($new) < 8) {
    fail('New password must be at least 8 characters.');
}

if (!preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
    fail('New password must contain both letters and numbers.');
}

if ($new === $current) {
    fail('New password must be different from the current password.');
}

/* Verify current password */
$st = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
$st->execute([$u['id']]);
$hash = $st->fetchColumn();

if (!$hash || !password_verify($current, $hash)) {
    log_activity($u['id'], 'password_change_failed', 'user', $u['id'], 'Wrong current password');
    fail('Current password is incorrect.', 401);
}

/* Update */
$new_hash = password_hash($new, PASSWORD_BCRYPT);
$st = db()->prepare(
    'UPDATE users SET password_hash = ?, must_change_pwd = 0 WHERE id = ?'
);
$st->execute([$new_hash, $u['id']]);

/* Revoke every other session — force re-login elsewhere */
$st = db()->prepare(
    'UPDATE sessions SET revoked_at = NOW() WHERE user_id = ? AND id <> ? AND revoked_at IS NULL'
);
$st->execute([$u['id'], $u['session_id']]);

log_activity($u['id'], 'password_changed', 'user', $u['id'], 'Password updated');

ok(['message' => 'Password updated successfully.']);
