<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli("127.0.0.1", "root", "", "balangaylog_db", 3306);
    $conn->set_charset("utf8mb4");
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Koneksyon sa database nabigo: ' . $e->getMessage()]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

// 1. GET: Kunin ang lahat ng user accounts
if ($method === 'GET') {
    $sql = "SELECT id, full_name, email, role, email_or_phone, user_type, authorization_status, created_at FROM users ORDER BY created_at DESC";
    $result = $conn->query($sql);

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }

    echo json_encode(['success' => true, 'users' => $users]);
    exit();
}

// 2. POST: Admin gumagawa ng bagong account
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $fullName   = trim($input['fullName'] ?? '');
    $email      = trim($input['email'] ?? '');
    $phone      = trim($input['phone'] ?? '');
    $password   = trim($input['password'] ?? '');
    $role       = trim($input['role'] ?? 'OFFICER');
    $authStatus = trim($input['authStatus'] ?? 'AUTHORIZED');

    if (empty($fullName) || empty($email) || strlen($password) < 6) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Lahat ng field ay kailangan (minimum 6 chars ang password).']);
        exit();
    }

    // Check duplicate
    $stmtCheck = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmtCheck->bind_param("s", $email);
    $stmtCheck->execute();
    if ($stmtCheck->get_result()->num_rows > 0) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Ginagamit na ang email na ito.']);
        exit();
    }
    $stmtCheck->close();

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $emailOrPhone = !empty($phone) ? $phone : $email;
    $userType = in_array($role, ['ADMIN', 'OFFICER', 'RESIDENT']) ? $role : 'OFFICER';

    $stmt = $conn->prepare("INSERT INTO users (full_name, email, role, email_or_phone, password_hash, user_type, authorization_status) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssss", $fullName, $email, $role, $emailOrPhone, $hash, $userType, $authStatus);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Matagumpay na nagawa ang account.']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Hindi nagawang likhain ang account.']);
    }
    $stmt->close();
    exit();
}

// 3. PUT: Pag-update ng email, pangalan, role, status, o password
if ($method === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);

    $id         = intval($input['id'] ?? 0);
    $fullName   = trim($input['fullName'] ?? '');
    $email      = trim($input['email'] ?? '');
    $role       = trim($input['role'] ?? '');
    $authStatus = trim($input['authStatus'] ?? '');
    $newPass    = trim($input['newPassword'] ?? '');

    if ($id <= 0 || empty($fullName) || empty($email)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Wastong User ID, Buong Pangalan, at Email ang kailangan.']);
        exit();
    }

    // Check email uniqueness maliban sa sarili
    $stmtEmail = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
    $stmtEmail->bind_param("si", $email, $id);
    $stmtEmail->execute();
    if ($stmtEmail->get_result()->num_rows > 0) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Ang email na ito ay gamit na ng ibang account.']);
        exit();
    }
    $stmtEmail->close();

    $userType = in_array($role, ['ADMIN', 'OFFICER', 'RESIDENT']) ? $role : 'OFFICER';

    if (!empty($newPass)) {
        if (strlen($newPass) < 6) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Dapat hindi bababa sa 6 characters ang password.']);
            exit();
        }
        $newHash = password_hash($newPass, PASSWORD_BCRYPT);
        $stmtUpdate = $conn->prepare("UPDATE users SET full_name = ?, email = ?, role = ?, user_type = ?, authorization_status = ?, password_hash = ? WHERE id = ?");
        $stmtUpdate->bind_param("ssssssi", $fullName, $email, $role, $userType, $authStatus, $newHash, $id);
    } else {
        $stmtUpdate = $conn->prepare("UPDATE users SET full_name = ?, email = ?, role = ?, user_type = ?, authorization_status = ? WHERE id = ?");
        $stmtUpdate->bind_param("sssssi", $fullName, $email, $role, $userType, $authStatus, $id);
    }

    if ($stmtUpdate->execute()) {
        echo json_encode(['success' => true, 'message' => 'Na-update nang matagumpay ang account.']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Hindi nagawang i-update ang account.']);
    }
    $stmtUpdate->close();
    exit();
}

// 4. DELETE: Pagbura o pagtanggal ng account
if ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);

    if ($id <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Hindi wastong User ID.']);
        exit();
    }

    $stmtDelete = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmtDelete->bind_param("i", $id);

    if ($stmtDelete->execute()) {
        echo json_encode(['success' => true, 'message' => 'Matagumpay na nabura ang user account.']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Hindi nabura ang account.']);
    }
    $stmtDelete->close();
    exit();
}

$conn->close();