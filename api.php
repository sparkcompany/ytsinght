<?php
/**
 * YTInsight - Firebase Configuration Backend
 * config.php — Server-side only, never exposed to frontend JS
 *
 * Usage:
 * POST /config.php?action=get_config   → returns Firebase config as JSON (for SDK init)
 * POST /config.php?action=verify       → verifies a Firebase ID token server-side
 *
 * IMPORTANT: Place this file on your server and set correct CORS origin.
 * NEVER include your Firebase keys directly in frontend HTML/JS.
 */

// ─────────────────────────────────────────────
// CONFIGURATION — Edit these values
// ─────────────────────────────────────────────
define('ALLOWED_ORIGIN', 'https://ytinsight.site'); // Your domain

// Firebase Project Config (Updated with your provided configuration)
define('FB_API_KEY',          'AIzaSyCzZ-6KQtTbz8eAdACZ9KSYVKa0ATAAg-I');
define('FB_AUTH_DOMAIN',      'insight-4264d.firebaseapp.com');
define('FB_PROJECT_ID',       'insight-4264d');
define('FB_STORAGE_BUCKET',   'insight-4264d.firebasestorage.app');
define('FB_MESSAGING_SENDER', '349243473589');
define('FB_APP_ID',           '1:349243473589:web:d7169e82d1129aa23cc8fc');
define('FB_DATABASE_URL',     'https://insight-4264d-default-rtdb.firebaseio.com');

// Admin / Server Key (from Firebase Console → Project Settings → Service Accounts)
// Used for server-side token verification
define('FB_SERVER_KEY',       'YOUR_SERVER_KEY_HERE');

// Secret key to protect this endpoint (add ?secret=THIS_KEY from your frontend fetch)
define('ENDPOINT_SECRET',     'ytinsight_secret_2025_xyz');

// ─────────────────────────────────────────────
// CORS & Headers
// ─────────────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === ALLOWED_ORIGIN) {
    header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
}
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Content-Type: application/json');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─────────────────────────────────────────────
// Secret Verification
// ─────────────────────────────────────────────
$providedSecret = $_GET['secret'] ?? $_POST['secret'] ?? '';
if ($providedSecret !== ENDPOINT_SECRET) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ─────────────────────────────────────────────
// Action Router
// ─────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    // ── Return Firebase Config for SDK initialization ──
    case 'get_config':
        echo json_encode([
            'success' => true,
            'config'  => [
                'apiKey'            => FB_API_KEY,
                'authDomain'        => FB_AUTH_DOMAIN,
                'projectId'         => FB_PROJECT_ID,
                'storageBucket'     => FB_STORAGE_BUCKET,
                'messagingSenderId' => FB_MESSAGING_SENDER,
                'appId'             => FB_APP_ID,
                'databaseURL'       => FB_DATABASE_URL,
            ]
        ]);
        break;

    // ── Verify Firebase ID Token (server-side) ──
    case 'verify':
        $body    = json_decode(file_get_contents('php://input'), true);
        $idToken = $body['idToken'] ?? '';
        if (!$idToken) {
            http_response_code(400);
            echo json_encode(['error' => 'ID token missing']);
            exit;
        }

        // Call Firebase REST API to verify token
        $url = 'https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=' . FB_API_KEY;
        $ch  = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['idToken' => $idToken]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);

        if ($httpCode === 200 && isset($result['users'][0])) {
            $user = $result['users'][0];
            echo json_encode([
                'success' => true,
                'uid'     => $user['localId'],
                'email'   => $user['email'] ?? '',
                'name'    => $user['displayName'] ?? '',
            ]);
        } else {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid token', 'detail' => $result]);
        }
        break;

    // ── Log user activity to Firebase RTDB ──
    case 'log_activity':
        $body  = json_decode(file_get_contents('php://input'), true);
        $uid   = preg_replace('/[^a-zA-Z0-9_-]/', '', $body['uid'] ?? '');
        $event = $body['event'] ?? 'unknown';
        $meta  = $body['meta'] ?? [];

        if (!$uid) {
            http_response_code(400);
            echo json_encode(['error' => 'UID required']);
            exit;
        }

        $timestamp = date('c'); // ISO 8601
        $logEntry  = [
            'event'     => htmlspecialchars($event),
            'timestamp' => $timestamp,
            'meta'      => $meta,
        ];

        // Write to Firebase RTDB via REST
        $rtdbUrl = FB_DATABASE_URL . '/users/' . $uid . '/activity/' . time() . '.json?auth=' . FB_SERVER_KEY;
        $ch = curl_init($rtdbUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($logEntry));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            echo json_encode(['success' => true, 'logged' => $event]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'RTDB write failed', 'http' => $httpCode]);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action. Use: get_config, verify, log_activity']);
}
?>
