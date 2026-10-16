<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    jsonError(405, 'GET only.');
}

// Full case record: staff only. Residents use api/resident_console.php.
requireApiRole(['OFFICER', 'CAPTAIN', 'ADMIN', 'SYSTEM_ADMIN']);
session_write_close();

$trackingId = trim((string) ($_GET['tracking_id'] ?? ''));
if ($trackingId === '') {
    jsonError(422, 'Tracking ID is required.');
}

try {
    $pdo = getDatabaseConnection();

    $st = $pdo->prepare('SELECT * FROM incident_reports WHERE reference_number = ? LIMIT 1');
    $st->execute([$trackingId]);
    $case = $st->fetch();

    if (!$case) {
        jsonError(404, 'No matching case found.');
    }

    $ms = $pdo->prepare('SELECT status_snapshot, officer_in_charge, deployed_unit, action_note, created_at
                         FROM case_milestones WHERE tracking_id = ? ORDER BY id ASC');
    $ms->execute([$trackingId]);
    $milestones = $ms->fetchAll();

    echo json_encode([
        'success' => true,
        'case' => $case,
        'milestones' => $milestones
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('[track_case] ' . $e->getMessage());
    jsonError(500, 'Could not load the case.');
}