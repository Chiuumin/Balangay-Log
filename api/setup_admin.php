<?php
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (hasSystemAdmin()) {
    echo json_encode(['success' => false, 'message' => 'The system has already been set up.']);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    $data = $_POST;
}

$firstName = trim((string) ($data['first_name'] ?? ''));
$lastName = trim((string) ($data['last_name'] ?? ''));
$username = trim((string) ($data['username'] ?? ''));
$password = (string) ($data['password'] ?? '');
$confirmPassword = (string) ($data['confirm_password'] ?? '');

if ($firstName === '' || $lastName === '' || $username === '' || $password === '' || $confirmPassword === '') {
    echo json_encode(['success' => false, 'message' => 'Please complete all required fields.']);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
    echo json_encode(['success' => false, 'message' => 'Username may only contain letters, numbers, underscores, periods, and hyphens.']);
    exit;
}

if ($password !== $confirmPassword) {
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit;
}

if (strlen($password) < 6) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters long.']);
    exit;
}

$pdo = getDatabaseConnection();
$duplicateStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username OR email_or_phone = :email_or_phone LIMIT 1');
$duplicateStmt->execute([
    'username' => $username,
    'email_or_phone' => $username,
]);

if ($duplicateStmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'That username is already taken.']);
    exit;
}

$fullName = trim($firstName . ' ' . $lastName);
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare('INSERT INTO users (full_name, username, email_or_phone, password_hash, user_type, status, authorization_status, created_at) VALUES (:full_name, :username, :email_or_phone, :password_hash, :user_type, :status, :authorization_status, NOW())');
$stmt->execute([
    'full_name' => $fullName,
    'username' => $username,
    'email_or_phone' => $username,
    'password_hash' => $passwordHash,
    'user_type' => 'SYSTEM_ADMIN',
    'status' => 'ACTIVE',
    'authorization_status' => 'ACTIVE',
]);

$adminUserId = (int) $pdo->lastInsertId();
logAudit('SYSTEM_ADMIN_SETUP', ['username' => $username, 'full_name' => $fullName], null, $adminUserId);

echo json_encode(['success' => true, 'message' => 'System administrator account created successfully.']);
exit;
