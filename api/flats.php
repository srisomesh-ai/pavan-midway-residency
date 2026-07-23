<?php
/**
 * GET /api/flats.php
 * Query: block=A|B  floor=0..4  occupancy=owner|tenant|vacant  q=search
 *
 * Returns the fixed flat register grouped by floor.
 * Flats are structural data and cannot be created, edited or deleted here.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

api_init();

$u = require_admin();

$block     = param('block');
$floor     = param('floor');
$occupancy = param('occupancy');
$q         = param('q');

$sql = 'SELECT f.id, f.flat_no, f.flat_code, f.floor, f.floor_label,
               f.occupancy, f.flat_type, f.area_sqft, f.is_locked,
               b.code AS block_code, b.name AS block_name,
               (SELECT COUNT(*) FROM user_flats uf WHERE uf.flat_id = f.id) AS members
        FROM flats f
        JOIN blocks b ON b.id = f.block_id
        WHERE f.is_active = 1';

$args = [];

if ($block !== null && $block !== '') {
    $sql .= ' AND b.code = ?';
    $args[] = $block;
}

if ($floor !== null && $floor !== '') {
    $sql .= ' AND f.floor = ?';
    $args[] = (int) $floor;
}

if ($occupancy !== null && $occupancy !== '') {
    $sql .= ' AND f.occupancy = ?';
    $args[] = $occupancy;
}

if ($q !== null && $q !== '') {
    $sql .= ' AND (f.flat_no LIKE ? OR f.flat_code LIKE ?)';
    $like = '%' . $q . '%';
    $args[] = $like;
    $args[] = $like;
}

$sql .= ' ORDER BY b.sort_order, b.code, f.floor, f.flat_no';

$st = db()->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

/* Group by block then floor for easy rendering */
$grouped = [];
foreach ($rows as $r) {
    $bc = $r['block_code'];
    $fl = (int) $r['floor'];

    if (!isset($grouped[$bc])) {
        $grouped[$bc] = [
            'block_code' => $bc,
            'block_name' => $r['block_name'],
            'floors'     => [],
        ];
    }
    if (!isset($grouped[$bc]['floors'][$fl])) {
        $grouped[$bc]['floors'][$fl] = [
            'floor'       => $fl,
            'floor_label' => $r['floor_label'],
            'flats'       => [],
        ];
    }

    $grouped[$bc]['floors'][$fl]['flats'][] = [
        'id'        => (int) $r['id'],
        'flat_no'   => $r['flat_no'],
        'flat_code' => $r['flat_code'],
        'occupancy' => $r['occupancy'],
        'flat_type' => $r['flat_type'],
        'area_sqft' => $r['area_sqft'],
        'members'   => (int) $r['members'],
        'is_locked' => (int) $r['is_locked'] === 1,
    ];
}

/* Re-index so JSON arrays stay arrays */
$out = [];
foreach ($grouped as $bc => $blk) {
    ksort($blk['floors']);
    $blk['floors'] = array_values($blk['floors']);
    $out[] = $blk;
}

ok([
    'count'  => count($rows),
    'blocks' => $out,
]);
