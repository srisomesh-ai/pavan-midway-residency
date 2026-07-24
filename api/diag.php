<?php
/**
 * GET /api/diag.php
 *
 * Troubleshooting helper. Open this directly in a browser to see whether
 * the server is configured correctly. Reports NO sensitive values -
 * passwords and tokens are never echoed, only whether they arrived.
 *
 * Safe to leave in place, but you can delete it once everything works.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

api_init();

$checks = [];

/* ---- PHP ---- */
$checks['php_version'] = PHP_VERSION;
$checks['pdo_mysql']   = extension_loaded('pdo_mysql');
$checks['json']        = extension_loaded('json');

/* ---- Database ---- */
try {
    $d = db();
    $checks['db_connected'] = true;

    $need = ['blocks','flats','users','user_flats','sessions','login_attempts','activity_log','settings'];
    $need_form = ['submissions','flat_details','form_submits'];
    $need_app  = ['visitors','preapproved_visitors','away_notices','complaints','complaint_replies'];
    $need_notify = ['notifications','notices','push_tokens'];
    $need_gate   = ['gate_submits'];
    $have = [];
    foreach ($d->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $r) {
        $have[] = $r[0];
    }
    $checks['tables_found']   = count($have);
    $checks['tables_missing'] = array_values(array_diff($need, $have));

    $missing_form = array_values(array_diff($need_form, $have));
    $checks['resident_form_ready'] = empty($missing_form);
    if (!empty($missing_form)) {
        $checks['resident_form_missing'] = $missing_form;
    }

    $missing_app = array_values(array_diff($need_app, $have));
    $checks['resident_app_ready'] = empty($missing_app);
    if (!empty($missing_app)) {
        $checks['resident_app_missing'] = $missing_app;
    }

    $missing_notify = array_values(array_diff($need_notify, $have));
    $checks['notifications_ready'] = empty($missing_notify);
    if (!empty($missing_notify)) {
        $checks['notifications_missing'] = $missing_notify;
    }

    $missing_gate = array_values(array_diff($need_gate, $have));
    $checks['gate_page_ready'] = empty($missing_gate);
    if (!empty($missing_gate)) {
        $checks['gate_page_missing'] = $missing_gate;
    }

    if (empty($missing_notify)) {
        $checks['notifications_sent'] = (int) $d->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
        $checks['notices_posted']     = (int) $d->query('SELECT COUNT(*) FROM notices')->fetchColumn();
    }

    if (empty($missing_app)) {
        $checks['resident_logins'] = (int) $d->query(
            'SELECT COUNT(*) FROM users WHERE role = "resident" AND status = "active"'
        )->fetchColumn();
        $checks['guard_logins'] = (int) $d->query(
            'SELECT COUNT(*) FROM users WHERE role = "guard" AND status = "active"'
        )->fetchColumn();
        $checks['open_tickets'] = (int) $d->query(
            'SELECT COUNT(*) FROM complaints WHERE status IN ("open","in_progress")'
        )->fetchColumn();
    }

    if (empty($missing_form)) {
        $checks['submissions_pending'] = (int) $d->query(
            'SELECT COUNT(*) FROM submissions WHERE review_state = "pending"'
        )->fetchColumn();
        $checks['details_collected'] = (int) $d->query('SELECT COUNT(*) FROM flat_details')->fetchColumn();

        $fdcols = [];
        foreach ($d->query('SHOW COLUMNS FROM flat_details')->fetchAll() as $c) {
            $fdcols[] = $c['Field'];
        }
        $checks['flats_has_vehicle_type'] = in_array('vehicle_1_type', $fdcols, true);
    }

    if (in_array('flats', $have, true)) {
        $checks['flat_count'] = (int) $d->query('SELECT COUNT(*) FROM flats')->fetchColumn();
    }
    if (in_array('blocks', $have, true)) {
        $checks['block_count'] = (int) $d->query('SELECT COUNT(*) FROM blocks')->fetchColumn();
    }
    if (in_array('users', $have, true)) {
        $checks['admin_exists'] = (bool) $d->query(
            'SELECT COUNT(*) FROM users WHERE role IN ("super_admin","admin")'
        )->fetchColumn();
    }
    if (in_array('sessions', $have, true)) {
        $checks['active_sessions'] = (int) $d->query(
            'SELECT COUNT(*) FROM sessions WHERE revoked_at IS NULL AND expires_at > NOW()'
        )->fetchColumn();
    }

    /* Confirm the new flat structure columns exist */
    if (in_array('flats', $have, true)) {
        $cols = [];
        foreach ($d->query('SHOW COLUMNS FROM flats')->fetchAll() as $c) {
            $cols[] = $c['Field'];
        }
        $checks['flats_has_flat_code'] = in_array('flat_code', $cols, true);
    }

} catch (Exception $e) {
    $checks['db_connected'] = false;
    $checks['db_error'] = DEBUG ? $e->getMessage() : 'hidden (set DEBUG true to see)';
}

/* ---- Authorization header ---- */
$auth_sources = [
    'HTTP_AUTHORIZATION'                   => isset($_SERVER['HTTP_AUTHORIZATION']),
    'REDIRECT_HTTP_AUTHORIZATION'          => isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']),
    'REDIRECT_REDIRECT_HTTP_AUTHORIZATION' => isset($_SERVER['REDIRECT_REDIRECT_HTTP_AUTHORIZATION']),
    'apache_request_headers'               => function_exists('apache_request_headers'),
];
$checks['auth_header_sources'] = $auth_sources;

$tok = bearer_token();
$checks['token_received'] = $tok !== null && $tok !== '';
$checks['token_source']   = $tok
    ? (isset($_GET['token']) ? 'url_parameter' : 'authorization_header')
    : 'none';

/* If a token arrived, does it resolve to a user? */
if ($tok) {
    $u = current_user();
    $checks['token_valid'] = $u !== null;
    if ($u) {
        $checks['logged_in_as'] = $u['username'];
        $checks['role'] = $u['role'];
    }
}

/* ---- Push notifications ---- */
$checks['php_curl']    = function_exists('curl_init');
$checks['php_openssl'] = function_exists('openssl_sign');
$checks['push_ready']  = function_exists('fcm_ready') ? fcm_ready() : false;
if (!$checks['push_ready'] && function_exists('fcm_missing_reason')) {
    $checks['push_blocked_by'] = fcm_missing_reason();
}
try {
    if (in_array('push_tokens', $have ?? [], true)) {
        $checks['devices_registered'] = (int) db()->query('SELECT COUNT(*) FROM push_tokens')->fetchColumn();
    }
} catch (Exception $e) {}

/* ---- Server ---- */
$checks['server_software'] = $_SERVER['SERVER_SOFTWARE'] ?? 'unknown';
$checks['sapi']            = PHP_SAPI;
$checks['mod_rewrite']     = function_exists('apache_get_modules')
    ? in_array('mod_rewrite', apache_get_modules(), true)
    : 'cannot detect';

/* ---- Verdict ---- */
$problems = [];
if (empty($checks['db_connected']))                  $problems[] = 'Database is not connecting. Check api/config.local.php.';
if (!empty($checks['tables_missing']))               $problems[] = 'Missing tables: ' . implode(', ', $checks['tables_missing']) . '. Import sql/01_schema.sql.';
if (isset($checks['flat_count']) && $checks['flat_count'] !== 140) $problems[] = 'Expected 140 flats, found ' . $checks['flat_count'] . '. Run sql/03_migrate_flat_structure.sql then sql/02_seed.sql.';
if (isset($checks['flats_has_flat_code']) && !$checks['flats_has_flat_code']) $problems[] = 'The flats table is missing flat_code. Run sql/03_migrate_flat_structure.sql.';
if (empty($checks['admin_exists']))                  $problems[] = 'No admin user found. Import sql/02_seed.sql.';
if (isset($checks['resident_form_ready']) && !$checks['resident_form_ready']) {
    $problems[] = 'The resident form will not work yet. Import sql/04_resident_form.sql to create the '
                . implode(', ', $checks['resident_form_missing']) . ' table(s).';
}
if (isset($checks['resident_app_ready']) && !$checks['resident_app_ready']) {
    $problems[] = 'The resident app will not work yet. Import sql/06_resident_app.sql to create the '
                . implode(', ', $checks['resident_app_missing']) . ' table(s).';
}
if (isset($checks['notifications_ready']) && !$checks['notifications_ready']) {
    $problems[] = 'Notifications will not appear. Import sql/07_notifications.sql to create the '
                . implode(', ', $checks['notifications_missing']) . ' table(s).';
}
if (isset($checks['gate_page_ready']) && !$checks['gate_page_ready']) {
    $problems[] = 'The visitor QR page will not work. Import sql/08_open_gate.sql.';
}
if (isset($checks['flats_has_vehicle_type']) && !$checks['flats_has_vehicle_type']) {
    $problems[] = 'Vehicle types are missing. Import sql/05_vehicle_types.sql.';
}

ok([
    'checks'   => $checks,
    'problems' => $problems,
    'verdict'  => empty($problems) ? 'All checks passed.' : 'Problems found - see the list above.',
]);
