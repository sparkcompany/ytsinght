 <?php
/**
 * YTInsight SaaS Backend API
 * Handles: lead capture, user auth, user monitoring, team management
 * Serves: secure Firebase config (never exposed in frontend HTML)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Database Config ──────────────────────────────────────────────────────────
// Update these credentials with your MySQL/MariaDB details
define('DB_HOST', 'localhost');
define('DB_NAME', 'ytinsight_db');
define('DB_USER', 'ytinsight_user');
define('DB_PASS', 'your_secure_password_here');

// ── Firebase Config (stored securely server-side) ────────────────────────────
define('FIREBASE_CONFIG', [
    'apiKey'            => 'AIzaSyCdrPLotWyFJXsRcBJrga91LbiAXGicbpY',
    'authDomain'        => 'spaintoearn.firebaseapp.com',
    'databaseURL'       => 'https://spaintoearn-default-rtdb.firebaseio.com',
    'projectId'         => 'spaintoearn',
    'storageBucket'     => 'spaintoearn.firebasestorage.app',
    'messagingSenderId' => '77653986140',
    'appId'             => '1:77653986140:web:4a3b0f1895ddad1664cf99',
    'measurementId'     => 'G-BJE7GM34DJ'
]);

// ── Admin Secret (change this!) ───────────────────────────────────────────────
define('ADMIN_SECRET', 'ytinsight_admin_2025_secret_key');

// ── Database Connection ───────────────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $e) {
            respond(500, ['error' => 'Database connection failed']);
        }
    }
    return $pdo;
}

// ── Response Helper ───────────────────────────────────────────────────────────
function respond(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// ── Input Sanitization ────────────────────────────────────────────────────────
function clean(string $val): string {
    return htmlspecialchars(strip_tags(trim($val)), ENT_QUOTES, 'UTF-8');
}

// ── Initialize Tables ─────────────────────────────────────────────────────────
function initDB(): void {
    $db = getDB();
    $db->exec("
        CREATE TABLE IF NOT EXISTS leads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(200) NOT NULL,
            whatsapp VARCHAR(20) NOT NULL,
            plan VARCHAR(20) DEFAULT 'free',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ip_address VARCHAR(45),
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            firebase_uid VARCHAR(128) UNIQUE NOT NULL,
            name VARCHAR(120),
            email VARCHAR(200) NOT NULL,
            whatsapp VARCHAR(20),
            plan ENUM('free','paid') DEFAULT 'free',
            plan_expires_at TIMESTAMP NULL,
            is_active TINYINT(1) DEFAULT 1,
            last_login TIMESTAMP NULL,
            login_count INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_firebase_uid (firebase_uid),
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS team_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            owner_firebase_uid VARCHAR(128) NOT NULL,
            member_email VARCHAR(200) NOT NULL,
            member_name VARCHAR(120),
            role ENUM('editor','viewer','admin') DEFAULT 'editor',
            invited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            accepted TINYINT(1) DEFAULT 0,
            INDEX idx_owner (owner_firebase_uid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            firebase_uid VARCHAR(128),
            action VARCHAR(100),
            meta TEXT,
            ip_address VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_uid (firebase_uid),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

// ── Route Handler ─────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $action ?: ($body['action'] ?? '');

// Initialize tables on first run
try { initDB(); } catch (Throwable $e) { /* tables may already exist */ }

switch ($action) {

    // ── Public: Serve Firebase Config ────────────────────────────────────────
    case 'firebase_config':
        // Only serve to verified origin (optional: add origin check)
        respond(200, ['config' => FIREBASE_CONFIG]);
        break;

    // ── Public: Submit Lead / Get Started form ───────────────────────────────
    case 'submit_lead':
        $name     = clean($body['name'] ?? '');
        $email    = filter_var($body['email'] ?? '', FILTER_SANITIZE_EMAIL);
        $whatsapp = clean($body['whatsapp'] ?? '');

        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$whatsapp) {
            respond(400, ['error' => 'Name, valid email, and WhatsApp are required']);
        }

        try {
            $db = getDB();
            // Upsert: update if email exists
            $stmt = $db->prepare("
                INSERT INTO leads (name, email, whatsapp, ip_address)
                VALUES (:name, :email, :whatsapp, :ip)
                ON DUPLICATE KEY UPDATE name=:name, whatsapp=:whatsapp
            ");
            $stmt->execute([
                ':name'     => $name,
                ':email'    => $email,
                ':whatsapp' => $whatsapp,
                ':ip'       => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            respond(200, ['success' => true, 'message' => "Welcome, $name! We'll contact you on WhatsApp shortly."]);
        } catch (Throwable $e) {
            respond(500, ['error' => 'Could not save lead: ' . $e->getMessage()]);
        }
        break;

    // ── Auth: Sync user on Firebase login ────────────────────────────────────
    case 'sync_user':
        $uid   = clean($body['uid'] ?? '');
        $name  = clean($body['name'] ?? '');
        $email = filter_var($body['email'] ?? '', FILTER_SANITIZE_EMAIL);
        $phone = clean($body['whatsapp'] ?? '');

        if (!$uid || !$email) {
            respond(400, ['error' => 'uid and email required']);
        }

        try {
            $db = getDB();
            $stmt = $db->prepare("
                INSERT INTO users (firebase_uid, name, email, whatsapp, last_login, login_count)
                VALUES (:uid, :name, :email, :phone, NOW(), 1)
                ON DUPLICATE KEY UPDATE
                    last_login = NOW(),
                    login_count = login_count + 1,
                    name = IF(:name != '', :name, name)
            ");
            $stmt->execute([':uid' => $uid, ':name' => $name, ':email' => $email, ':phone' => $phone]);

            // Get user plan
            $stmt2 = $db->prepare("SELECT plan, plan_expires_at, is_active FROM users WHERE firebase_uid = ?");
            $stmt2->execute([$uid]);
            $user = $stmt2->fetch();

            // Log activity
            $db->prepare("INSERT INTO activity_log (firebase_uid, action, ip_address) VALUES (?, 'login', ?)")
               ->execute([$uid, $_SERVER['REMOTE_ADDR'] ?? 'unknown']);

            respond(200, ['success' => true, 'user' => $user]);
        } catch (Throwable $e) {
            respond(500, ['error' => $e->getMessage()]);
        }
        break;

    // ── Auth: Get user plan/status ────────────────────────────────────────────
    case 'get_user':
        $uid = clean($body['uid'] ?? $_GET['uid'] ?? '');
        if (!$uid) respond(400, ['error' => 'uid required']);

        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT plan, plan_expires_at, is_active, name, email FROM users WHERE firebase_uid = ?");
            $stmt->execute([$uid]);
            $user = $stmt->fetch();

            if (!$user) respond(404, ['error' => 'User not found']);

            // Check plan expiry
            if ($user['plan'] === 'paid' && $user['plan_expires_at'] && strtotime($user['plan_expires_at']) < time()) {
                $db->prepare("UPDATE users SET plan = 'free' WHERE firebase_uid = ?")->execute([$uid]);
                $user['plan'] = 'free';
            }

            respond(200, $user);
        } catch (Throwable $e) {
            respond(500, ['error' => $e->getMessage()]);
        }
        break;

    // ── Team: Add member ──────────────────────────────────────────────────────
    case 'add_team_member':
        $ownerUid    = clean($body['owner_uid'] ?? '');
        $memberEmail = filter_var($body['member_email'] ?? '', FILTER_SANITIZE_EMAIL);
        $memberName  = clean($body['member_name'] ?? '');
        $role        = in_array($body['role'] ?? '', ['editor','viewer','admin']) ? $body['role'] : 'editor';

        if (!$ownerUid || !filter_var($memberEmail, FILTER_VALIDATE_EMAIL)) {
            respond(400, ['error' => 'owner_uid and valid member_email required']);
        }

        // Verify owner is on paid plan
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT plan FROM users WHERE firebase_uid = ?");
            $stmt->execute([$ownerUid]);
            $owner = $stmt->fetch();

            if (!$owner || $owner['plan'] !== 'paid') {
                respond(403, ['error' => 'Team features require a paid plan']);
            }

            $stmt2 = $db->prepare("
                INSERT INTO team_members (owner_firebase_uid, member_email, member_name, role)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE role = ?, member_name = ?
            ");
            $stmt2->execute([$ownerUid, $memberEmail, $memberName, $role, $role, $memberName]);

            respond(200, ['success' => true, 'message' => "Invitation sent to $memberEmail"]);
        } catch (Throwable $e) {
            respond(500, ['error' => $e->getMessage()]);
        }
        break;

    // ── Team: Get members ─────────────────────────────────────────────────────
    case 'get_team':
        $ownerUid = clean($body['owner_uid'] ?? $_GET['owner_uid'] ?? '');
        if (!$ownerUid) respond(400, ['error' => 'owner_uid required']);

        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT member_email, member_name, role, invited_at, accepted FROM team_members WHERE owner_firebase_uid = ? ORDER BY invited_at DESC");
            $stmt->execute([$ownerUid]);
            respond(200, ['members' => $stmt->fetchAll()]);
        } catch (Throwable $e) {
            respond(500, ['error' => $e->getMessage()]);
        }
        break;

    // ── Admin: Get all users (requires admin secret) ──────────────────────────
    case 'admin_users':
        $secret = $_SERVER['HTTP_X_ADMIN_SECRET'] ?? $body['secret'] ?? '';
        if ($secret !== ADMIN_SECRET) {
            respond(403, ['error' => 'Unauthorized']);
        }

        try {
            $db = getDB();
            $page  = max(1, intval($body['page'] ?? 1));
            $limit = 50;
            $offset = ($page - 1) * $limit;

            $users = $db->query("
                SELECT u.id, u.firebase_uid, u.name, u.email, u.plan, u.is_active,
                       u.last_login, u.login_count, u.created_at,
                       (SELECT COUNT(*) FROM team_members tm WHERE tm.owner_firebase_uid = u.firebase_uid) AS team_size
                FROM users u
                ORDER BY u.created_at DESC
                LIMIT $limit OFFSET $offset
            ")->fetchAll();

            $total = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
            respond(200, ['users' => $users, 'total' => $total, 'page' => $page]);
        } catch (Throwable $e) {
            respond(500, ['error' => $e->getMessage()]);
        }
        break;

    // ── Admin: Get leads ──────────────────────────────────────────────────────
    case 'admin_leads':
        $secret = $_SERVER['HTTP_X_ADMIN_SECRET'] ?? $body['secret'] ?? '';
        if ($secret !== ADMIN_SECRET) respond(403, ['error' => 'Unauthorized']);

        try {
            $db = getDB();
            $leads = $db->query("SELECT id, name, email, whatsapp, plan, created_at FROM leads ORDER BY created_at DESC LIMIT 200")->fetchAll();
            respond(200, ['leads' => $leads]);
        } catch (Throwable $e) {
            respond(500, ['error' => $e->getMessage()]);
        }
        break;

    // ── Admin: Update user plan ────────────────────────────────────────────────
    case 'admin_update_plan':
        $secret = $_SERVER['HTTP_X_ADMIN_SECRET'] ?? $body['secret'] ?? '';
        if ($secret !== ADMIN_SECRET) respond(403, ['error' => 'Unauthorized']);

        $uid  = clean($body['uid'] ?? '');
        $plan = in_array($body['plan'] ?? '', ['free','paid']) ? $body['plan'] : 'free';
        $days = intval($body['days'] ?? 30);

        if (!$uid) respond(400, ['error' => 'uid required']);

        try {
            $db = getDB();
            $expires = $plan === 'paid' ? date('Y-m-d H:i:s', strtotime("+$days days")) : null;
            $stmt = $db->prepare("UPDATE users SET plan = ?, plan_expires_at = ? WHERE firebase_uid = ?");
            $stmt->execute([$plan, $expires, $uid]);
            respond(200, ['success' => true, 'plan' => $plan, 'expires_at' => $expires]);
        } catch (Throwable $e) {
            respond(500, ['error' => $e->getMessage()]);
        }
        break;

    // ── Admin: Toggle user active status ─────────────────────────────────────
    case 'admin_toggle_user':
        $secret = $_SERVER['HTTP_X_ADMIN_SECRET'] ?? $body['secret'] ?? '';
        if ($secret !== ADMIN_SECRET) respond(403, ['error' => 'Unauthorized']);

        $uid = clean($body['uid'] ?? '');
        if (!$uid) respond(400, ['error' => 'uid required']);

        try {
            $db = getDB();
            $db->prepare("UPDATE users SET is_active = NOT is_active WHERE firebase_uid = ?")->execute([$uid]);
            respond(200, ['success' => true]);
        } catch (Throwable $e) {
            respond(500, ['error' => $e->getMessage()]);
        }
        break;

    // ── Admin: Activity log ───────────────────────────────────────────────────
    case 'admin_activity':
        $secret = $_SERVER['HTTP_X_ADMIN_SECRET'] ?? $body['secret'] ?? '';
        if ($secret !== ADMIN_SECRET) respond(403, ['error' => 'Unauthorized']);

        try {
            $db = getDB();
            $log = $db->query("
                SELECT al.*, u.name, u.email
                FROM activity_log al
                LEFT JOIN users u ON u.firebase_uid = al.firebase_uid
                ORDER BY al.created_at DESC LIMIT 100
            ")->fetchAll();
            respond(200, ['log' => $log]);
        } catch (Throwable $e) {
            respond(500, ['error' => $e->getMessage()]);
        }
        break;

    default:
        respond(404, ['error' => 'Unknown action: ' . $action]);
}
