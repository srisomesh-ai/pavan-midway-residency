<?php
/**
 * GET  /api/submissions.php?state=pending
 * POST /api/submissions.php   { id, action: "approve"|"reject", note }
 *
 * Admin only. Approving copies the submission into flat_details
 * and updates the flat occupancy.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

api_init();

$admin = require_admin();

/* ============================================================
   POST - approve or reject
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $id     = (int) param('id', 0);
    $action = param('action');
    $note   = param('note');

    if ($id <= 0) fail('Missing submission id.');
    if (!in_array($action, ['approve', 'reject'], true)) {
        fail('Action must be approve or reject.');
    }

    $st = db()->prepare('SELECT * FROM submissions WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) fail('Submission not found.', 404);

    if ($s['review_state'] !== 'pending') {
        fail('This submission has already been ' . $s['review_state'] . '.', 409);
    }

    if ($action === 'reject') {
        $st = db()->prepare(
            'UPDATE submissions
             SET review_state = "rejected", reviewed_by = ?, reviewed_at = NOW(), review_note = ?
             WHERE id = ?'
        );
        $st->execute([$admin['id'], $note ? str_cut($note, 255) : null, $id]);
        log_activity($admin['id'], 'submission_rejected', 'submission', $id, null);
        ok(['message' => 'Submission rejected.']);
    }

    /* ---- Approve ---- */
    db()->beginTransaction();
    try {
        /* Upsert into flat_details */
        $sql = 'INSERT INTO flat_details
                (flat_id, owner_name, owner_mobile, owner_mobile_alt, owner_email,
                 vehicle_count, vehicle_1, vehicle_1_type, vehicle_2, vehicle_2_type,
                 vehicle_3, vehicle_3_type,
                 status, family_members,
                 tenant_name, tenant_mobile, tenant_mobile_alt, tenant_family,
                 rent_amount, lease_start, lease_end,
                 vacant_since, looking_to_rent, expected_rent,
                 notes, source_submission, approved_by, approved_at)
                VALUES (?,?,?,?,?, ?,?,?,?,?, ?,?, ?,?, ?,?,?,?, ?,?,?, ?,?,?, ?,?,?, NOW())
                ON DUPLICATE KEY UPDATE
                 owner_name=VALUES(owner_name), owner_mobile=VALUES(owner_mobile),
                 owner_mobile_alt=VALUES(owner_mobile_alt), owner_email=VALUES(owner_email),
                 vehicle_count=VALUES(vehicle_count),
                 vehicle_1=VALUES(vehicle_1), vehicle_1_type=VALUES(vehicle_1_type),
                 vehicle_2=VALUES(vehicle_2), vehicle_2_type=VALUES(vehicle_2_type),
                 vehicle_3=VALUES(vehicle_3), vehicle_3_type=VALUES(vehicle_3_type),
                 status=VALUES(status), family_members=VALUES(family_members),
                 tenant_name=VALUES(tenant_name), tenant_mobile=VALUES(tenant_mobile),
                 tenant_mobile_alt=VALUES(tenant_mobile_alt), tenant_family=VALUES(tenant_family),
                 rent_amount=VALUES(rent_amount), lease_start=VALUES(lease_start),
                 lease_end=VALUES(lease_end), vacant_since=VALUES(vacant_since),
                 looking_to_rent=VALUES(looking_to_rent), expected_rent=VALUES(expected_rent),
                 notes=VALUES(notes), source_submission=VALUES(source_submission),
                 approved_by=VALUES(approved_by), approved_at=NOW()';

        $st = db()->prepare($sql);
        $st->execute([
            $s['flat_id'], $s['owner_name'], $s['owner_mobile'], $s['owner_mobile_alt'], $s['owner_email'],
            $s['vehicle_count'], $s['vehicle_1'], $s['vehicle_1_type'], $s['vehicle_2'], $s['vehicle_2_type'],
            $s['vehicle_3'], $s['vehicle_3_type'],
            $s['status'], $s['family_members'],
            $s['tenant_name'], $s['tenant_mobile'], $s['tenant_mobile_alt'], $s['tenant_family'],
            $s['rent_amount'], $s['lease_start'], $s['lease_end'],
            $s['vacant_since'], $s['looking_to_rent'], $s['expected_rent'],
            $s['notes'], $s['id'], $admin['id'],
        ]);

        /* Keep flats.occupancy in step: rented -> tenant, owner -> owner, vacant -> vacant */
        $occ = $s['status'] === 'rented' ? 'tenant'
             : ($s['status'] === 'owner' ? 'owner' : 'vacant');

        $st = db()->prepare('UPDATE flats SET occupancy = ? WHERE id = ?');
        $st->execute([$occ, $s['flat_id']]);

        /* Mark reviewed */
        $st = db()->prepare(
            'UPDATE submissions
             SET review_state = "approved", reviewed_by = ?, reviewed_at = NOW(), review_note = ?
             WHERE id = ?'
        );
        $st->execute([$admin['id'], $note ? str_cut($note, 255) : null, $id]);

        db()->commit();
    } catch (Exception $e) {
        db()->rollBack();
        fail(DEBUG ? ('Could not approve: ' . $e->getMessage()) : 'Could not approve this submission.', 500);
    }

    log_activity($admin['id'], 'submission_approved', 'submission', $id, null);
    ok(['message' => 'Approved and published.']);
}

/* ============================================================
   GET - list
   ============================================================ */
$state = param('state', 'pending');
if (!in_array($state, ['pending', 'approved', 'rejected', 'all'], true)) {
    $state = 'pending';
}

$sql = 'SELECT s.*, f.flat_no, f.flat_code, f.floor_label,
               b.code AS block_code, b.name AS block_name,
               r.name AS reviewer_name,
               fd.flat_id AS has_existing
        FROM submissions s
        JOIN flats  f ON f.id = s.flat_id
        JOIN blocks b ON b.id = f.block_id
        LEFT JOIN users r ON r.id = s.reviewed_by
        LEFT JOIN flat_details fd ON fd.flat_id = s.flat_id';

$args = [];
if ($state !== 'all') {
    $sql .= ' WHERE s.review_state = ?';
    $args[] = $state;
}
$sql .= ' ORDER BY s.id DESC LIMIT 200';

$st = db()->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

$out = [];
foreach ($rows as $r) {
    $out[] = [
        'id'         => (int) $r['id'],
        'flat_no'    => $r['flat_no'],
        'flat_code'  => $r['flat_code'],
        'block_name' => $r['block_name'],
        'floor_label'=> $r['floor_label'],

        'owner_name'       => $r['owner_name'],
        'owner_mobile'     => $r['owner_mobile'],
        'owner_mobile_alt' => $r['owner_mobile_alt'],
        'owner_email'      => $r['owner_email'],

        'vehicle_count' => (int) $r['vehicle_count'],
        'vehicles'      => vehicle_list($r),

        'status'         => $r['status'],
        'family_members' => $r['family_members'] !== null ? (int) $r['family_members'] : null,

        'tenant_name'       => $r['tenant_name'],
        'tenant_mobile'     => $r['tenant_mobile'],
        'tenant_mobile_alt' => $r['tenant_mobile_alt'],
        'tenant_family'     => $r['tenant_family'] !== null ? (int) $r['tenant_family'] : null,
        'rent_amount'       => $r['rent_amount'] !== null ? (float) $r['rent_amount'] : null,
        'lease_start'       => $r['lease_start'],
        'lease_end'         => $r['lease_end'],

        'vacant_since'    => $r['vacant_since'],
        'looking_to_rent' => $r['looking_to_rent'] !== null ? ((int) $r['looking_to_rent'] === 1) : null,
        'expected_rent'   => $r['expected_rent'] !== null ? (float) $r['expected_rent'] : null,

        'notes'         => $r['notes'],
        'review_state'  => $r['review_state'],
        'reviewer_name' => $r['reviewer_name'],
        'reviewed_at'   => $r['reviewed_at'],
        'review_note'   => $r['review_note'],
        'is_update'     => $r['has_existing'] !== null,
        'created_at'    => $r['created_at'],
    ];
}

/* Counters for the tab badges */
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach (db()->query('SELECT review_state, COUNT(*) AS n FROM submissions GROUP BY review_state')->fetchAll() as $c) {
    $counts[$c['review_state']] = (int) $c['n'];
}

ok([
    'state'       => $state,
    'counts'      => $counts,
    'submissions' => $out,
]);
