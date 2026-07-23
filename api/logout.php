<?php
/**
 * POST /api/logout.php
 * Revokes the current session token.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

api_init();

$u = current_user();

if ($u) {
    $st = db()->prepare('UPDATE sessions SET revoked_at = NOW() WHERE id = ?');
    $st->execute([$u['session_id']]);
    log_activity($u['id'], 'logout', 'user', $u['id'], null);
}

/* Always report success so a stale token still clears the client. */
ok(['message' => 'Logged out']);
