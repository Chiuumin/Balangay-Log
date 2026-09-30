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

    // 1. Active Cases (Anything not RESOLVED or DISMISSED)
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM incident_reports WHERE status NOT IN ('RESOLVED', 'DISMISSED')");
    $activeCases = $stmt->fetch()['count'];

    // 2. Resolved This Month
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM incident_reports WHERE status = 'RESOLVED' AND MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())");
    $resolvedMonth = $stmt->fetch()['count'];

    // 3. Residents Served (Distinct complainants)
    $stmt = $pdo->query("SELECT COUNT(DISTINCT complainant_name) as count FROM incident_reports");
    $residentsServed = $stmt->fetch()['count'];

    // 4. Calculate Dynamic Average Resolution Time (in Days)
    // We calculate the difference in hours and divide by 24 to get accurate decimals (e.g., 1.5 days)
    $stmt = $pdo->query("
        SELECT ROUND(AVG(TIMESTAMPDIFF(HOUR, i.created_at, m.created_at)) / 24, 1) as avg_days
        FROM incident_reports i
        JOIN case_milestones m ON i.id = m.incident_id
        WHERE m.status_snapshot = 'RESOLVED'
    ");
    
    $result = $stmt->fetch();
    
    // Fallback to 0 if no cases have been resolved yet
    $avgResolution = $result['avg_days'] !== null ? $result['avg_days'] : 0;

    echo json_encode([
        'success' => true,
        'activeCases' => $activeCases,
        'resolvedMonth' => $resolvedMonth,
        'residentsServed' => $residentsServed,
        'avgResolution' => $avgResolution
    ]);

} catch (\PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB Connection Failed']);
}
?>
