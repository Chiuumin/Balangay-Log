<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/case_intake.php';

requireJsonPost();
$actor = requireApiRole(['OFFICER', 'CAPTAIN', 'ADMIN', 'SYSTEM_ADMIN']);
session_write_close();   // release the session lock: an AI call can take seconds

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) jsonError(400, 'Invalid or missing JSON payload.');

try {
    $r = encode_case_run($input, $actor);
} catch (Throwable $e) {
    error_log('[encode_case] ' . $e->getMessage());
    jsonError(500, 'Could not save the incident. Nothing was recorded.');
}
http_response_code($r['status']);
echo json_encode($r['body']);