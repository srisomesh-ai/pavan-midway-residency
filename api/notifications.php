<?php
/**
 * GET  /api/notifications.php            unread count + recent list
 * GET  /api/notifications.php?all=1      full history
 * POST /api/notifications.php            { action: "read", id }  or  { action: "read_all" }
 *
 * Any signed-in user. Everyone only ever sees their own notifications.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

api_init();

$me = require_auth();

/* If the table is missing the app should still work, just with nothing to show. */
if (!notify_ready()) {
    ok([
        'ready'  => false,
        'unread' => 0,
        'items'  => [],
        'note'   => 'Notifications are not set up yet. Import sql/07_notifications.sql.',
    ]);
}

/* ============================================================
   POST - mark read
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = param('action');

    if ($action === 'read') {
        $id = (int) param('id', 0);
        if ($id <= 0) fail('Missing notification id.');
        $st = db()->prepare(
            'UPDATE notifications SET read_at = NOW()
             WHERE id = ? AND user_id = ? AND read_at IS NULL'
        );
        $st->execute([$id, $me['id']]);
        ok(['message' => 'Marked as read.']);
    }

    if ($action === 'read_all') {
        $st = db()->prepare(
            'UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL'
        );
        $st->execute([$me['id']]);
        ok(['message' => 'All marked as read.']);
    }

    fail('Unknown action.');
}

/* ============================================================
   GET - list
   ============================================================ */
$all   = param('all') === '1';
$limit = $all ? 100 : 20;

$st = db()->prepare(
    'SELECT n.id, n.kind, n.title, n.body, n.link, n.entity, n.entity_id,
            n.is_urgent, n.read_at, n.created_at, u.name AS from_name
     FROM notifications n
     LEFT JOIN users u ON u.id = n.created_by
     WHERE n.user_id = ?
     ORDER BY n.id DESC
     LIMIT ' . (int) $limit
);
$st->execute([$me['id']]);

$items = [];
foreach ($st->fetchAll() as $r) {
    $items[] = [
        'id'         => (int) $r['id'],
        'kind'       => $r['kind'],
        'title'      => $r['title'],
        'body'       => $r['body'],
        'link'       => $r['link'],
        'entity'     => $r['entity'],
        'entity_id'  => $r['entity_id'] !== null ? (int) $r['entity_id'] : null,
        'is_urgent'  => (int) $r['is_urgent'] === 1,
        'is_read'    => $r['read_at'] !== null,
        'from_name'  => $r['from_name'],
        'created_at' => $r['created_at'],
    ];
}

$st = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
$st->execute([$me['id']]);
$unread = (int) $st->fetchColumn();

/* Occasionally trim old read notifications so the table stays small */
if (random_int(1, 50) === 1) {
    try {
        db()->exec(
            'DELETE FROM notifications
             WHERE read_at IS NOT NULL AND created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)'
        );
    } catch (Exception $e) {
        // housekeeping only
    }
}

ok([
    'ready'  => true,
    'unread' => $unread,
    'items'  => $items,
]);
