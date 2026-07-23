<?php
/**
 * GET  /api/notices.php          list notices (any signed-in user)
 * POST /api/notices.php          committee only
 *        { action:"create", title, body, category, audience, is_pinned }
 *        { action:"delete", id }
 *        { action:"pin", id, pinned }
 *
 * Creating a notice sends it to every resident in the chosen audience.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

api_init();

$me = require_auth();

if (!notify_ready()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        fail('Notices are not set up on this server yet. Import sql/07_notifications.sql in phpMyAdmin, then reload this page.', 503);
    }
    ok(['ready' => false, 'notices' => []]);
}

$CATS = [
    'general'     => 'General',
    'urgent'      => 'Urgent',
    'water'       => 'Water',
    'electricity' => 'Electricity',
    'maintenance' => 'Maintenance',
    'event'       => 'Event',
    'meeting'     => 'Meeting',
    'security'    => 'Security',
];

$AUD = [
    'all'     => 'Everyone',
    'block_a' => 'Block A only',
    'block_b' => 'Block B only',
    'owners'  => 'Owners only',
    'tenants' => 'Tenants only',
];

/* ============================================================
   POST
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $admin  = require_admin();
    $action = param('action');

    /* -------- create and send -------- */
    if ($action === 'create') {
        $title = clean_text(param('title'), 150);
        $body  = clean_text(param('body'), 4000);

        if ($title === null || str_len($title) < 3) {
            fail('Please write a title for the notice.');
        }
        if ($body === null || str_len($body) < 3) {
            fail('Please write the notice message.');
        }

        $category = param('category');
        if (!isset($CATS[$category])) $category = 'general';

        $audience = param('audience');
        if (!isset($AUD[$audience])) $audience = 'all';

        $pinned = param('is_pinned') ? 1 : 0;
        $urgent = $category === 'urgent' ? 1 : 0;

        $st = db()->prepare(
            'INSERT INTO notices (title, body, category, is_pinned, audience, posted_by)
             VALUES (?,?,?,?,?,?)'
        );
        $st->execute([$title, $body, $category, $pinned, $audience, $admin['id']]);
        $nid = (int) db()->lastInsertId();

        /* Fan out to residents */
        $sent = notify_all_residents(
            $audience,
            'notice',
            $title,
            str_cut($body, 300),
            [
                'link'      => 'notices.html',
                'entity'    => 'notice',
                'entity_id' => $nid,
                'urgent'    => $urgent,
                'by'        => $admin['id'],
            ]
        );

        db()->prepare('UPDATE notices SET sent_count = ? WHERE id = ?')->execute([$sent, $nid]);

        log_activity($admin['id'], 'notice_posted', 'notice', $nid, $title);

        ok([
            'id'      => $nid,
            'sent'    => $sent,
            'message' => $sent
                ? ('Notice sent to ' . $sent . ' resident' . ($sent === 1 ? '' : 's') . '.')
                : 'Notice saved. No resident logins exist yet, so nobody was notified.',
        ]);
    }

    /* -------- pin / unpin -------- */
    if ($action === 'pin') {
        $id = (int) param('id', 0);
        if ($id <= 0) fail('Missing notice id.');
        $pin = param('pinned') ? 1 : 0;
        db()->prepare('UPDATE notices SET is_pinned = ? WHERE id = ?')->execute([$pin, $id]);
        ok(['message' => $pin ? 'Pinned.' : 'Unpinned.']);
    }

    /* -------- delete -------- */
    if ($action === 'delete') {
        $id = (int) param('id', 0);
        if ($id <= 0) fail('Missing notice id.');
        db()->prepare('UPDATE notices SET is_active = 0 WHERE id = ?')->execute([$id]);
        log_activity($admin['id'], 'notice_deleted', 'notice', $id, null);
        ok(['message' => 'Notice removed.']);
    }

    fail('Unknown action.');
}

/* ============================================================
   GET - list
   ============================================================ */
$st = db()->query(
    'SELECT n.id, n.title, n.body, n.category, n.is_pinned, n.audience,
            n.sent_count, n.created_at, u.name AS posted_by_name
     FROM notices n
     LEFT JOIN users u ON u.id = n.posted_by
     WHERE n.is_active = 1
     ORDER BY n.is_pinned DESC, n.id DESC
     LIMIT 100'
);

$out = [];
foreach ($st->fetchAll() as $r) {
    $out[] = [
        'id'             => (int) $r['id'],
        'title'          => $r['title'],
        'body'           => $r['body'],
        'category'       => $r['category'],
        'category_label' => $CATS[$r['category']] ?? 'General',
        'is_pinned'      => (int) $r['is_pinned'] === 1,
        'audience'       => $r['audience'],
        'audience_label' => $AUD[$r['audience']] ?? 'Everyone',
        'sent_count'     => (int) $r['sent_count'],
        'posted_by_name' => $r['posted_by_name'],
        'created_at'     => $r['created_at'],
    ];
}

ok([
    'ready'      => true,
    'is_admin'   => is_admin($me),
    'categories' => $CATS,
    'audiences'  => $AUD,
    'notices'    => $out,
]);


/* ---------------------------------------------------------- */
function clean_text($v, $len) {
    if ($v === null) return null;
    $v = trim((string) $v);
    if ($v === '') return null;
    return str_cut($v, $len);
}
