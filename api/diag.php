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
    $have = [];
    foreach ($d->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $r) {
        $have[] = $r[0];
    }
    $checks['tables_found']   = count($have);
    $checks['tables_missing'] = array_values(array_diff($need, $have));

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

ok([
    'checks'   => $checks,
    'problems' => $problems,
    'verdict'  => empty($problems) ? 'All checks passed.' : 'Problems found - see the list above.',
]);
