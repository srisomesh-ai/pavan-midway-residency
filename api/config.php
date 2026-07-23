<?php
/**
 * Pavan Midway Residency - Core config
 *
 * THIS FILE IS OVERWRITTEN ON EVERY DEPLOYMENT.
 * Never put real credentials here.
 *
 * Real database details belong in  config.local.php  (gitignored).
 * Copy config.sample.php to config.local.php and edit that.
 */

// ---------- LOAD LOCAL CREDENTIALS ----------
$__local = __DIR__ . '/config.local.php';

if (!file_exists($__local)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Setup incomplete: api/config.local.php is missing. Copy api/config.sample.php to api/config.local.php and enter your database details.'
    ]);
    exit;
}

require_once $__local;

// ---------- DEFAULTS ----------
// config.local.php may define any of these first; defined() keeps its value.
if (!defined('DEBUG'))           define('DEBUG', false);
if (!defined('SESSION_DAYS'))    define('SESSION_DAYS', 30);
if (!defined('MAX_ATTEMPTS'))    define('MAX_ATTEMPTS', 5);
if (!defined('LOCKOUT_MINUTES')) define('LOCKOUT_MINUTES', 15);
if (!defined('DB_HOST'))         define('DB_HOST', 'localhost');

define('APP_NAME', 'Pavan Midway Residency');

// ---------- SANITY CHECK ----------
if (!defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS')) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Setup incomplete: DB_NAME, DB_USER or DB_PASS missing from api/config.local.php.'
    ]);
    exit;
}

// ---------- ERROR HANDLING ----------
if (DEBUG) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

date_default_timezone_set('Asia/Kolkata');

/**
 * PDO connection (singleton)
 */
function db() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            echo json_encode([
                'ok'    => false,
                'error' => DEBUG ? ('Database connection failed: ' . $e->getMessage())
                                 : 'Database connection failed. Check the credentials in api/config.local.php.'
            ]);
            exit;
        }
    }
    return $pdo;
}
