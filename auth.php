<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,      // JavaScript cannot read the session cookie
        'samesite' => 'Lax',     // not sent on cross-site POSTs
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

function getDatabaseConnection(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = '127.0.0.1';
    $db = 'balangaylog_db';
    $user = 'root';
    $pass = '';
    $charset = 'utf8mb4';

    $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $pdo = new PDO($dsn, $user, $pass, $options);
    return $pdo;
}

function getSiteBasePath(): string
{
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.html');
    $dirname = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    return $dirname === '.' || $dirname === '/' ? '' : $dirname;
}

function redirectTo(string $path): void
{
    $base = getSiteBasePath();
    $target = $base !== '' ? $base . '/' . ltrim($path, '/') : '/' . ltrim($path, '/');
    header('Location: ' . $target);
    exit;
}

function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

function getUserRole(): string
{
    $role = $_SESSION['role'] ?? $_SESSION['account_type'] ?? 'GUEST';
    return strtoupper(trim((string) $role));
}

function getRoleDashboardPath(string $role): string
{
    $normalized = strtoupper(trim($role));

    $map = [
        'SYSTEM_ADMIN' => 'admin_dashboard.php',
        'ADMIN' => 'admin_dashboard.php',
        'CAPTAIN' => 'captain_dashboard.php',
        'OFFICER' => 'officer_dashboard.php',
        'RESIDENT' => 'resident_dashboard.php',
    ];

    return $map[$normalized] ?? 'index.html';
}

function requireLogin(string $redirectPath = 'index.html'): void
{
    if (!isLoggedIn()) {
        redirectTo($redirectPath);
    }
}

function requireRole(array $allowedRoles): void
{
    requireLogin('index.html');

    $userRole = getUserRole();
    $allowed = array_map('strtoupper', $allowedRoles);

    if (!in_array($userRole, $allowed, true)) {
        redirectTo(getRoleDashboardPath($userRole));
    }
}

function getCurrentUser() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        return null;
    }

    try {
        $pdo = getDatabaseConnection();
        $stmt = $pdo->prepare('
            SELECT 
                id, 
                full_name, 
                username, 
                email_or_phone, 
                user_type AS role, 
                status 
            FROM users 
            WHERE id = :id 
            LIMIT 1
        ');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

function hasSystemAdmin(): bool
{
    $pdo = getDatabaseConnection();
    $stmt = $pdo->query("SELECT EXISTS(SELECT 1 FROM users WHERE user_type IN ('SYSTEM_ADMIN', 'ADMIN') LIMIT 1)");
    return (bool) $stmt->fetchColumn();
}

function logAudit(string $action, array $details = [], ?int $actorUserId = null, ?int $targetUserId = null): void
{
    try {
        $pdo = getDatabaseConnection();
        $stmt = $pdo->prepare('INSERT INTO audit_logs (actor_user_id, target_user_id, action, details) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            $actorUserId,
            $targetUserId,
            $action,
            json_encode($details, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
        // Intentionally silent to avoid breaking user actions when audit logging is unavailable.
    }
}

/* ------------------------------------------------------------------
   JSON guards for /api endpoints. The page guards above redirect,
   which breaks fetch() calls.
------------------------------------------------------------------ */
function jsonError(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function requireJsonPost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        jsonError(405, 'POST only.');
    }
    // A cross-site HTML form cannot send this content type without a CORS preflight,
    // so this check also blocks forged cross-site posts (CSRF).
    $ct = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (strpos($ct, 'application/json') !== 0) {
        jsonError(415, 'Content-Type must be application/json.');
    }
}

/** Returns the verified user row. The role is read from the DB, not the session. */
function requireApiRole(array $allowedRoles): array
{
    if (!isLoggedIn()) {
        jsonError(401, 'Please log in again.');
    }
    $user = getCurrentUser();
    if (!$user || strtoupper((string) $user['status']) !== 'ACTIVE') {
        jsonError(401, 'Your account is not active.');
    }
    $role = strtoupper(trim((string) $user['role']));
    if (!in_array($role, array_map('strtoupper', $allowedRoles), true)) {
        jsonError(403, 'You do not have permission for this action.');
    }
    return $user;
}