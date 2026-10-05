<?php
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['first_failed_time'] = time();
}

$maxAttempts = 5;
$lockoutSeconds = 300;

if ($_SESSION['login_attempts'] >= $maxAttempts) {
    $elapsed = time() - ($_SESSION['first_failed_time'] ?? time());
    if ($elapsed < $lockoutSeconds) {
        $remainingMinutes = (int) ceil(($lockoutSeconds - $elapsed) / 60);
        echo json_encode([
            'success' => false,
            'message' => "Too many failed attempts. Please try again in {$remainingMinutes} minute(s).",
        ]);
        exit;
    }

    $_SESSION['login_attempts'] = 0;
    $_SESSION['first_failed_time'] = time();
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    $data = $_POST;
}

$username = trim((string) ($data['username'] ?? ''));
$password = (string) ($data['password'] ?? '');

if ($username === '' || $password === '') {
    echo json_encode(['success' => false, 'message' => 'Please enter both username and password.']);
    exit;
}

$pdo = getDatabaseConnection();
$stmt = $pdo->prepare('SELECT * FROM users WHERE username = :username1 OR email_or_phone = :username2 OR full_name = :username3 LIMIT 1');
$stmt->execute([
    'username1' => $username,
    'username2' => $username,
    'username3' => $username,
]);
$user = $stmt->fetch();

if (!$user) {
    $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
    echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
    exit;
}

$accountStatus = strtoupper((string) ($user['status'] ?? $user['authorization_status'] ?? 'ACTIVE'));
$role = strtoupper((string) ($user['user_type'] ?? 'RESIDENT'));

if ($accountStatus === 'PENDING_VERIFICATION') {
    echo json_encode(['success' => false, 'message' => 'Your account is still pending verification.']);
    exit;
}

if ($accountStatus === 'REJECTED') {
    echo json_encode(['success' => false, 'message' => 'Your registration was rejected. Please contact the System Administrator.']);
    exit;
}

if (in_array($accountStatus, ['INACTIVE', 'DEACTIVATED'], true)) {
    echo json_encode(['success' => false, 'message' => 'Your account is currently inactive. Please contact the System Administrator.']);
    exit;
}

if (!password_verify($password, (string) $user['password_hash'])) {
    $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
    logAudit('FAILED_LOGIN', ['username' => $username], (int) ($user['id'] ?? 0), (int) ($user['id'] ?? 0));
    echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
    exit;
}

$_SESSION['login_attempts'] = 0;
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['role'] = $role;
$_SESSION['account_type'] = $role;
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['username'] = $user['username'] ?: $user['email_or_phone'];

session_regenerate_id(true);

logAudit('LOGIN', ['username' => $_SESSION['username'], 'role' => $role], (int) $user['id'], (int) $user['id']);

$jsonUser = [
    'id' => (int) $user['id'],
    'full_name' => $user['full_name'],
    'username' => $user['username'] ?: $user['email_or_phone'],
    'role' => $role,
    'status' => $accountStatus,
];

echo json_encode([
    'success' => true,
    'message' => 'Login successful.',
    'token' => bin2hex(random_bytes(32)),
    'user' => $jsonUser,
    'redirect' => getRoleDashboardPath($role),
]);
exit;