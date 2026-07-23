<?php
/**
 * POST /api/submit_details.php
 *
 * PUBLIC - no login required.
 * Accepts a resident information form submission. Nothing goes live
 * until a committee member approves it from the admin dashboard.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

api_init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed', 405);
}

if (setting('form_open', '1') !== '1') {
    fail('The resident information form is currently closed.', 403);
}

$ip = client_ip();

/* ---------- Rate limit ---------- */
$max = (int) setting('form_max_per_ip_hour', 5);
$st = db()->prepare(
    'SELECT COUNT(*) FROM form_submits
     WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
);
$st->execute([$ip]);
if ((int) $st->fetchColumn() >= $max) {
    fail('Too many submissions from this device. Please try again in an hour.', 429);
}

/* ---------- Helpers ---------- */

function clean($v, $len = 150) {
    if ($v === null) return null;
    $v = trim((string) $v);
    if ($v === '') return null;
    return str_cut($v, $len);
}

function valid_mobile($m) {
    if ($m === null) return false;
    $d = preg_replace('/\D/', '', $m);
    // Indian mobile: 10 digits starting 6-9, optionally +91 prefixed
    if (strlen($d) === 12 && substr($d, 0, 2) === '91') $d = substr($d, 2);
    return (bool) preg_match('/^[6-9]\d{9}$/', $d);
}

function norm_mobile($m) {
    $d = preg_replace('/\D/', '', (string) $m);
    if (strlen($d) === 12 && substr($d, 0, 2) === '91') $d = substr($d, 2);
    return $d;
}

function norm_vehicle($v) {
    $v = clean($v, 20);
    if ($v === null) return null;
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $v));
}

/* ---------- Flat ---------- */
$flat_id = (int) param('flat_id', 0);
if ($flat_id <= 0) {
    fail('Please select your flat number.');
}

$st = db()->prepare('SELECT id, flat_no, flat_code FROM flats WHERE id = ? AND is_active = 1 LIMIT 1');
$st->execute([$flat_id]);
$flat = $st->fetch();
if (!$flat) {
    fail('That flat could not be found. Please select your flat from the list.');
}

/* ---------- Owner ---------- */
$owner_name = clean(param('owner_name'), 120);
if ($owner_name === null || str_len($owner_name) < 2) {
    fail('Please enter the owner name.');
}

$owner_mobile = param('owner_mobile');
if (!valid_mobile($owner_mobile)) {
    fail('Please enter a valid 10 digit owner mobile number.');
}
$owner_mobile = norm_mobile($owner_mobile);

$owner_mobile_alt = param('owner_mobile_alt');
if ($owner_mobile_alt !== null && trim($owner_mobile_alt) !== '') {
    if (!valid_mobile($owner_mobile_alt)) {
        fail('The second owner mobile number is not valid.');
    }
    $owner_mobile_alt = norm_mobile($owner_mobile_alt);
} else {
    $owner_mobile_alt = null;
}

$owner_email = clean(param('owner_email'), 150);
if ($owner_email !== null && !filter_var($owner_email, FILTER_VALIDATE_EMAIL)) {
    fail('Please enter a valid email address, or leave it blank.');
}

/* ---------- Vehicles ---------- */
$vcount = (int) param('vehicle_count', 0);
if ($vcount < 0 || $vcount > 3) $vcount = 0;

$vehicles = [null, null, null];
if ($vcount >= 1) $vehicles[0] = norm_vehicle(param('vehicle_1'));
if ($vcount >= 2) $vehicles[1] = norm_vehicle(param('vehicle_2'));
if ($vcount >= 3) $vehicles[2] = norm_vehicle(param('vehicle_3'));

for ($i = 0; $i < $vcount; $i++) {
    if ($vehicles[$i] === null || strlen($vehicles[$i]) < 6) {
        fail('Please enter vehicle number ' . ($i + 1) . ' (for example TS07AB1234).');
    }
}

$v1 = $vehicles[0];
$v2 = $vehicles[1];
$v3 = $vehicles[2];

/* ---------- Status branch ---------- */
$status = param('status');
if (!in_array($status, ['owner', 'rented', 'vacant'], true)) {
    fail('Please choose whether the flat is owner occupied, rented out, or vacant.');
}

$family_members = null;
$tenant_name = $tenant_mobile = $tenant_mobile_alt = null;
$tenant_family = null;
$rent_amount = null;
$lease_start = $lease_end = null;
$vacant_since = null;
$looking_to_rent = null;
$expected_rent = null;

if ($status === 'owner') {
    $fm = param('family_members');
    if ($fm !== null && $fm !== '') {
        $family_members = max(1, min(30, (int) $fm));
    }

} elseif ($status === 'rented') {
    $tenant_name = clean(param('tenant_name'), 120);
    if ($tenant_name === null || str_len($tenant_name) < 2) {
        fail('Please enter the tenant name.');
    }

    $tm = param('tenant_mobile');
    if (!valid_mobile($tm)) {
        fail('Please enter a valid 10 digit tenant mobile number.');
    }
    $tenant_mobile = norm_mobile($tm);

    $tma = param('tenant_mobile_alt');
    if ($tma !== null && trim($tma) !== '') {
        if (!valid_mobile($tma)) {
            fail('The second tenant mobile number is not valid.');
        }
        $tenant_mobile_alt = norm_mobile($tma);
    }

    $tf = param('tenant_family');
    if ($tf !== null && $tf !== '') {
        $tenant_family = max(1, min(30, (int) $tf));
    }

    $ra = param('rent_amount');
    if ($ra !== null && $ra !== '') {
        $ra = (float) preg_replace('/[^0-9.]/', '', $ra);
        if ($ra > 0) $rent_amount = min($ra, 9999999);
    }

    $ls = clean(param('lease_start'), 10);
    $le = clean(param('lease_end'), 10);
    if ($ls !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ls)) $lease_start = $ls;
    if ($le !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $le)) $lease_end = $le;

    if ($lease_start && $lease_end && $lease_end < $lease_start) {
        fail('The lease end date cannot be before the lease start date.');
    }

} else { /* vacant */
    $vacant_since = clean(param('vacant_since'), 40);

    $ltr = param('looking_to_rent');
    $looking_to_rent = ($ltr === '1' || $ltr === 1 || $ltr === true || $ltr === 'yes') ? 1 : 0;

    if ($looking_to_rent === 1) {
        $er = param('expected_rent');
        if ($er !== null && $er !== '') {
            $er = (float) preg_replace('/[^0-9.]/', '', $er);
            if ($er > 0) $expected_rent = min($er, 9999999);
        }
    }
}

$notes = clean(param('notes'), 1000);
$lang  = param('lang') === 'te' ? 'te' : 'en';

/* ---------- Duplicate guard ---------- */
$st = db()->prepare(
    'SELECT id FROM submissions
     WHERE flat_id = ? AND owner_mobile = ? AND review_state = "pending"
       AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
     LIMIT 1'
);
$st->execute([$flat_id, $owner_mobile]);
if ($st->fetch()) {
    fail('We already received your details for this flat a moment ago. The committee will review them shortly.', 409);
}

/* ---------- Insert ---------- */
$st = db()->prepare(
    'INSERT INTO submissions
     (flat_id, owner_name, owner_mobile, owner_mobile_alt, owner_email,
      vehicle_count, vehicle_1, vehicle_2, vehicle_3,
      status, family_members,
      tenant_name, tenant_mobile, tenant_mobile_alt, tenant_family,
      rent_amount, lease_start, lease_end,
      vacant_since, looking_to_rent, expected_rent,
      notes, submitted_ip, submitted_lang)
     VALUES (?,?,?,?,?, ?,?,?,?, ?,?, ?,?,?,?, ?,?,?, ?,?,?, ?,?,?)'
);

$st->execute([
    $flat_id, $owner_name, $owner_mobile, $owner_mobile_alt, $owner_email,
    $vcount, $v1, $v2, $v3,
    $status, $family_members,
    $tenant_name, $tenant_mobile, $tenant_mobile_alt, $tenant_family,
    $rent_amount, $lease_start, $lease_end,
    $vacant_since, $looking_to_rent, $expected_rent,
    $notes, $ip, $lang,
]);

$sub_id = (int) db()->lastInsertId();

/* Record for rate limiting */
$st = db()->prepare('INSERT INTO form_submits (ip_address, flat_id) VALUES (?, ?)');
$st->execute([$ip, $flat_id]);

/* Housekeeping */
if (random_int(1, 30) === 1) {
    db()->exec('DELETE FROM form_submits WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)');
}

log_activity(null, 'form_submitted', 'submission', $sub_id, 'Flat ' . $flat['flat_code']);

ok([
    'submission_id' => $sub_id,
    'flat_no'       => $flat['flat_no'],
    'message'       => 'Thank you. Your details have been sent to the committee for review.',
]);
