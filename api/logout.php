<?php
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$actorUserId = isLoggedIn() ? (int) $_SESSION['user_id'] : null;

session_unset();
session_destroy();

if ($actorUserId) {
    logAudit('LOGOUT', ['logout' => true], $actorUserId, $actorUserId);
}

echo json_encode(['success' => true, 'message' => 'Logged out successfully.']);
exit;
