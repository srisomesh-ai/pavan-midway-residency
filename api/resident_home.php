<?php
/**
 * GET /api/resident_home.php
 *
 * Everything the resident home screen needs in one call.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

api_init();

$u = require_resident();
$fid = (int) $u['flat_id'];

/* Expire stale visitor requests first */
try {
    $mins = (int) setting('visitor_auto_expire_minutes', 30);
    if ($mins > 0) {
        $st = db()->prepare(
            'UPDATE visitors SET status = "expired"
             WHERE status = "pending" AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        );
        $st->execute([$mins]);
    }
} catch (Exception $e) {}

/* ---- Flat ---- */
$st = db()->prepare(
    'SELECT f.id, f.flat_no, f.flat_code, f.floor_label, f.occupancy,
            b.name AS block_name
     FROM flats f JOIN blocks b ON b.id = f.block_id
     WHERE f.id = ? LIMIT 1'
);
$st->execute([$fid]);
$flat = $st->fetch();

/* ---- Details ---- */
$details = null;
$st = db()->prepare('SELECT * FROM flat_details WHERE flat_id = ? LIMIT 1');
$st->execute([$fid]);
$d = $st->fetch();
if ($d) {
    $details = [
        'owner_name'     => $d['owner_name'],
        'owner_mobile'   => $d['owner_mobile'],
        'tenant_name'    => $d['tenant_name'],
        'tenant_mobile'  => $d['tenant_mobile'],
        'status'         => $d['status'],
        'family_members' => $d['family_members'] !== null ? (int) $d['family_members'] : null,
        'vehicles'       => vehicle_list($d),
        'updated_at'     => $d['updated_at'],
    ];
}

/* ---- Visitors waiting on this resident ---- */
$st = db()->prepare(
    'SELECT id, visitor_name, visitor_mobile, visitor_count, purpose, purpose_note,
            vehicle_no, created_at
     FROM visitors
     WHERE flat_id = ? AND status = "pending"
     ORDER BY id DESC'
);
$st->execute([$fid]);

$LAB = ['guest'=>'Guest','delivery'=>'Delivery','cab'=>'Cab',
        'service'=>'Service','staff'=>'Domestic help','other'=>'Other'];

$waiting = [];
foreach ($st->fetchAll() as $r) {
    $waiting[] = [
        'id'            => (int) $r['id'],
        'visitor_name'  => $r['visitor_name'],
        'visitor_mobile'=> $r['visitor_mobile'],
        'visitor_count' => (int) $r['visitor_count'],
        'purpose'       => $r['purpose'],
        'purpose_label' => $LAB[$r['purpose']] ?? 'Other',
        'purpose_note'  => $r['purpose_note'],
        'vehicle_no'    => $r['vehicle_no'],
        'created_at'    => $r['created_at'],
    ];
}

/* ---- Recent visitor history ---- */
$st = db()->prepare(
    'SELECT id, visitor_name, purpose, status, entry_at, exit_at, created_at
     FROM visitors
     WHERE flat_id = ? AND status <> "pending"
     ORDER BY id DESC LIMIT 8'
);
$st->execute([$fid]);
$recent = [];
foreach ($st->fetchAll() as $r) {
    $recent[] = [
        'id'            => (int) $r['id'],
        'visitor_name'  => $r['visitor_name'],
        'purpose_label' => $LAB[$r['purpose']] ?? 'Other',
        'status'        => $r['status'],
        'entry_at'      => $r['entry_at'],
        'exit_at'       => $r['exit_at'],
        'created_at'    => $r['created_at'],
    ];
}

/* ---- Away notice ---- */
$st = db()->prepare(
    'SELECT id, from_date, to_date, status FROM away_notices
     WHERE flat_id = ? AND status IN ("upcoming","active")
     ORDER BY from_date LIMIT 1'
);
$st->execute([$fid]);
$away = $st->fetch();
if ($away) {
    $away['id'] = (int) $away['id'];
}

/* ---- Complaint counters ---- */
$st = db()->prepare(
    'SELECT
        SUM(status IN ("open","in_progress")) AS active,
        SUM(status = "resolved") AS resolved,
        COUNT(*) AS total
     FROM complaints WHERE flat_id = ?'
);
$st->execute([$fid]);
$c = $st->fetch();

/* ---- Active pre-approvals ---- */
$st = db()->prepare(
    'SELECT COUNT(*) FROM preapproved_visitors
     WHERE flat_id = ? AND is_active = 1 AND valid_to >= CURDATE()'
);
$st->execute([$fid]);
$pre = (int) $st->fetchColumn();

/* ---- Any submission of mine still pending? ---- */
$pending_sub = 0;
try {
    $st = db()->prepare('SELECT COUNT(*) FROM submissions WHERE flat_id = ? AND review_state = "pending"');
    $st->execute([$fid]);
    $pending_sub = (int) $st->fetchColumn();
} catch (Exception $e) {}

ok([
    'society' => setting('society_name', APP_NAME),
    'me' => [
        'id'            => (int) $u['id'],
        'name'          => $u['name'],
        'resident_type' => $u['resident_type'],
    ],
    'flat' => [
        'id'          => (int) $flat['id'],
        'flat_no'     => $flat['flat_no'],
        'flat_code'   => $flat['flat_code'],
        'floor_label' => $flat['floor_label'],
        'block_name'  => $flat['block_name'],
        'occupancy'   => $flat['occupancy'],
    ],
    'details'          => $details,
    'waiting'          => $waiting,
    'recent'           => $recent,
    'away'             => $away ?: null,
    'preapproved'      => $pre,
    'pending_review'   => $pending_sub,
    'complaints' => [
        'active'   => (int) ($c['active'] ?? 0),
        'resolved' => (int) ($c['resolved'] ?? 0),
        'total'    => (int) ($c['total'] ?? 0),
    ],
]);
