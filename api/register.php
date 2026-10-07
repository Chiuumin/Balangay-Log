<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli("127.0.0.1", "root", "", "balangaylog_db", 3306);
    $conn->set_charset("utf8mb4");
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Hindi makakonekta sa database.']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Maling JSON data.']);
    exit();
}

$fullName      = trim($input['fullName'] ?? '');
$email         = trim($input['email'] ?? '');
$phone         = trim($input['phone'] ?? '');
$password      = trim($input['password'] ?? '');
$role          = trim($input['role'] ?? 'RESIDENT');
$purok         = trim($input['purok'] ?? '');
$streetAddress = trim($input['streetAddress'] ?? '');
$idProofNumber = trim($input['idProofNumber'] ?? '');

if (strlen($fullName) < 3 || empty($email) || strlen($password) < 6) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Punan ang lahat ng kailangang impormasyon (minimum 6 characters ang password).']);
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Hindi wastong format ng email.']);
    exit();
}

// Check kung may existing user na gamit ang email na ito
$stmtCheck = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$stmtCheck->bind_param("s", $email);
$stmtCheck->execute();
if ($stmtCheck->get_result()->num_rows > 0) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'May account na gamit ang email na ito.']);
    exit();
}
$stmtCheck->close();

$hashedPassword = password_hash($password, PASSWORD_BCRYPT);
// Laging 'PENDING' para kailangan muna i-verify at i-authorize ng Admin bago makapasok
$authStatus     = 'PENDING';
$userType       = ($role === 'RESIDENT') ? 'RESIDENT' : 'OFFICER';
$emailOrPhone   = !empty($phone) ? $phone : $email;

$stmt = $conn->prepare("INSERT INTO users (full_name, email, purok, street_address, id_proof_number, role, email_or_phone, password_hash, user_type, authorization_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("ssssssssss", $fullName, $email, $purok, $streetAddress, $idProofNumber, $role, $emailOrPhone, $hashedPassword, $userType, $authStatus);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true,
        'message' => 'Naipasa na ang iyong pagpaparehistro. Kasalukuyang PENDING ang status para sa pagsusuri ng Barangay Admin.'
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Nagka-aberya sa pag-save ng account records.']);
}

$stmt->close();
$conn->close();