<?php
/**
 * GET /api/me.php
 * Validates the bearer token and returns the current user.
 * Used on page load to decide whether to show the dashboard or the login form.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

api_init();

$u = current_user();
if (!$u) {
    fail('Not logged in', 401);
}

ok([
    'user' => [
        'id'              => (int) $u['id'],
        'name'            => $u['name'],
        'email'           => $u['email'],
        'username'        => $u['username'],
        'mobile'          => $u['mobile'],
        'role'            => $u['role'],
        'designation'     => $u['designation'],
        'photo_url'       => $u['photo_url'],
        'must_change_pwd' => (int) $u['must_change_pwd'] === 1,
    ],
]);
