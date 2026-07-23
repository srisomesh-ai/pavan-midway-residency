<?php
/**
 * GET /api/flat_detail.php?id=123
 *
 * Admin only. Everything known about one flat: structure plus the
 * approved resident details, if any have been collected.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

api_init();

$u = require_admin();

$id = (int) param('id', 0);
if ($id <= 0) fail('Missing flat id.');

$st = db()->prepare(
    'SELECT f.id, f.flat_no, f.flat_code, f.floor, f.floor_label,
            f.occupancy, f.flat_type, f.area_sqft,
            b.code AS block_code, b.name AS block_name
     FROM flats f
     JOIN blocks b ON b.id = f.block_id
     WHERE f.id = ? AND f.is_active = 1
     LIMIT 1'
);
$st->execute([$id]);
$flat = $st->fetch();
if (!$flat) fail('Flat not found.', 404);

$out = [
    'flat' => [
        'id'          => (int) $flat['id'],
        'flat_no'     => $flat['flat_no'],
        'flat_code'   => $flat['flat_code'],
        'floor_label' => $flat['floor_label'],
        'block_code'  => $flat['block_code'],
        'block_name'  => $flat['block_name'],
        'occupancy'   => $flat['occupancy'],
        'flat_type'   => $flat['flat_type'],
        'area_sqft'   => $flat['area_sqft'],
    ],
    'details'         => null,
    'has_details'     => false,
    'pending_review'  => 0,
];

/* Approved details, if collected */
try {
    $st = db()->prepare(
        'SELECT fd.*, u.name AS approved_by_name
         FROM flat_details fd
         LEFT JOIN users u ON u.id = fd.approved_by
         WHERE fd.flat_id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $d = $st->fetch();

    if ($d) {
        $out['has_details'] = true;
        $out['details'] = [
            'owner_name'       => $d['owner_name'],
            'owner_mobile'     => $d['owner_mobile'],
            'owner_mobile_alt' => $d['owner_mobile_alt'],
            'owner_email'      => $d['owner_email'],

            'vehicle_count' => (int) $d['vehicle_count'],
            'vehicles'      => vehicle_list($d),

            'status'         => $d['status'],
            'family_members' => $d['family_members'] !== null ? (int) $d['family_members'] : null,

            'tenant_name'       => $d['tenant_name'],
            'tenant_mobile'     => $d['tenant_mobile'],
            'tenant_mobile_alt' => $d['tenant_mobile_alt'],
            'tenant_family'     => $d['tenant_family'] !== null ? (int) $d['tenant_family'] : null,
            'rent_amount'       => $d['rent_amount'] !== null ? (float) $d['rent_amount'] : null,
            'lease_start'       => $d['lease_start'],
            'lease_end'         => $d['lease_end'],

            'vacant_since'    => $d['vacant_since'],
            'looking_to_rent' => $d['looking_to_rent'] !== null ? ((int) $d['looking_to_rent'] === 1) : null,
            'expected_rent'   => $d['expected_rent'] !== null ? (float) $d['expected_rent'] : null,

            'notes'            => $d['notes'],
            'approved_by_name' => $d['approved_by_name'],
            'approved_at'      => $d['approved_at'],
            'updated_at'       => $d['updated_at'],
        ];
    }

    /* Any submission still waiting for this flat? */
    $st = db()->prepare(
        'SELECT COUNT(*) FROM submissions WHERE flat_id = ? AND review_state = "pending"'
    );
    $st->execute([$id]);
    $out['pending_review'] = (int) $st->fetchColumn();

} catch (PDOException $e) {
    /* Resident form tables not created yet - structure still returned */
    if ($e->getCode() !== '42S02') {
        fail(DEBUG ? $e->getMessage() : 'Could not load flat details.', 500);
    }
}

ok($out);
