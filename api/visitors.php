<?php
/**
 * /api/visitors.php
 *
 * GET  ?scope=mine        resident: visitors for my flat
 *      ?scope=pending     guard/admin: awaiting resident decision
 *      ?scope=today       guard/admin: today's gate log
 *      ?scope=inside      guard/admin: currently inside
 *
 * POST { action: "create", ... }   guard logs a visitor at the gate
 *      { action: "decide", id, decision: "approved"|"denied", reason }
 *      { action: "entry",  id }    guard marks entry
 *      { action: "exit",   id }    guard marks exit
 *      { action: "preapprove", ... }  resident allows someone in advance
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

api_init();

$me = require_auth();

function gate_pass() {
    return strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 6));
}

/** Auto-expire stale pending requests before any read or write. */
function expire_old() {
    $mins = (int) setting('visitor_auto_expire_minutes', 30);
    if ($mins <= 0) return;
    try {
        $st = db()->prepare(
            'UPDATE visitors SET status = "expired"
             WHERE status = "pending" AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        );
        $st->execute([$mins]);
    } catch (Exception $e) {}
}

expire_old();

$PURPOSES = ['guest','delivery','cab','service','staff','other'];

/* ============================================================
   POST
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = param('action');

    /* -------- guard logs a visitor -------- */
    if ($action === 'create') {
        require_guard();

        $flat_id = (int) param('flat_id', 0);
        if ($flat_id <= 0) fail('Please select the flat being visited.');

        $st = db()->prepare('SELECT id, flat_no, flat_code FROM flats WHERE id = ? AND is_active = 1 LIMIT 1');
        $st->execute([$flat_id]);
        $flat = $st->fetch();
        if (!$flat) fail('Flat not found.', 404);

        $name = clean_txt(param('visitor_name'), 120);
        if ($name === null || str_len($name) < 2) fail('Please enter the visitor name.');

        $mobile  = clean_mobile(param('visitor_mobile'));
        $count   = max(1, min(20, (int) param('visitor_count', 1)));
        $purpose = param('purpose');
        if (!in_array($purpose, $PURPOSES, true)) $purpose = 'guest';
        $note    = clean_txt(param('purpose_note'), 150);
        $vehicle = clean_txt(param('vehicle_no'), 20);
        if ($vehicle !== null) $vehicle = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vehicle));

        /* Does a valid pre-approval cover this visitor? */
        $auto = false;
        $pass = param('gate_pass');
        if ($pass) {
            $st = db()->prepare(
                'SELECT id FROM preapproved_visitors
                 WHERE gate_pass = ? AND flat_id = ? AND is_active = 1
                   AND valid_from <= CURDATE() AND valid_to >= CURDATE()
                 LIMIT 1'
            );
            $st->execute([strtoupper($pass), $flat_id]);
            if ($st->fetch()) $auto = true;
        }

        $status = $auto ? 'approved' : 'pending';

        $st = db()->prepare(
            'INSERT INTO visitors
             (flat_id, visitor_name, visitor_mobile, visitor_count, purpose, purpose_note,
              vehicle_no, status, gate_pass, decided_at, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,' . ($auto ? 'NOW()' : 'NULL') . ',?)'
        );
        $st->execute([
            $flat_id, $name, $mobile, $count, $purpose, $note,
            $vehicle, $status, gate_pass(), $me['id'],
        ]);

        $vid = (int) db()->lastInsertId();
        log_activity($me['id'], 'visitor_logged', 'visitor', $vid, $flat['flat_code'] . ' - ' . $name);

        ok([
            'id'      => $vid,
            'status'  => $status,
            'auto'    => $auto,
            'flat_no' => $flat['flat_no'],
            'message' => $auto
                ? 'Pre-approved. Allow the visitor in.'
                : 'Sent to the resident for approval.',
        ]);
    }

    /* -------- resident approves or denies -------- */
    if ($action === 'decide') {
        $id       = (int) param('id', 0);
        $decision = param('decision');
        if ($id <= 0) fail('Missing visitor id.');
        if (!in_array($decision, ['approved', 'denied'], true)) {
            fail('Decision must be approved or denied.');
        }

        $st = db()->prepare('SELECT * FROM visitors WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $v = $st->fetch();
        if (!$v) fail('Visitor entry not found.', 404);

        /* Residents may only decide for their own flat; admins may override */
        if (!is_admin($me)) {
            if ($me['role'] !== 'resident' || (int) $me['flat_id'] !== (int) $v['flat_id']) {
                fail('This visitor is not for your flat.', 403);
            }
        }

        if ($v['status'] !== 'pending') {
            fail('This request is already ' . $v['status'] . '.', 409);
        }

        $reason = $decision === 'denied' ? clean_txt(param('reason'), 150) : null;

        $st = db()->prepare(
            'UPDATE visitors SET status = ?, decided_by = ?, decided_at = NOW(), deny_reason = ?
             WHERE id = ? AND status = "pending"'
        );
        $st->execute([$decision, $me['id'], $reason, $id]);

        log_activity($me['id'], 'visitor_' . $decision, 'visitor', $id, $v['visitor_name']);
        ok(['message' => $decision === 'approved' ? 'Visitor approved.' : 'Visitor denied.']);
    }

    /* -------- guard marks entry or exit -------- */
    if ($action === 'entry' || $action === 'exit') {
        require_guard();
        $id = (int) param('id', 0);
        if ($id <= 0) fail('Missing visitor id.');

        $st = db()->prepare('SELECT * FROM visitors WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $v = $st->fetch();
        if (!$v) fail('Visitor entry not found.', 404);

        if ($action === 'entry') {
            if ($v['status'] !== 'approved') {
                fail('Only approved visitors can be let in.', 409);
            }
            $st = db()->prepare('UPDATE visitors SET status = "entered", entry_at = NOW() WHERE id = ?');
            $st->execute([$id]);
            log_activity($me['id'], 'visitor_entered', 'visitor', $id, null);
            ok(['message' => 'Entry recorded.']);
        }

        if ($v['status'] !== 'entered') {
            fail('This visitor has not been marked as entered.', 409);
        }
        $st = db()->prepare('UPDATE visitors SET status = "exited", exit_at = NOW() WHERE id = ?');
        $st->execute([$id]);
        log_activity($me['id'], 'visitor_exited', 'visitor', $id, null);
        ok(['message' => 'Exit recorded.']);
    }

    /* -------- resident pre-approves someone -------- */
    if ($action === 'preapprove') {
        $u = require_resident();

        $name = clean_txt(param('visitor_name'), 120);
        if ($name === null || str_len($name) < 2) fail('Please enter the visitor name.');

        $mobile  = clean_mobile(param('visitor_mobile'));
        $purpose = param('purpose');
        if (!in_array($purpose, $PURPOSES, true)) $purpose = 'guest';

        $from = clean_txt(param('valid_from'), 10);
        $to   = clean_txt(param('valid_to'), 10);
        if (!$from || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) fail('Please choose a start date.');
        if (!$to   || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   fail('Please choose an end date.');
        if ($to < $from) fail('The end date cannot be before the start date.');

        $pass = gate_pass();
        $st = db()->prepare(
            'INSERT INTO preapproved_visitors
             (flat_id, visitor_name, visitor_mobile, purpose, valid_from, valid_to, gate_pass, created_by)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $st->execute([$u['flat_id'], $name, $mobile, $purpose, $from, $to, $pass, $u['id']]);

        log_activity($u['id'], 'visitor_preapproved', 'visitor', db()->lastInsertId(), $name);
        ok(['gate_pass' => $pass, 'message' => 'Pre-approved. Share this gate pass with your visitor.']);
    }

    /* -------- resident cancels a pre-approval -------- */
    if ($action === 'cancel_preapprove') {
        $u = require_resident();
        $id = (int) param('id', 0);
        $st = db()->prepare(
            'UPDATE preapproved_visitors SET is_active = 0 WHERE id = ? AND flat_id = ?'
        );
        $st->execute([$id, $u['flat_id']]);
        ok(['message' => 'Pre-approval cancelled.']);
    }

    fail('Unknown action.');
}

/* ============================================================
   GET
   ============================================================ */
$scope = param('scope', 'mine');

$base = 'SELECT v.*, f.flat_no, f.flat_code, b.name AS block_name,
                d.name AS decided_by_name, g.name AS logged_by_name
         FROM visitors v
         JOIN flats f  ON f.id = v.flat_id
         JOIN blocks b ON b.id = f.block_id
         LEFT JOIN users d ON d.id = v.decided_by
         LEFT JOIN users g ON g.id = v.created_by';

$args = [];

if ($scope === 'mine') {
    $u = require_resident();
    $sql = $base . ' WHERE v.flat_id = ? ORDER BY v.id DESC LIMIT 100';
    $args[] = $u['flat_id'];

} elseif ($scope === 'pending') {
    require_guard();
    $sql = $base . ' WHERE v.status = "pending" ORDER BY v.id DESC LIMIT 100';

} elseif ($scope === 'inside') {
    require_guard();
    $sql = $base . ' WHERE v.status = "entered" ORDER BY v.entry_at DESC LIMIT 100';

} else { /* today */
    require_guard();
    $sql = $base . ' WHERE DATE(v.created_at) = CURDATE() ORDER BY v.id DESC LIMIT 200';
}

$st = db()->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

$LABELS = [
    'guest'    => 'Guest',
    'delivery' => 'Delivery',
    'cab'      => 'Cab',
    'service'  => 'Service',
    'staff'    => 'Domestic help',
    'other'    => 'Other',
];

$out = [];
foreach ($rows as $r) {
    $out[] = [
        'id'             => (int) $r['id'],
        'flat_no'        => $r['flat_no'],
        'flat_code'      => $r['flat_code'],
        'block_name'     => $r['block_name'],
        'visitor_name'   => $r['visitor_name'],
        'visitor_mobile' => $r['visitor_mobile'],
        'visitor_count'  => (int) $r['visitor_count'],
        'purpose'        => $r['purpose'],
        'purpose_label'  => $LABELS[$r['purpose']] ?? 'Other',
        'purpose_note'   => $r['purpose_note'],
        'vehicle_no'     => $r['vehicle_no'],
        'status'         => $r['status'],
        'deny_reason'    => $r['deny_reason'],
        'gate_pass'      => $r['gate_pass'],
        'decided_by_name'=> $r['decided_by_name'],
        'logged_by_name' => $r['logged_by_name'],
        'decided_at'     => $r['decided_at'],
        'entry_at'       => $r['entry_at'],
        'exit_at'        => $r['exit_at'],
        'created_at'     => $r['created_at'],
    ];
}

$extra = [];

/* A resident also gets their active pre-approvals */
if ($scope === 'mine') {
    $st = db()->prepare(
        'SELECT id, visitor_name, visitor_mobile, purpose, valid_from, valid_to, gate_pass
         FROM preapproved_visitors
         WHERE flat_id = ? AND is_active = 1 AND valid_to >= CURDATE()
         ORDER BY valid_from'
    );
    $st->execute([$me['flat_id']]);
    $pre = $st->fetchAll();
    foreach ($pre as &$p) {
        $p['id'] = (int) $p['id'];
        $p['purpose_label'] = $LABELS[$p['purpose']] ?? 'Other';
    }
    unset($p);
    $extra['preapproved'] = $pre;
}

/* Counters for the gate screen */
if (in_array($scope, ['pending', 'today', 'inside'], true)) {
    $extra['counts'] = [
        'pending' => (int) db()->query('SELECT COUNT(*) FROM visitors WHERE status = "pending"')->fetchColumn(),
        'inside'  => (int) db()->query('SELECT COUNT(*) FROM visitors WHERE status = "entered"')->fetchColumn(),
        'today'   => (int) db()->query('SELECT COUNT(*) FROM visitors WHERE DATE(created_at) = CURDATE()')->fetchColumn(),
    ];
}

ok(array_merge(['scope' => $scope, 'visitors' => $out], $extra));
