<?php
/**
 * /api/complaints.php
 *
 * GET  ?scope=mine            resident: my flat's tickets
 *      ?scope=all&status=open admin: the queue
 *      ?id=123                either: one ticket with its replies
 *
 * POST { action:"create", kind, category, subject, body, is_anonymous }
 *      { action:"reply", id, body }
 *      { action:"status", id, status, priority }   admin only
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

api_init();

$me = require_auth();
require_resident_app();

$CATS = ['water','electricity','lift','security','housekeeping','parking',
         'common_area','noise','maintenance','other'];

$CAT_LABELS = [
    'water'        => 'Water',
    'electricity'  => 'Electricity',
    'lift'         => 'Lift',
    'security'     => 'Security',
    'housekeeping' => 'Housekeeping',
    'parking'      => 'Parking',
    'common_area'  => 'Common area',
    'noise'        => 'Noise',
    'maintenance'  => 'Maintenance',
    'other'        => 'Other',
];

$STATUS_LABELS = [
    'open'        => 'Open',
    'in_progress' => 'In progress',
    'resolved'    => 'Resolved',
    'closed'      => 'Closed',
];

/* ============================================================
   POST
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = param('action');

    /* -------- raise a ticket -------- */
    if ($action === 'create') {
        $u = require_resident();

        $kind = param('kind') === 'suggestion' ? 'suggestion' : 'complaint';

        $cat = param('category');
        if (!in_array($cat, $CATS, true)) $cat = 'other';

        $subject = clean_txt(param('subject'), 150);
        if ($subject === null || str_len($subject) < 4) {
            fail('Please write a short subject line.');
        }

        $bodyTxt = clean_txt(param('body'), 4000);
        if ($bodyTxt === null || str_len($bodyTxt) < 10) {
            fail('Please describe the issue in a little more detail.');
        }

        $anon = param('is_anonymous');
        $anon = ($anon === '1' || $anon === 1 || $anon === true) ? 1 : 0;

        /* Simple flood guard */
        $st = db()->prepare(
            'SELECT COUNT(*) FROM complaints
             WHERE raised_by = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );
        $st->execute([$u['id']]);
        if ((int) $st->fetchColumn() >= 5) {
            fail('You have raised several tickets in the last hour. Please wait before adding more.', 429);
        }

        $st = db()->prepare(
            'INSERT INTO complaints (flat_id, raised_by, kind, category, subject, body, is_anonymous)
             VALUES (?,?,?,?,?,?,?)'
        );
        $st->execute([$u['flat_id'], $u['id'], $kind, $cat, $subject, $bodyTxt, $anon]);

        $cid = (int) db()->lastInsertId();
        log_activity($u['id'], $kind . '_raised', 'complaint', $cid, $subject);

        ok([
            'id'      => $cid,
            'message' => $kind === 'suggestion'
                ? 'Thank you. Your suggestion has been sent to the committee.'
                : 'Your complaint has been logged. The committee will look into it.',
        ]);
    }

    /* -------- add a reply -------- */
    if ($action === 'reply') {
        $id = (int) param('id', 0);
        $txt = clean_txt(param('body'), 2000);
        if ($id <= 0) fail('Missing ticket id.');
        if ($txt === null || str_len($txt) < 2) fail('Please write a message.');

        $st = db()->prepare('SELECT * FROM complaints WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) fail('Ticket not found.', 404);

        $admin = is_admin($me);
        if (!$admin) {
            if ($me['role'] !== 'resident' || (int) $me['flat_id'] !== (int) $c['flat_id']) {
                fail('This ticket does not belong to your flat.', 403);
            }
            if (in_array($c['status'], ['closed'], true)) {
                fail('This ticket is closed.', 409);
            }
        }

        $st = db()->prepare(
            'INSERT INTO complaint_replies (complaint_id, user_id, body, is_committee)
             VALUES (?,?,?,?)'
        );
        $st->execute([$id, $me['id'], $txt, $admin ? 1 : 0]);

        /* A committee reply on an open ticket moves it along */
        if ($admin && $c['status'] === 'open') {
            db()->prepare('UPDATE complaints SET status = "in_progress" WHERE id = ?')->execute([$id]);
        }
        db()->prepare('UPDATE complaints SET updated_at = NOW() WHERE id = ?')->execute([$id]);

        ok(['message' => 'Reply added.']);
    }

    /* -------- committee updates status -------- */
    if ($action === 'status') {
        require_admin();
        $id = (int) param('id', 0);
        if ($id <= 0) fail('Missing ticket id.');

        $status   = param('status');
        $priority = param('priority');

        $sets = [];
        $args = [];

        if (in_array($status, ['open','in_progress','resolved','closed'], true)) {
            $sets[] = 'status = ?';
            $args[] = $status;
            if (in_array($status, ['resolved','closed'], true)) {
                $sets[] = 'resolved_by = ?';
                $args[] = $me['id'];
                $sets[] = 'resolved_at = NOW()';
            }
        }
        if (in_array($priority, ['low','normal','high'], true)) {
            $sets[] = 'priority = ?';
            $args[] = $priority;
        }
        if (!$sets) fail('Nothing to update.');

        $args[] = $id;
        $st = db()->prepare('UPDATE complaints SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $st->execute($args);

        log_activity($me['id'], 'complaint_updated', 'complaint', $id, $status ?: $priority);
        ok(['message' => 'Ticket updated.']);
    }

    fail('Unknown action.');
}

/* ============================================================
   GET - single ticket with thread
   ============================================================ */
$one = (int) param('id', 0);

if ($one > 0) {
    $st = db()->prepare(
        'SELECT c.*, f.flat_no, f.flat_code, u.name AS raiser_name,
                r.name AS resolver_name
         FROM complaints c
         LEFT JOIN flats f ON f.id = c.flat_id
         LEFT JOIN users u ON u.id = c.raised_by
         LEFT JOIN users r ON r.id = c.resolved_by
         WHERE c.id = ? LIMIT 1'
    );
    $st->execute([$one]);
    $c = $st->fetch();
    if (!$c) fail('Ticket not found.', 404);

    if (!is_admin($me)) {
        if ($me['role'] !== 'resident' || (int) $me['flat_id'] !== (int) $c['flat_id']) {
            fail('This ticket does not belong to your flat.', 403);
        }
    }

    $st = db()->prepare(
        'SELECT r.id, r.body, r.is_committee, r.created_at, u.name AS author
         FROM complaint_replies r
         LEFT JOIN users u ON u.id = r.user_id
         WHERE r.complaint_id = ? ORDER BY r.id'
    );
    $st->execute([$one]);
    $replies = [];
    foreach ($st->fetchAll() as $r) {
        $replies[] = [
            'id'           => (int) $r['id'],
            'body'         => $r['body'],
            'is_committee' => (int) $r['is_committee'] === 1,
            'author'       => $r['author'],
            'created_at'   => $r['created_at'],
        ];
    }

    $hide = (int) $c['is_anonymous'] === 1 && !is_admin($me);

    ok([
        'ticket' => [
            'id'             => (int) $c['id'],
            'kind'           => $c['kind'],
            'category'       => $c['category'],
            'category_label' => $CAT_LABELS[$c['category']] ?? 'Other',
            'subject'        => $c['subject'],
            'body'           => $c['body'],
            'status'         => $c['status'],
            'status_label'   => $STATUS_LABELS[$c['status']] ?? $c['status'],
            'priority'       => $c['priority'],
            'is_anonymous'   => (int) $c['is_anonymous'] === 1,
            'flat_no'        => $c['flat_no'],
            'raiser_name'    => $hide ? null : $c['raiser_name'],
            'resolver_name'  => $c['resolver_name'],
            'resolved_at'    => $c['resolved_at'],
            'created_at'     => $c['created_at'],
        ],
        'replies' => $replies,
    ]);
}

/* ============================================================
   GET - list
   ============================================================ */
$scope  = param('scope', 'mine');
$status = param('status');

$base = 'SELECT c.id, c.kind, c.category, c.subject, c.status, c.priority,
                c.is_anonymous, c.created_at, c.updated_at,
                f.flat_no, u.name AS raiser_name,
                (SELECT COUNT(*) FROM complaint_replies cr WHERE cr.complaint_id = c.id) AS replies
         FROM complaints c
         LEFT JOIN flats f ON f.id = c.flat_id
         LEFT JOIN users u ON u.id = c.raised_by';

$args  = [];
$where = [];

if ($scope === 'mine') {
    $u = require_resident();
    $where[] = 'c.flat_id = ?';
    $args[]  = $u['flat_id'];
} else {
    require_admin();
}

if (in_array($status, ['open','in_progress','resolved','closed'], true)) {
    $where[] = 'c.status = ?';
    $args[]  = $status;
}

$sql = $base . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY FIELD(c.priority,"high","normal","low"), c.id DESC LIMIT 200';

$st = db()->prepare($sql);
$st->execute($args);

$out = [];
foreach ($st->fetchAll() as $r) {
    $anon = (int) $r['is_anonymous'] === 1;
    $out[] = [
        'id'             => (int) $r['id'],
        'kind'           => $r['kind'],
        'category'       => $r['category'],
        'category_label' => $CAT_LABELS[$r['category']] ?? 'Other',
        'subject'        => $r['subject'],
        'status'         => $r['status'],
        'status_label'   => $STATUS_LABELS[$r['status']] ?? $r['status'],
        'priority'       => $r['priority'],
        'flat_no'        => $anon && !is_admin($me) ? null : $r['flat_no'],
        'raiser_name'    => $anon && !is_admin($me) ? null : $r['raiser_name'],
        'is_anonymous'   => $anon,
        'replies'        => (int) $r['replies'],
        'created_at'     => $r['created_at'],
        'updated_at'     => $r['updated_at'],
    ];
}

/* Counters */
$counts = ['open' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0];
if ($scope === 'mine') {
    $st = db()->prepare('SELECT status, COUNT(*) n FROM complaints WHERE flat_id = ? GROUP BY status');
    $st->execute([$me['flat_id']]);
} else {
    $st = db()->query('SELECT status, COUNT(*) n FROM complaints GROUP BY status');
}
foreach ($st->fetchAll() as $c) {
    $counts[$c['status']] = (int) $c['n'];
}

ok([
    'scope'      => $scope,
    'counts'     => $counts,
    'categories' => $CAT_LABELS,
    'tickets'    => $out,
]);
