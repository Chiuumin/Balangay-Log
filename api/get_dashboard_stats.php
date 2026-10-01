<?php
// Suppress raw HTML error display so JSON won't break
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');

$conn = new mysqli("127.0.0.1", "root", "", "balangaylog_db", 3306);

if ($conn->connect_error) {
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . $conn->connect_error,
        'counts' => [
            'pending' => 0,
            'in_progress' => 0,
            'for_resolution' => 0,
            'resolved' => 0,
            'critical' => 0,
            'total' => 0
        ],
        'recent_cases' => []
    ]);
    exit();
}

$counts = [
    'pending' => 0,
    'in_progress' => 0,
    'for_resolution' => 0,
    'resolved' => 0,
    'critical' => 0,
    'total' => 0
];

// 1. Tally active statuses
$sql = "SELECT status, COUNT(*) as cnt FROM incident_reports GROUP BY status";
$res = $conn->query($sql);

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $statusKey = strtolower($row['status']);
        if (array_key_exists($statusKey, $counts)) {
            $counts[$statusKey] = (int)$row['cnt'];
        }
        $counts['total'] += (int)$row['cnt'];
    }
}

// 2. Fetch Recent Cases
$recent = [];
$recRes = $conn->query("SELECT reference_number, incident_type, purok, status, priority_level, created_at FROM incident_reports ORDER BY id DESC LIMIT 6");

if ($recRes) {
    while ($row = $recRes->fetch_assoc()) {
        $recent[] = $row;
    }
}

$urgentCases = [];
$urgentRes = $conn->query("SELECT reference_number, complainant_name, complainant_phone, incident_type, narrative_description, incident_datetime, purok, scenario_type, verification_level, priority_level, ai_recommendation, status, assigned_officer_name, deployed_unit, created_at FROM incident_reports WHERE (priority_level IN ('Critical', 'High') OR status = 'CRITICAL') AND status NOT IN ('RESOLVED', 'FOR_RESOLUTION') ORDER BY CASE WHEN priority_level = 'Critical' OR status = 'CRITICAL' THEN 0 ELSE 1 END, created_at DESC");

if ($urgentRes) {
    while ($row = $urgentRes->fetch_assoc()) {
        $urgentCases[] = $row;
    }
}

$caseQueue = [];
$queueRes = $conn->query("SELECT reference_number, complainant_name, complainant_phone, incident_type, narrative_description, incident_datetime, purok, scenario_type, verification_level, priority_level, ai_recommendation, status, assigned_officer_name, deployed_unit, created_at FROM incident_reports WHERE status NOT IN ('RESOLVED', 'FOR_RESOLUTION') ORDER BY CASE WHEN priority_level = 'Critical' OR status = 'CRITICAL' THEN 0 WHEN priority_level = 'High' THEN 1 ELSE 2 END, created_at DESC");

if ($queueRes) {
    while ($row = $queueRes->fetch_assoc()) {
        $caseQueue[] = $row;
    }
}

echo json_encode([
    'success' => true,
    'counts' => $counts,
    'recent_cases' => $recent,
    'urgent_cases' => $urgentCases,
    'case_queue' => $caseQueue
]);

$conn->close();