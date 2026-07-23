<?php
/**
 * PUBLIC GATE API - no login required.
 * Reached by scanning the QR code at the gate.
 *
 * GET  /api/gate.php?action=flats            flat list for the picker
 * GET  /api/gate.php?action=today            today's entries (for the guard's screen)
 * GET  /api/gate.php?action=status&id=123    poll one entry for the resident's decision
 * POST /api/gate.php  { action:"create", flat_id, visitor_name, ... }
 * POST /api/gate.php  { action:"entry", id }   mark the visitor in
 * POST /api/gate.php  { action:"exit",  id }   mark the visitor out
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
        if ($mobile !== null && trim($mobile) !== '') {
            $d = preg_replace('/\D/', '', $mobile);
            if (strlen($d) === 12 && substr($d, 0, 2) === '91') $d = substr($d, 2);
            if (!preg_match('/^[6-9]\d{9}$/', $d)) {
                fail('The visitor mobile number is not valid.');
            }
            $mobile = $d;
        } else {
            $mobile = null;
        }

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
                trim($LABELS[$purpose] . ($count > 1 ? ' - ' . $count . ' people' : '')
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
    if ($action === 'entry' || $action === 'exit') {
        $id = (int) param('id', 0);
        if ($id <= 0) fail('Missing visitor id.');

        $st = db()->prepare('SELECT id, status FROM visitors WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $v = $st->fetch();
        if (!$v) fail('Visitor entry not found.', 404);

        if ($action === 'entry') {
            if ($v['status'] !== 'approved') {
                fail('This visitor has not been approved yet.', 409);
            }
            db()->prepare('UPDATE visitors SET status = "entered", entry_at = NOW() WHERE id = ?')
                ->execute([$id]);
            ok(['message' => 'Entry recorded.']);
        }

        if (!in_array($v['status'], ['entered', 'approved'], true)) {
            fail('This visitor is not inside.', 409);
        }
        db()->prepare('UPDATE visitors SET status = "exited", exit_at = NOW() WHERE id = ?')
            ->execute([$id]);
        ok(['message' => 'Exit recorded.']);
    }

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
    if ($id <= 0) fail('Missing visitor id.');

    $st = db()->prepare(
        'SELECT v.id, v.visitor_name, v.status, v.deny_reason, v.entry_at, v.exit_at,
                f.flat_no
         FROM visitors v JOIN flats f ON f.id = v.flat_id
         WHERE v.id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $v = $st->fetch();
    if (!$v) fail('Visitor entry not found.', 404);

    ok([
        'id'           => (int) $v['id'],
        'visitor_name' => $v['visitor_name'],
        'flat_no'      => $v['flat_no'],
        'status'       => $v['status'],
        'deny_reason'  => $v['deny_reason'],
        'entry_at'     => $v['entry_at'],
        'exit_at'      => $v['exit_at'],
    ]);
}

/* -------- today's log for the gate screen -------- */
if ($action === 'today') {
    $st = db()->query(
        'SELECT v.id, v.visitor_name, v.visitor_count, v.purpose, v.vehicle_no,
                v.status, v.deny_reason, v.entry_at, v.exit_at, v.created_at,
                f.flat_no
         FROM visitors v JOIN flats f ON f.id = v.flat_id
         WHERE v.created_at >= CURDATE()
         ORDER BY v.id DESC
         LIMIT 100'
    );

    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = [
            'id'            => (int) $r['id'],
            'visitor_name'  => $r['visitor_name'],
            'visitor_count' => (int) $r['visitor_count'],
            'purpose'       => $r['purpose'],
            'purpose_label' => $LABELS[$r['purpose']] ?? 'Other',
            'vehicle_no'    => $r['vehicle_no'],
            'flat_no'       => $r['flat_no'],
            'status'        => $r['status'],
            'deny_reason'   => $r['deny_reason'],
            'entry_at'      => $r['entry_at'],
            'exit_at'       => $r['exit_at'],
            'created_at'    => $r['created_at'],
        ];
    }

    $counts = ['waiting' => 0, 'inside' => 0, 'today' => count($out)];
    foreach ($out as $o) {
        if ($o['status'] === 'pending') $counts['waiting']++;
        if ($o['status'] === 'entered') $counts['inside']++;
    }

    ok(['counts' => $counts, 'visitors' => $out]);
}

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
