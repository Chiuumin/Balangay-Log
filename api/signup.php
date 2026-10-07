<?php
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data) || empty($data)) {
    $data = $_POST;
}

$firstName = trim((string) ($data['first_name'] ?? $data['firstName'] ?? ''));
$lastName = trim((string) ($data['last_name'] ?? $data['lastName'] ?? ''));
$username = trim((string) ($data['username'] ?? ''));
$password = (string) ($data['password'] ?? '');
$confirmPassword = (string) ($data['confirm_password'] ?? $data['confirmPassword'] ?? '');
$dob = trim((string) ($data['date_of_birth'] ?? $data['dateOfBirth'] ?? ''));
$address = trim((string) ($data['address'] ?? ''));
$purok = trim((string) ($data['purok'] ?? ''));
$contactNumber = trim((string) ($data['contact_number'] ?? $data['contactNumber'] ?? ''));

if ($firstName === '' || $lastName === '' || $username === '' || $password === '' || $confirmPassword === '' || $dob === '' || $address === '' || $purok === '' || $contactNumber === '') {
    echo json_encode(['success' => false, 'message' => 'Please complete all required resident registration fields.']);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
    echo json_encode(['success' => false, 'message' => 'Username may only contain letters, numbers, underscores, periods, and hyphens.']);
    exit;
}

if (strlen($password) < 6) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters long.']);
    exit;
}

if ($password !== $confirmPassword) {
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit;
}

$pdo = getDatabaseConnection();
$duplicateStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username OR email_or_phone = :email_or_phone LIMIT 1');
$duplicateStmt->execute([
    'username' => $username,
    'email_or_phone' => $username,
]);

if ($duplicateStmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'That username is already taken. Please choose another.']);
    exit;
}

$fullName = trim($firstName . ' ' . $lastName);
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$proofPath = null;
$savedProofPath = null;

try {
    $proof = $_FILES['proof_of_residency'] ?? null;
    if ($proof && $proof['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($proof['error'] !== UPLOAD_ERR_OK || $proof['size'] > 5 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'Proof of residency must be smaller than 5 MB.']);
            exit;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($proof['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
        if (!isset($extensions[$mime])) {
            echo json_encode(['success' => false, 'message' => 'Upload a JPG, PNG, or PDF proof of residency.']);
            exit;
        }
        $uploadDirectory = __DIR__ . '/../uploads/residency';
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
            throw new RuntimeException('Could not create private upload directory.');
        }
        $filename = bin2hex(random_bytes(20)) . '.' . $extensions[$mime];
        $savedProofPath = $uploadDirectory . '/' . $filename;
        if (!move_uploaded_file($proof['tmp_name'], $savedProofPath)) {
            throw new RuntimeException('Could not store proof of residency.');
        }
        $proofPath = 'uploads/residency/' . $filename;
    }

    $stmt = $pdo->prepare('INSERT INTO users (full_name, username, email_or_phone, password_hash, date_of_birth, address, purok, contact_number, proof_of_residency, user_type, status, authorization_status, created_at) VALUES (:full_name, :username, :email_or_phone, :password_hash, :date_of_birth, :address, :purok, :contact_number, :proof_of_residency, :user_type, :status, :authorization_status, NOW())');
    $stmt->execute([
        'full_name' => $fullName,
        'username' => $username,
        'email_or_phone' => $username,
        'password_hash' => $passwordHash,
        'date_of_birth' => $dob,
        'address' => $address,
        'purok' => $purok,
        'contact_number' => $contactNumber,
        'proof_of_residency' => $proofPath,
        'user_type' => 'RESIDENT',
        'status' => 'PENDING_VERIFICATION',
        'authorization_status' => 'PENDING_VERIFICATION',
    ]);

    $userId = (int) $pdo->lastInsertId();
    logAudit('RESIDENT_REGISTRATION', [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'username' => $username,
        'contact_number' => $contactNumber,
    ], null, $userId);

    echo json_encode([
        'success' => true,
        'message' => 'Your registration has been submitted and is currently pending verification by the System Administrator.',
    ]);
    exit;
} catch (Throwable $e) {
    if ($savedProofPath && is_file($savedProofPath)) unlink($savedProofPath);
    error_log('Resident registration failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Registration failed. Please try again.']);
    exit;
}
