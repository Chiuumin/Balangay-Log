<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');

$conn = new mysqli("127.0.0.1", "root", "", "balangaylog_db", 3306);

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// 1. Critical Emergencies
$criticalRes = $conn->query("SELECT id, reference_number, incident_type, purok, created_at 
    FROM incident_reports 
    WHERE (priority_level = 'Critical' OR status = 'CRITICAL') AND status != 'RESOLVED' 
    ORDER BY id DESC LIMIT 10");

$criticalCases = [];
if ($criticalRes) {
    while ($row = $criticalRes->fetch_assoc()) {
        $criticalCases[] = [
            'id' => $row['id'],
            'reference_number' => $row['reference_number'],
            'title' => $row['incident_type'],
            'purok' => $row['purok'],
            'category_label' => 'CRITICAL EMERGENCY',
            'priority' => 'critical',
            'badge_color' => '#dc2626',
            'border_color' => '#ef4444'
            'subtext' => $row['reference_number'],
            'target_url' => '#' . $row['reference_number']
        ];
    }
}

// 2. High Priority Incidents
$highRes = $conn->query("SELECT id, reference_number, incident_type, purok, created_at 
    FROM incident_reports 
    WHERE priority_level = 'High' AND status != 'CRITICAL' AND status != 'RESOLVED' 
    ORDER BY id DESC LIMIT 10");

$highCases = [];
if ($highRes) {
    while ($row = $highRes->fetch_assoc()) {
        $highCases[] = [
            'id' => $row['id'],
            'reference_number' => $row['reference_number'],
            'title' => $row['incident_type'],
            'purok' => $row['purok'],
            'category_label' => 'HIGH PRIORITY',
            'priority' => 'high',
            'badge_color' => '#d97706',
            'border_color' => '#f97316'
            'subtext' => $row['reference_number'],
            'target_url' => '#' . $row['reference_number']
        ];
    }
}

// 3. Low Priority Incidents
$lowRes = $conn->query("SELECT id, reference_number, incident_type, purok, created_at 
    FROM incident_reports 
    WHERE priority_level = 'Low' AND status != 'CRITICAL' AND status != 'RESOLVED' 
    ORDER BY id DESC LIMIT 10");

$lowCases = [];
if ($lowRes) {
    while ($row = $lowRes->fetch_assoc()) {
        $lowCases[] = [
            'id' => $row['id'],
            'reference_number' => $row['reference_number'],
            'title' => $row['incident_type'],
            'purok' => $row['purok'],
            'category_label' => 'LOW PRIORITY',
            'priority' => 'low',
            'badge_color' => '#15803d',
            'border_color' => '#10b981'
            'subtext' => $row['reference_number'],
            'target_url' => '#' . $row['reference_number']
        ];
    }
}

$countCritical = count($criticalCases);
$countHigh     = count($highCases);
$countLow      = count($lowCases);
$totalCombined = $countCritical + $countHigh + $countLow;

// Critical first, then High, then Low
$allNotifications = array_merge($criticalCases, $highCases, $lowCases);

echo json_encode([
    'success' => true,
    'counts' => [
        'total' => $totalCombined,
        'critical' => $countCritical,
        'high' => $countHigh,
        'low' => $countLow
    ],
    'notifications' => $allNotifications
]);

$conn->close();
