<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
// No CORS headers on purpose: this endpoint is same-origin only.

require_once __DIR__ . '/../auth.php';

requireApiRole(['OFFICER', 'CAPTAIN', 'ADMIN', 'SYSTEM_ADMIN']);
session_write_close();   // this page may be polled: do not hold the session lock

try {
    $pdo = getDatabaseConnection();

    $counts = [
        'pending' => 0,
        'in_progress' => 0,
        'dispatched' => 0,
        'for_resolution' => 0,
        'resolved' => 0,
        'closed' => 0,
        'critical' => 0,
        'total' => 0
    ];

    // 1. Tally statuses
    foreach ($pdo->query('SELECT status, COUNT(*) AS cnt FROM incident_reports GROUP BY status') as $row) {
        $statusKey = strtolower((string) $row['status']);
        if (array_key_exists($statusKey, $counts)) {
            $counts[$statusKey] = (int) $row['cnt'];
        }
        $counts['total'] += (int) $row['cnt'];
    }

    // 2. Recent cases
    $recent = $pdo->query('SELECT reference_number, incident_type, purok, status, priority_level, created_at
                           FROM incident_reports ORDER BY id DESC LIMIT 6')->fetchAll();

    $cols = 'reference_number, complainant_name, complainant_phone, incident_type, narrative_description,
             incident_datetime, purok, scenario_type, verification_level, priority_level, ai_recommendation,
             status, assigned_officer_name, deployed_unit, created_at';

    // 3. Urgent cases
    $urgentCases = $pdo->query("SELECT {$cols} FROM incident_reports
        WHERE (priority_level IN ('Critical', 'High') OR status = 'CRITICAL')
          AND status NOT IN ('RESOLVED', 'CLOSED', 'FOR_RESOLUTION')
        ORDER BY CASE WHEN priority_level = 'Critical' OR status = 'CRITICAL' THEN 0 ELSE 1 END, created_at DESC")->fetchAll();

    // 4. Case queue
    $caseQueue = $pdo->query("SELECT {$cols} FROM incident_reports
        WHERE status NOT IN ('RESOLVED', 'CLOSED', 'FOR_RESOLUTION')
        ORDER BY CASE WHEN priority_level = 'Critical' OR status = 'CRITICAL' THEN 0
                      WHEN priority_level = 'High' THEN 1 ELSE 2 END, created_at DESC")->fetchAll();

    echo json_encode([
        'success' => true,
        'counts' => $counts,
        'recent_cases' => $recent,
        'urgent_cases' => $urgentCases,
        'case_queue' => $caseQueue
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('[get_dashboard_stats] ' . $e->getMessage());
    jsonError(500, 'Could not load dashboard data.');
}