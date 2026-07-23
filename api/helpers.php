<?php
/**
 * Shared helpers: JSON output, input parsing, auth, logging.
 */

/** Standard CORS + JSON headers. Call at top of every endpoint. */
function api_init() {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('X-Content-Type-Options: nosniff');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/** Emit JSON and stop. */
function json_out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ------------------------------------------------------------
   UTF-8 string helpers.
   mbstring is not installed on every shared host, so these fall
   back to byte-safe alternatives. Telugu text must survive both.
   ------------------------------------------------------------ */

/** Truncate to $len characters without splitting a UTF-8 sequence. */
function str_cut($s, $len) {
    if (function_exists('mb_substr')) return mb_substr($s, 0, $len, 'UTF-8');
    if (strlen($s) <= $len) return $s;
    $out = substr($s, 0, $len);
    while (strlen($out) > 0 && (ord($out[strlen($out) - 1]) & 0xC0) === 0x80) {
        $out = substr($out, 0, -1);
    }
    return $out;
}

/** Count characters, not bytes. */
function str_len($s) {
    if (function_exists('mb_strlen')) return mb_strlen($s, 'UTF-8');
    return strlen(preg_replace('/[\x80-\xBF]/', '', $s));
}

/**
 * Trim, collapse to null when empty, and cut to a character limit.
 */
function clean_txt($v, $len = 150) {
    if ($v === null) return null;
    $v = trim((string) $v);
    if ($v === '') return null;
    return str_cut($v, $len);
}

/** Validate an Indian mobile number and return it normalised, or null. */
function clean_mobile($m) {
    if ($m === null) return null;
    $d = preg_replace('/\D/', '', (string) $m);
    if (strlen($d) === 12 && substr($d, 0, 2) === '91') $d = substr($d, 2);
    return preg_match('/^[6-9]\d{9}$/', $d) ? $d : null;
}

/**
 * Build a vehicle list from a row containing vehicle_1..3 and
 * vehicle_1_type..3_type. Returns [{number, type, label}, ...].
 */
function vehicle_list($row) {
    $out = [];
    for ($i = 1; $i <= 3; $i++) {
        $num = $row['vehicle_' . $i] ?? null;
        if ($num === null || $num === '') continue;
        $type = $row['vehicle_' . $i . '_type'] ?? null;
        $out[] = [
            'number' => $num,
            'type'   => $type,
            'label'  => $type === 'four_wheeler' ? 'Four wheeler'
                      : ($type === 'two_wheeler' ? 'Two wheeler' : ''),
        ];
    }
    return $out;
}

function fail($msg, $code = 400, $extra = []) {
    json_out(array_merge(['ok' => false, 'error' => $msg], $extra), $code);
}

function ok($data = []) {
    json_out(array_merge(['ok' => true], $data));
}

/** Read JSON body, falling back to form POST. */
function body() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $raw = file_get_contents('php://input');
    $j   = json_decode($raw, true);
    $cache = is_array($j) ? $j : $_POST;
    return $cache;
}

function param($key, $default = null) {
    $b = body();
    if (isset($b[$key]))     return is_string($b[$key]) ? trim($b[$key]) : $b[$key];
    if (isset($_GET[$key]))  return trim($_GET[$key]);
    return $default;
}

function client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = explode(',', $_SERVER[$k])[0];
            return substr(trim($ip), 0, 45);
        }
    }
    return null;
}

function user_agent() {
    return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
}

/** Write an audit trail row. */
function log_activity($user_id, $action, $entity = null, $entity_id = null, $details = null) {
    try {
        $st = db()->prepare(
            'INSERT INTO activity_log (user_id, action, entity, entity_id, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$user_id, $action, $entity, $entity_id, $details, client_ip()]);
    } catch (Exception $e) {
        // logging must never break the request
    }
}

/** Extract bearer token from Authorization header. */
function bearer_token() {
    $hdr = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $hdr = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_REDIRECT_HTTP_AUTHORIZATION'])) {
        $hdr = $_SERVER['REDIRECT_REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        foreach ($h as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $hdr = $v; break; }
        }
    }
    if (preg_match('/Bearer\s+(\S+)/i', $hdr, $m)) return $m[1];
    // fallback for hosts that strip the Authorization header entirely
    return param('token');
}

/**
 * Validate session token. Returns user row or null.
 */
function current_user() {
    static $u = null;
    static $checked = false;
    if ($checked) return $u;
    $checked = true;

    $tok = bearer_token();
    if (!$tok) return null;

    $hash = hash('sha256', $tok);

    /* The flat columns only exist after sql/06_resident_app.sql.
       Detect once so sessions still work on an un-migrated database. */
    static $has_flat = null;
    if ($has_flat === null) {
        try {
            $has_flat = (bool) db()->query("SHOW COLUMNS FROM users LIKE 'flat_id'")->fetch();
        } catch (Exception $e) {
            $has_flat = false;
        }
    }

    $cols = $has_flat
        ? 'u.flat_id, u.resident_type,'
        : 'NULL AS flat_id, NULL AS resident_type,';

    $st = db()->prepare(
        'SELECT u.id, u.name, u.email, u.username, u.mobile, u.role, u.designation,
                u.status, u.photo_url, u.must_change_pwd, ' . $cols . '
                s.id AS session_id
         FROM sessions s
         JOIN users u ON u.id = s.user_id
         WHERE s.token_hash = ?
           AND s.revoked_at IS NULL
           AND s.expires_at > NOW()
         LIMIT 1'
    );
    $st->execute([$hash]);
    $row = $st->fetch();

    if (!$row || $row['status'] !== 'active') return null;
    $u = $row;
    return $u;
}

/** Require a logged in user, optionally restricted to given roles. */
function require_auth($roles = null) {
    $u = current_user();
    if (!$u) fail('Unauthorized. Please log in again.', 401);
    if ($roles !== null) {
        $roles = (array) $roles;
        if (!in_array($u['role'], $roles, true)) {
            fail('You do not have permission for this action.', 403);
        }
    }
    return $u;
}

/** Admin-level shortcut. */
function require_admin() {
    return require_auth(['super_admin', 'admin']);
}

/** Resident shortcut. Guarantees the user has a flat linked. */
function require_resident() {
    $u = require_auth(['resident']);
    if (empty($u['flat_id'])) {
        fail('Your account is not linked to a flat. Please contact the committee.', 403);
    }
    return $u;
}

/** Guard (gate) shortcut. Admins may also use gate screens. */
function require_guard() {
    return require_auth(['guard', 'super_admin', 'admin']);
}

/** True if the user is a committee member. */
function is_admin($u) {
    return $u && in_array($u['role'], ['super_admin', 'admin'], true);
}

/** Read a settings value. */
function setting($key, $default = null) {
    try {
        $st = db()->prepare('SELECT key_value FROM settings WHERE key_name = ? LIMIT 1');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null) ? $default : $v;
    } catch (Exception $e) {
        return $default;
    }
}
