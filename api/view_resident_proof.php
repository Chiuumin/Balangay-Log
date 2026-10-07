<?php
require_once __DIR__ . '/../auth.php';

if (!isLoggedIn() || !in_array(getUserRole(), ['SYSTEM_ADMIN', 'ADMIN'], true)) {
    http_response_code(403);
    exit('Administrator access required.');
}

$userId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$userId) {
    http_response_code(400);
    exit('Invalid resident record.');
}

try {
    $pdo = getDatabaseConnection();
    $stmt = $pdo->prepare("SELECT proof_of_residency, full_name FROM users WHERE id = ? AND user_type = 'RESIDENT' LIMIT 1");
    $stmt->execute([$userId]);
    $resident = $stmt->fetch();
    $relativePath = (string) ($resident['proof_of_residency'] ?? '');
    if ($relativePath === '') {
        http_response_code(404);
        exit('No submitted proof was found.');
    }

    $path = __DIR__ . '/../uploads/residency/' . basename($relativePath);
    if (!is_file($path)) {
        http_response_code(404);
        exit('The submitted document is not available.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
        http_response_code(415);
        exit('Unsupported document type.');
    }

    logAudit('RESIDENT_DOCUMENT_VIEWED', ['resident_name' => $resident['full_name']], (int) $_SESSION['user_id'], (int) $userId);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="resident-proof-' . (int) $userId . '.' . ($mime === 'application/pdf' ? 'pdf' : ($mime === 'image/png' ? 'png' : 'jpg')) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
} catch (Throwable $e) {
    error_log('Resident proof preview failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Could not open the submitted document.');
}
