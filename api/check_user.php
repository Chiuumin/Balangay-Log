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

// Retain the legacy response shape without exposing account existence.
echo json_encode([
    'success' => true,
    'exists' => false,
    'name' => '',
    'message' => 'If the account is eligible, recovery instructions will be sent to its registered number.',
]);
exit;