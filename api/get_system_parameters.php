<?php
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    $puroks = $pdo->query('SELECT name FROM system_puroks WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    $categories = $pdo->query('SELECT name FROM incident_categories WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['success' => true, 'puroks' => $puroks, 'categories' => $categories], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('System parameter lookup failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'System parameters are not ready. Import admin_schema.sql first.']);
}
