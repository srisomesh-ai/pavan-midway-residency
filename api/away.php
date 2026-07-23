<?php
/**
 * /api/away.php
 *
 * GET  ?scope=mine      resident: my flat's notices
 *      ?scope=active    admin/guard: who is away right now
 *      ?scope=all       admin: everything
 *
 * POST { action:"create", from_date, to_date, contact_mobile, key_with, note }
 *      { action:"cancel", id }
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

api_init();

$me = require_auth();
require_resident_app();

/** Roll statuses forward based on today's date. */
function refresh_away() {
    try {
        db()->exec(
            'UPDATE away_notices SET status = "active"
             WHERE status = "upcoming" AND from_date <= CURDATE() AND to_date >= CURDATE()'
        );
        db()->exec(
            'UPDATE away_notices SET status = "completed"
             WHERE status IN ("upcoming","active") AND to_date < CURDATE()'
        );
    } catch (Exception $e) {}
}

refresh_away();

/* ============================================================
   POST
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = param('action');

    if ($action === 'create') {
        $u = require_resident();

        $from = clean_txt(param('from_date'), 10);
        $to   = clean_txt(param('to_date'), 10);

        if (!$from || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) fail('Please choose the date you leave.');
        if (!$to   || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   fail('Please choose the date you return.');
        if ($to < $from) fail('The return date cannot be before the date you leave.');

        /* Overlapping notice already recorded? */
        $st = db()->prepare(
            'SELECT id FROM away_notices
             WHERE flat_id = ? AND status IN ("upcoming","active")
               AND from_date <= ? AND to_date >= ?
             LIMIT 1'
        );
        $st->execute([$u['flat_id'], $to, $from]);
        if ($st->fetch()) {
            fail('You already have a notice covering those dates.', 409);
        }

        $mobile = clean_mobile(param('contact_mobile'));
        $key    = clean_txt(param('key_with'), 120);
        $note   = clean_txt(param('note'), 300);

        $status = ($from <= date('Y-m-d') && $to >= date('Y-m-d')) ? 'active' : 'upcoming';

        $st = db()->prepare(
            'INSERT INTO away_notices (flat_id, from_date, to_date, contact_mobile, key_with, note, status, created_by)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $st->execute([$u['flat_id'], $from, $to, $mobile, $key, $note, $status, $u['id']]);

        log_activity($u['id'], 'away_notice', 'away', db()->lastInsertId(), $from . ' to ' . $to);
        ok(['message' => 'The committee and security have been notified.']);
    }

    if ($action === 'cancel') {
        $id = (int) param('id', 0);
        if ($id <= 0) fail('Missing notice id.');

        if (is_admin($me)) {
            $st = db()->prepare('UPDATE away_notices SET status = "cancelled" WHERE id = ?');
            $st->execute([$id]);
        } else {
            $u = require_resident();
            $st = db()->prepare('UPDATE away_notices SET status = "cancelled" WHERE id = ? AND flat_id = ?');
            $st->execute([$id, $u['flat_id']]);
        }
        ok(['message' => 'Notice cancelled.']);
    }

    fail('Unknown action.');
}

/* ============================================================
   GET
   ============================================================ */
$scope = param('scope', 'mine');

$base = 'SELECT a.*, f.flat_no, f.flat_code, b.name AS block_name, u.name AS created_by_name
         FROM away_notices a
         JOIN flats f  ON f.id = a.flat_id
         JOIN blocks b ON b.id = f.block_id
         LEFT JOIN users u ON u.id = a.created_by';

$args = [];

if ($scope === 'mine') {
    $u = require_resident();
    $sql = $base . ' WHERE a.flat_id = ? ORDER BY a.from_date DESC LIMIT 50';
    $args[] = $u['flat_id'];

} elseif ($scope === 'active') {
    require_guard();
    $sql = $base . ' WHERE a.status = "active" ORDER BY a.to_date LIMIT 200';

} else {
    require_admin();
    $sql = $base . ' WHERE a.status IN ("upcoming","active") ORDER BY a.from_date LIMIT 200';
}

$st = db()->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

$out = [];
foreach ($rows as $r) {
    $days = (int) ((strtotime($r['to_date']) - strtotime($r['from_date'])) / 86400) + 1;
    $out[] = [
        'id'             => (int) $r['id'],
        'flat_no'        => $r['flat_no'],
        'flat_code'      => $r['flat_code'],
        'block_name'     => $r['block_name'],
        'from_date'      => $r['from_date'],
        'to_date'        => $r['to_date'],
        'days'           => $days,
        'contact_mobile' => $r['contact_mobile'],
        'key_with'       => $r['key_with'],
        'note'           => $r['note'],
        'status'         => $r['status'],
        'created_by_name'=> $r['created_by_name'],
        'created_at'     => $r['created_at'],
    ];
}

ok(['scope' => $scope, 'notices' => $out]);
