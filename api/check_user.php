<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
// No CORS headers on purpose: same-origin only.

require_once __DIR__ . '/../auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonError(405, 'POST only.');
}

$data = json_decode(file_get_contents('php://input'), true);
$identifier = is_array($data) ? trim((string) ($data['username'] ?? '')) : '';

if ($identifier === '' || mb_strlen($identifier) > 120) {
    echo json_encode(['success' => false, 'exists' => false, 'message' => 'Please enter your username or email first.']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    // Username or email/phone only. Full names are not unique and must not be searchable here.
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE username = :a OR email_or_phone = :b LIMIT 1');
    $stmt->execute(['a' => $identifier, 'b' => $identifier]);
    $exists = (bool) $stmt->fetchColumn();
} catch (Throwable $e) {
    error_log('[check_user] ' . $e->getMessage());
    jsonError(500, 'Could not check the account right now.');
}

if ($exists) {
    // The account holder's name is never sent back.
    echo json_encode(['success' => true, 'exists' => true, 'name' => '']);
} else {
    echo json_encode([
        'success' => true,
        'exists'  => false,
        'message' => 'No registered account found with that username or email. Please enter an existing account.'
    ]);
}
exit;