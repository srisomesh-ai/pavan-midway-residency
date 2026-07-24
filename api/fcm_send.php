<?php
/**
 * fcm_send.php - Firebase Cloud Messaging (HTTP v1) sender.
 *
 * Ported from the BharatGPS TaskManager implementation, adapted so one
 * person can have several devices (phone, tablet, desktop browser).
 *
 * Reads the service-account key from public_html/fcm-key.json, one level
 * above /api. Uses only cURL and openssl - no Composer, no SDK.
 *
 * Every function is a safe no-op when the key file is missing, so the app
 * keeps working normally until Firebase is set up.
 */

if (!function_exists('fcm_key_path')) {
    function fcm_key_path() {
        return __DIR__ . '/../fcm-key.json';
    }
}

if (!function_exists('fcm_ready')) {
    /** True once fcm-key.json is in place and the server can actually send. */
    function fcm_ready() {
        static $ok = null;
        if ($ok !== null) return $ok;

        /* Both are needed to talk to Google. Some minimal PHP builds lack cURL. */
        if (!function_exists('curl_init') || !function_exists('openssl_sign')) {
            $ok = false;
            return $ok;
        }

        $p = fcm_key_path();
        if (!is_readable($p)) { $ok = false; return $ok; }
        $k = json_decode((string) file_get_contents($p), true);
        $ok = (bool) ($k && !empty($k['client_email']) && !empty($k['private_key']) && !empty($k['project_id']));
        return $ok;
    }
}

if (!function_exists('fcm_missing_reason')) {
    /** Why push is unavailable, for the diagnostics page. */
    function fcm_missing_reason() {
        if (!function_exists('curl_init'))   return 'PHP cURL extension is not enabled on this server.';
        if (!function_exists('openssl_sign')) return 'PHP OpenSSL extension is not enabled on this server.';
        $p = fcm_key_path();
        if (!is_readable($p)) return 'fcm-key.json is not in public_html yet.';
        $k = json_decode((string) file_get_contents($p), true);
        if (!$k) return 'fcm-key.json is not valid JSON.';
        foreach (['client_email','private_key','project_id'] as $f) {
            if (empty($k[$f])) return 'fcm-key.json is missing "' . $f . '".';
        }
        return null;
    }
}

if (!function_exists('fcm_base64url')) {
    function fcm_base64url($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

/**
 * OAuth2 access token via the service-account JWT bearer flow.
 * Cached in a temp file until shortly before expiry so we do not
 * re-sign on every notification.
 */
if (!function_exists('fcm_get_access_token')) {
    function fcm_get_access_token() {
        $keyPath = fcm_key_path();
        if (!is_readable($keyPath)) return null;
        $key = json_decode((string) file_get_contents($keyPath), true);
        if (!$key || empty($key['client_email']) || empty($key['private_key'])) return null;

        $cacheFile = sys_get_temp_dir() . '/pmr_fcm_tok_' . md5($key['client_email']) . '.json';
        if (is_readable($cacheFile)) {
            $c = json_decode((string) file_get_contents($cacheFile), true);
            if ($c && !empty($c['access_token']) && !empty($c['exp']) && $c['exp'] > time() + 300) {
                return $c['access_token'];
            }
        }

        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claim  = [
            'iss'   => $key['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $signingInput = fcm_base64url(json_encode($header)) . '.' . fcm_base64url(json_encode($claim));
        $sig = '';
        if (!openssl_sign($signingInput, $sig, $key['private_key'], 'sha256WithRSAEncryption')) {
            return null;
        }
        $jwt = $signingInput . '.' . fcm_base64url($sig);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code < 200 || $code >= 300) {
            error_log('FCM token request failed (' . $code . '): ' . substr((string) $resp, 0, 300));
            return null;
        }

        $j = json_decode((string) $resp, true);
        if (empty($j['access_token'])) return null;

        @file_put_contents($cacheFile, json_encode([
            'access_token' => $j['access_token'],
            'exp'          => $now + (int) ($j['expires_in'] ?? 3600),
        ]));

        return $j['access_token'];
    }
}

/**
 * Send to one device token.
 * Returns true on success. Sets $GLOBALS['_fcm_last'] for diagnostics.
 */
if (!function_exists('fcm_send_to_token')) {
    function fcm_send_to_token($fcmToken, $title, $body, $data = []) {
        if (!$fcmToken) return false;
        if (!fcm_ready()) return false;

        $key = json_decode((string) file_get_contents(fcm_key_path()), true);
        $projectId = $key['project_id'] ?? null;
        if (!$projectId) return false;

        $accessToken = fcm_get_access_token();
        if (!$accessToken) return false;

        /* FCM requires every data value to be a string */
        $strData = [];
        foreach ($data as $k => $v) {
            if ($v === null) continue;
            $strData[(string) $k] = (string) $v;
        }

        $link = $strData['link'] ?? 'index.html';

        $message = [
            'message' => [
                'token' => $fcmToken,
                'notification' => [
                    'title' => (string) $title,
                    'body'  => (string) $body,
                ],
                'data' => $strData,
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'channel_id' => 'pmr_alerts',
                        'sound'      => 'default',
                    ],
                ],
                /* Web push, for residents who installed the site to their home screen */
                'webpush' => [
                    'headers' => ['Urgency' => 'high'],
                    'notification' => [
                        'icon'  => '/assets/icon-192.png',
                        'badge' => '/assets/icon-192.png',
                    ],
                    'fcm_options' => ['link' => $link],
                ],
            ],
        ];

        $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($message),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $GLOBALS['_fcm_last'] = ['code' => $code, 'body' => substr((string) $resp, 0, 400)];

        if ($code < 200 || $code >= 300) {
            error_log('FCM send failed (' . $code . '): ' . substr((string) $resp, 0, 300));

            /* A token that is gone for good should be removed so we stop retrying it */
            if ($code === 404 || $code === 400) {
                $b = (string) $resp;
                if (strpos($b, 'UNREGISTERED') !== false || strpos($b, 'INVALID_ARGUMENT') !== false) {
                    try {
                        db()->prepare('DELETE FROM push_tokens WHERE token = ?')->execute([$fcmToken]);
                    } catch (Throwable $e) { /* ignore */ }
                }
            }
            return false;
        }

        return true;
    }
}

/**
 * Send to every device belonging to one user.
 * Never throws - a notification failure must not break the action that caused it.
 */
if (!function_exists('fcm_send_to_user')) {
    function fcm_send_to_user($userId, $title, $body, $data = []) {
        if (!$userId || !fcm_ready()) return 0;
        $sent = 0;
        try {
            $st = db()->prepare('SELECT token FROM push_tokens WHERE user_id = ?');
            $st->execute([(int) $userId]);
            foreach ($st->fetchAll() as $r) {
                if (fcm_send_to_token($r['token'], $title, $body, $data)) $sent++;
            }
        } catch (Throwable $e) {
            error_log('fcm_send_to_user: ' . $e->getMessage());
        }
        return $sent;
    }
}
