<?php
/**
 * Pavan Midway Residency - Core config
 * EDIT THE DB CREDENTIALS BELOW after creating the database in hPanel.
 */

// ---------- DATABASE ----------
define('DB_HOST', 'localhost');
define('DB_NAME', 'u000000000_pmr');       // <-- change
define('DB_USER', 'u000000000_pmradmin');  // <-- change
define('DB_PASS', 'CHANGE_ME');            // <-- change

// ---------- APP ----------
define('APP_NAME',        'Pavan Midway Residency');
define('SESSION_DAYS',    30);
define('MAX_ATTEMPTS',    5);
define('LOCKOUT_MINUTES', 15);

// ---------- ERROR HANDLING ----------
// Set to false on production
define('DEBUG', false);

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
            json_out(['ok' => false, 'error' => 'Database connection failed'], 500);
        }
    }
    return $pdo;
}
