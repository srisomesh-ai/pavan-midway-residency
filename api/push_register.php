<?php
/**
 * POST /api/push_register.php   { token, platform }   save this device
 * POST /api/push_register.php   { action:"remove", token }   forget it
 * GET  /api/push_register.php                        is push available?
 *
 * Any signed-in user. Tokens are per device, so one person can have the
 * app on a phone and a tablet and get notifications on both.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

api_init();

$me = require_auth();

/* Table arrives with sql/07_notifications.sql */
$have_table = false;
try {
    $have_table = (bool) db()->query("SHOW TABLES LIKE 'push_tokens'")->fetch();
} catch (Exception $e) {
    $have_table = false;
}

/* ---------- GET: can this device expect push? ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $mine = 0;
    if ($have_table) {
        try {
            $st = db()->prepare('SELECT COUNT(*) FROM push_tokens WHERE user_id = ?');
            $st->execute([$me['id']]);
            $mine = (int) $st->fetchColumn();
        } catch (Exception $e) {}
    }
    ok([
        'push_available' => fcm_ready() && $have_table,
        'devices'        => $mine,
        'note'           => fcm_ready()
            ? ($have_table ? null : 'Import sql/07_notifications.sql to store device tokens.')
            : (function_exists('fcm_missing_reason') ? fcm_missing_reason() : 'Firebase is not set up yet.'),
    ]);
}

if (!$have_table) {
    fail('Push is not set up on this server yet. Import sql/07_notifications.sql.', 503);
}

$action = param('action');

/* ---------- remove ---------- */
if ($action === 'remove') {
    $tok = param('token');
    if ($tok) {
        try {
            db()->prepare('DELETE FROM push_tokens WHERE token = ? AND user_id = ?')
                ->execute([$tok, $me['id']]);
        } catch (Exception $e) {}
    }
    ok(['message' => 'Device removed.']);
}

/* ---------- register ---------- */
$tok = param('token');
if (!$tok || strlen($tok) < 20) {
    fail('Missing device token.');
}
if (strlen($tok) > 255) {
    fail('Device token is too long.');
}

$platform = param('platform');
if (!in_array($platform, ['web', 'android', 'ios'], true)) {
    $platform = 'web';
}

try {
    /* A token identifies one device. If it moves to a different account,
       reassign it rather than leaving notifications going to the wrong person. */
    $st = db()->prepare(
        'INSERT INTO push_tokens (user_id, token, platform)
         VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id),
                                 platform = VALUES(platform),
                                 last_seen = NOW()'
    );
    $st->execute([$me['id'], $tok, $platform]);

    /* Keep it tidy - drop devices not seen for six months */
    if (random_int(1, 40) === 1) {
        db()->exec('DELETE FROM push_tokens WHERE last_seen < DATE_SUB(NOW(), INTERVAL 180 DAY)');
    }
} catch (Exception $e) {
    fail(DEBUG ? $e->getMessage() : 'Could not register this device.', 500);
}

ok([
    'message'        => 'Device registered for notifications.',
    'push_available' => fcm_ready(),
]);
