<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');

$host = "127.0.0.1";
$db   = "balangaylog_db";
$user = "root";
$pass = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Fetch ALL details for the inventory dropdowns
    $stmt = $pdo->query("SELECT * FROM incident_reports ORDER BY created_at DESC");
    $cases = $stmt->fetchAll();

    echo json_encode(['success' => true, 'cases' => $cases]);
} catch (\PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB Connection Failed']);
}
?>