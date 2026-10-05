<?php
if (session_status() === PHP_SESSION_NONE) {
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

function getCurrentUser(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }

    $pdo = getDatabaseConnection();
    $stmt = $pdo->prepare('SELECT id, full_name, username, email_or_phone, user_type, status, authorization_status, contact_number, address, purok, date_of_birth FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        session_unset();
        session_destroy();
        return null;
    }

    return $user;
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
