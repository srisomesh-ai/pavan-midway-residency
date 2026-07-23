<?php
/**
 * Pavan Midway Residency - Credentials TEMPLATE
 *
 * DO NOT PUT REAL CREDENTIALS IN THIS FILE.
 * This file is tracked by Git and gets overwritten on every deployment.
 *
 * SETUP (once, on the server):
 *   1. Copy this file and rename the copy to  config.local.php
 *   2. Put your real database details in config.local.php
 *   3. config.local.php is gitignored, so deployments will never touch it again
 */

// ---------- DATABASE ----------
define('DB_HOST', 'localhost');
define('DB_NAME', 'u000000000_pmr');       // your database name
define('DB_USER', 'u000000000_pmradmin');  // your database user
define('DB_PASS', 'CHANGE_ME');            // your database password

// ---------- OPTIONAL OVERRIDES ----------
// Uncomment any of these to override the defaults in config.php.

// define('DEBUG', true);            // show errors while troubleshooting
// define('SESSION_DAYS', 30);       // how long "keep me signed in" lasts
// define('MAX_ATTEMPTS', 5);        // failed logins before lockout
// define('LOCKOUT_MINUTES', 15);    // lockout duration
