<?php
/**
 * PUBLIC VISITOR API - no login required.
 *
 * The visitor scans the QR at the gate and fills the form on their own
 * phone. Security staff do nothing - they just look at the visitor's
 * screen, which shows APPROVED or REJECTED in large type.
 *
 * GET  /api/gate.php?action=flats          blocks and flats for the picker
 * GET  /api/gate.php?action=status&id=123  poll for the resident's decision
 * POST /api/gate.php  { action:"create", flat_id, visitor_name, visitor_mobile, purpose }
 *
 * Nothing here exposes resident names, phone numbers or flat details.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

api_init();

/* ------------------------------------------------------------
   Gate must be switched on
   ------------------------------------------------------------ */
if (setting('gate_open', '1') !== '1') {
    fail('The gate page is currently closed. Please contact the committee.', 403);
}

/* ------------------------------------------------------------
   Optional secret. Empty by default, so the link is open.
   Set `gate_key` in settings later to lock it down without
   reprinting the QR - just append ?k=<value> to the poster URL.
   ------------------------------------------------------------ */
$key = trim((string) setting('gate_key', ''));
if ($key !== '') {
    $given = (string) param('k', '');
    if (!hash_equals($key, $given)) {
        fail('This gate link is no longer valid. Please ask the committee for the current QR code.', 403);
    }
}

if (!resident_app_ready()) {
    fail('The visitor system is not set up on this server yet. Import sql/06_resident_app.sql and sql/08_open_gate.sql.', 503);
}

$ip = client_ip();

$LABELS = [
    'guest'    => 'Guest',
    'delivery' => 'Delivery',
    'cab'      => 'Cab',
    'service'  => 'Service',
    'staff'    => 'Domestic help',
    'other'    => 'Other',
];

/* ============================================================
   POST
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = param('action');

    /* -------- log a new visitor -------- */
    if ($action === 'create') {

        /* Rate limit so a leaked link cannot be used to spam residents */
        $max = (int) setting('gate_max_per_hour', 20);
        try {
            $st = db()->prepare(
                'SELECT COUNT(*) FROM gate_submits
                 WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
            );
            $st->execute([$ip]);
            if ((int) $st->fetchColumn() >= $max) {
                fail('Too many entries from this device in the last hour. Please wait a little.', 429);
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '42S02') {
                fail('The gate page is not set up yet. Import sql/08_open_gate.sql.', 503);
            }
        }

        $flat_id = (int) param('flat_id', 0);
        if ($flat_id <= 0) fail('Please choose the flat being visited.');

        $st = db()->prepare(
            'SELECT f.id, f.flat_no, f.flat_code, b.name AS block_name
             FROM flats f JOIN blocks b ON b.id = f.block_id
             WHERE f.id = ? AND f.is_active = 1 LIMIT 1'
        );
        $st->execute([$flat_id]);
        $flat = $st->fetch();
        if (!$flat) fail('That flat could not be found.');

        $name = clean_g(param('visitor_name'), 120);
        if ($name === null || str_len($name) < 2) {
            fail('Please enter the visitor name.');
        }

        $mobile = param('visitor_mobile');
        if ($mobile === null || trim($mobile) === '') {
            fail('Please enter your mobile number.');
        }
        $d = preg_replace('/\D/', '', $mobile);
        if (strlen($d) === 12 && substr($d, 0, 2) === '91') $d = substr($d, 2);
        if (!preg_match('/^[6-9]\d{9}$/', $d)) {
            fail('Please enter a valid 10 digit mobile number.');
        }
        $mobile = $d;

        $purpose = param('purpose');
        if (!isset($LABELS[$purpose])) $purpose = 'guest';

        $note    = clean_g(param('purpose_note'), 150);
        $vehicle = clean_g(param('vehicle_no'), 20);
        if ($vehicle !== null) {
            $vehicle = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vehicle));
            if ($vehicle === '') $vehicle = null;
        }

        $count = (int) param('visitor_count', 1);
        if ($count < 1) $count = 1;
        if ($count > 30) $count = 30;

        /* A valid gate pass lets them straight in */
        $auto = false;
        $pass = param('gate_pass');
        if ($pass !== null && trim($pass) !== '') {
            try {
                $st = db()->prepare(
                    'SELECT id FROM preapproved_visitors
                     WHERE gate_pass = ? AND flat_id = ? AND is_active = 1
                       AND valid_from <= CURDATE() AND valid_to >= CURDATE()
                     LIMIT 1'
                );
                $st->execute([strtoupper(trim($pass)), $flat_id]);
                $auto = (bool) $st->fetch();
            } catch (Exception $e) {
                $auto = false;
            }
        }

        $status = $auto ? 'approved' : 'pending';

        $st = db()->prepare(
            'INSERT INTO visitors
             (flat_id, visitor_name, visitor_mobile, visitor_count, purpose, purpose_note,
              vehicle_no, status, gate_pass, decided_at, created_by, source, logged_ip)
             VALUES (?,?,?,?,?,?,?,?,?,' . ($auto ? 'NOW()' : 'NULL') . ',NULL,?,?)'
        );
        $st->execute([
            $flat_id, $name, $mobile, $count, $purpose, $note,
            $vehicle, $status, gate_code(), 'gate_open', $ip,
        ]);

        $vid = (int) db()->lastInsertId();

        try {
            db()->prepare('INSERT INTO gate_submits (ip_address, flat_id) VALUES (?,?)')
                ->execute([$ip, $flat_id]);
        } catch (Exception $e) {
            // logging only
        }

        /* Housekeeping */
        if (random_int(1, 40) === 1) {
            try {
                db()->exec('DELETE FROM gate_submits WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)');
            } catch (Exception $e) {}
        }

        /* Tell the resident */
        if (!$auto) {
            notify_flat(
                $flat_id,
                'visitor',
                $name . ' is at the gate',
                trim($LABELS[$purpose]
                    . ($count > 1 ? ' - ' . $count . ' people' : '')
                    . ' - ' . $mobile
                    . '. Tap to allow or deny.'),
                [
                    'link'      => 'my-visitors.html',
                    'entity'    => 'visitor',
                    'entity_id' => $vid,
                    'urgent'    => 1,
                ]
            );
        } else {
            notify_flat(
                $flat_id,
                'visitor',
                $name . ' was let in',
                'Pre-approved gate pass used.',
                ['link' => 'my-visitors.html', 'entity' => 'visitor', 'entity_id' => $vid]
            );
        }

        log_activity(null, 'gate_visitor_logged', 'visitor', $vid, $flat['flat_code'] . ' - ' . $name);

        ok([
            'id'      => $vid,
            'status'  => $status,
            'auto'    => $auto,
            'flat_no' => $flat['flat_no'],
            'message' => $auto
                ? 'Pre-approved. Allow the visitor in.'
                : 'Sent to the resident. Please wait for their reply.',
        ]);
    }

    /* -------- mark entry / exit -------- */

    fail('Unknown action.');
}

/* ============================================================
   GET
   ============================================================ */
$action = param('action', 'flats');

/* -------- flat picker -------- */
if ($action === 'flats') {
    $rows = db()->query(
        'SELECT f.id, f.flat_no, f.floor_label, b.code AS block_code, b.name AS block_name
         FROM flats f JOIN blocks b ON b.id = f.block_id
         WHERE f.is_active = 1
         ORDER BY b.sort_order, b.code, f.floor, f.flat_no'
    )->fetchAll();

    $blocks = [];
    foreach ($rows as $r) {
        $bc = $r['block_code'];
        if (!isset($blocks[$bc])) {
            $blocks[$bc] = ['block_code' => $bc, 'block_name' => $r['block_name'], 'flats' => []];
        }
        $blocks[$bc]['flats'][] = [
            'id'          => (int) $r['id'],
            'flat_no'     => $r['flat_no'],
            'floor_label' => $r['floor_label'],
        ];
    }

    ok([
        'society'  => setting('society_name', APP_NAME),
        'purposes' => $LABELS,
        'blocks'   => array_values($blocks),
    ]);
}

/* -------- one entry, for polling the resident's decision -------- */
if ($action === 'status') {
    $id = (int) param('id', 0);
    if ($id <= 0) fail('Missing request id.');

    $st = db()->prepare(
        'SELECT v.id, v.visitor_name, v.visitor_mobile, v.visitor_count,
                v.purpose, v.status, v.deny_reason, v.decided_at, v.created_at,
                v.gate_pass, f.flat_no, b.name AS block_name
         FROM visitors v
         JOIN flats f  ON f.id = v.flat_id
         JOIN blocks b ON b.id = f.block_id
         WHERE v.id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $v = $st->fetch();
    if (!$v) fail('Request not found.', 404);

    /* Auto expire a request nobody answered.
       The comparison is done inside MySQL so a database on a different
       timezone to PHP cannot make everything expire instantly. */
    $mins = (int) setting('visitor_auto_expire_minutes', 30);
    if ($v['status'] === 'pending' && $mins > 0) {
        $ex = db()->prepare(
            'UPDATE visitors
             SET status = "expired"
             WHERE id = ? AND status = "pending"
               AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        );
        $ex->execute([$id, $mins]);
        if ($ex->rowCount() > 0) {
            $v['status'] = 'expired';
        }
    }

    /* Seconds waited so far, again measured by the database */
    $waited = 0;
    try {
        $ws = db()->prepare('SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM visitors WHERE id = ?');
        $ws->execute([$id]);
        $waited = max(0, (int) $ws->fetchColumn());
    } catch (Exception $e) {
        $waited = 0;
    }

    /* Format the stamp on the server so the visitor always sees the
       society's local time, whatever their phone is set to. */
    $stamp_src = $v['decided_at'] ?: $v['created_at'];
    $stamp_date = '';
    $stamp_time = '';
    try {
        $fs = db()->prepare(
            'SELECT DATE_FORMAT(?, "%e %b %Y") AS d, DATE_FORMAT(?, "%l:%i %p") AS t'
        );
        $fs->execute([$stamp_src, $stamp_src]);
        $fr = $fs->fetch();
        $stamp_date = $fr['d'] ?? '';
        $stamp_time = trim($fr['t'] ?? '');
    } catch (Exception $e) {}

    ok([
        'id'            => (int) $v['id'],
        'visitor_name'  => $v['visitor_name'],
        'visitor_count' => (int) $v['visitor_count'],
        'purpose'       => $v['purpose'],
        'purpose_label' => $LABELS[$v['purpose']] ?? 'Other',
        'flat_no'       => $v['flat_no'],
        'block_name'    => $v['block_name'],
        'status'        => $v['status'],
        'deny_reason'   => $v['deny_reason'],
        'gate_pass'     => $v['gate_pass'],
        'stamp_date'    => $stamp_date,
        'stamp_time'    => $stamp_time,
        'waited_secs'   => $waited,
    ]);
}

/* -------- today's log for the gate screen -------- */

fail('Unknown action.');


/* ---------------------------------------------------------- */
function clean_g($v, $len) {
    if ($v === null) return null;
    $v = trim((string) $v);
    if ($v === '') return null;
    return str_cut($v, $len);
}

function gate_code() {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < 6; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}
