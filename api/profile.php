<?php
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in to continue.']);
    exit;
}

$pdo = getDatabaseConnection();
$userId = (int) $_SESSION['user_id'];
$action = trim((string) ($_POST['action'] ?? $_GET['action'] ?? ''));

try {
    if ($action === 'profile_update') {
        $name = trim((string) ($_POST['full_name'] ?? ''));
        $contact = trim((string) ($_POST['contact_number'] ?? ''));

        if ($name === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Full name cannot be empty.']);
            exit;
        }

        $picturePath = null;
        if (!empty($_FILES['profile_picture']['tmp_name'])) {
            $picture = $_FILES['profile_picture'];
            if ($picture['error'] !== UPLOAD_ERR_OK || $picture['size'] > 2 * 1024 * 1024) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Image must be under 2MB.']);
                exit;
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($picture['tmp_name']);
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($allowed[$mime])) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Use a JPG, PNG, or WebP image.']);
                exit;
            }

            $dir = __DIR__ . '/../uploads/profiles';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $filename = $userId . '-' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
            move_uploaded_file($picture['tmp_name'], $dir . '/' . $filename);
            $picturePath = 'uploads/profiles/' . $filename;
        }

        if ($picturePath !== null) {
            $stmt = $pdo->prepare('UPDATE users SET full_name = ?, contact_number = ?, profile_picture = ? WHERE id = ?');
            $stmt->execute([$name, $contact, $picturePath, $userId]);
        } else {
            $stmt = $pdo->prepare('UPDATE users SET full_name = ?, contact_number = ? WHERE id = ?');
            $stmt->execute([$name, $contact, $userId]);
        }

        $_SESSION['full_name'] = $name;
        logAudit('PROFILE_UPDATED', ['full_name' => $name], $userId, $userId);
        echo json_encode(['success' => true, 'message' => 'Profile updated successfully.']);
        exit;
    }

    if ($action === 'password_update') {
        $raw = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $current = (string) ($raw['current_password'] ?? '');
        $new = (string) ($raw['new_password'] ?? '');
        $confirm = (string) ($raw['confirm_password'] ?? '');

        if (strlen($new) < 8 || $new !== $confirm) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters and match.']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $hash = (string) $stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
            exit;
        }

        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
        logAudit('PASSWORD_CHANGED', [], $userId, $userId);
        echo json_encode(['success' => true, 'message' => 'Password changed successfully.']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred while saving your changes.']);
}