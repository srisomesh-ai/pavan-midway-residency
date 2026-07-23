<?php
/**
 * GET /api/public_flats.php
 *
 * PUBLIC - no login required.
 * Returns the flat list for the resident form dropdown.
 * Deliberately exposes nothing beyond block, floor and flat number.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

api_init();

if (setting('form_open', '1') !== '1') {
    fail('The resident information form is currently closed.', 403);
}

$rows = db()->query(
    'SELECT f.id, f.flat_no, f.flat_code, f.floor, f.floor_label,
            b.code AS block_code, b.name AS block_name
     FROM flats f
     JOIN blocks b ON b.id = f.block_id
     WHERE f.is_active = 1
     ORDER BY b.sort_order, b.code, f.floor, f.flat_no'
)->fetchAll();

/* Group by block so the dropdown can use optgroups */
$blocks = [];
foreach ($rows as $r) {
    $bc = $r['block_code'];
    if (!isset($blocks[$bc])) {
        $blocks[$bc] = [
            'block_code' => $bc,
            'block_name' => $r['block_name'],
            'flats'      => [],
        ];
    }
    $blocks[$bc]['flats'][] = [
        'id'          => (int) $r['id'],
        'flat_no'     => $r['flat_no'],
        'flat_code'   => $r['flat_code'],
        'floor_label' => $r['floor_label'],
    ];
}

ok([
    'society' => setting('society_name', APP_NAME),
    'blocks'  => array_values($blocks),
]);
