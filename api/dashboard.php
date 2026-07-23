<?php
/**
 * GET /api/dashboard.php
 * Summary counters for the admin home screen.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

api_init();

$u = require_admin();

$d = db();

/* ---- Flats ---- */
$flats_total = (int) $d->query('SELECT COUNT(*) FROM flats WHERE is_active = 1')->fetchColumn();

$st = $d->query(
    'SELECT occupancy, COUNT(*) AS n FROM flats WHERE is_active = 1 GROUP BY occupancy'
);
$occ = ['owner' => 0, 'tenant' => 0, 'vacant' => 0];
foreach ($st->fetchAll() as $r) {
    $occ[$r['occupancy']] = (int) $r['n'];
}
$occupied = $occ['owner'] + $occ['tenant'];

/* ---- Blocks ---- */
$blocks = $d->query(
    'SELECT b.id, b.name, b.code, b.total_floors, b.flats_per_floor,
            COUNT(f.id) AS flats,
            SUM(CASE WHEN f.occupancy <> "vacant" THEN 1 ELSE 0 END) AS occupied
     FROM blocks b
     LEFT JOIN flats f ON f.block_id = b.id AND f.is_active = 1
     GROUP BY b.id
     ORDER BY b.sort_order, b.code'
)->fetchAll();

foreach ($blocks as &$b) {
    $b['id']              = (int) $b['id'];
    $b['total_floors']    = (int) $b['total_floors'];
    $b['flats_per_floor'] = (int) $b['flats_per_floor'];
    $b['flats']           = (int) $b['flats'];
    $b['occupied']        = (int) $b['occupied'];
}
unset($b);

/* ---- Residents ---- */
$residents = (int) $d->query(
    'SELECT COUNT(*) FROM users WHERE role = "resident" AND status = "active"'
)->fetchColumn();

$pending = (int) $d->query(
    'SELECT COUNT(*) FROM users WHERE role = "resident" AND status = "pending"'
)->fetchColumn();

$committee = (int) $d->query(
    'SELECT COUNT(*) FROM users WHERE role IN ("super_admin","admin") AND status = "active"'
)->fetchColumn();

/* ---- Form submissions awaiting review ---- */
$subs_pending = 0;
$details_done = 0;
try {
    $subs_pending = (int) $d->query(
        'SELECT COUNT(*) FROM submissions WHERE review_state = "pending"'
    )->fetchColumn();
    $details_done = (int) $d->query('SELECT COUNT(*) FROM flat_details')->fetchColumn();
} catch (Exception $e) {
    // submissions tables not created yet - run sql/04_resident_form.sql
}

/* ---- Resident app counters ---- */
$app = ['complaints_open' => 0, 'logins' => 0, 'away_now' => 0, 'visitors_today' => 0, 'visitors_pending' => 0];
try {
    $app['complaints_open'] = (int) $d->query(
        'SELECT COUNT(*) FROM complaints WHERE status IN ("open","in_progress")'
    )->fetchColumn();
    $app['logins'] = (int) $d->query(
        'SELECT COUNT(*) FROM users WHERE role = "resident" AND status = "active"'
    )->fetchColumn();
    $app['away_now'] = (int) $d->query(
        'SELECT COUNT(*) FROM away_notices WHERE status = "active"'
    )->fetchColumn();
    $app['visitors_today'] = (int) $d->query(
        'SELECT COUNT(*) FROM visitors WHERE DATE(created_at) = CURDATE()'
    )->fetchColumn();
    $app['visitors_pending'] = (int) $d->query(
        'SELECT COUNT(*) FROM visitors WHERE status = "pending"'
    )->fetchColumn();
} catch (Exception $e) {
    // resident app tables not created yet - run sql/06_resident_app.sql
}

/* ---- Recent activity ---- */
$activity = $d->query(
    'SELECT a.action, a.details, a.created_at, u.name AS user_name
     FROM activity_log a
     LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.id DESC
     LIMIT 8'
)->fetchAll();

ok([
    'society' => [
        'name'         => setting('society_name', APP_NAME),
        'total_blocks' => count($blocks),
        'total_flats'  => $flats_total,
    ],
    'flats' => [
        'total'    => $flats_total,
        'occupied' => $occupied,
        'vacant'   => $occ['vacant'],
        'owner'    => $occ['owner'],
        'tenant'   => $occ['tenant'],
    ],
    'people' => [
        'residents' => $residents,
        'pending'   => $pending,
        'committee' => $committee,
    ],
    'forms' => [
        'pending'       => $subs_pending,
        'details_filled'=> $details_done,
        'total_flats'   => $flats_total,
    ],
    'app'    => $app,
    'blocks'   => $blocks,
    'activity' => $activity,
]);
