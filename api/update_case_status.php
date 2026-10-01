<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli("127.0.0.1", "root", "", "balangaylog_db", 3306);
    $conn->set_charset("utf8mb4");
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.']);
    exit();
}

$referenceNumber = trim($input['reference_number'] ?? '');
$newStatus       = trim($input['new_status'] ?? '');
$actionNote      = trim($input['action_note'] ?? '');
$hasOfficerAssignment = array_key_exists('assigned_officer_name', $input);
$hasUnitAssignment = array_key_exists('deployed_unit', $input);
$assignedOfficerName = $hasOfficerAssignment && $input['assigned_officer_name'] !== null
    ? trim($input['assigned_officer_name'])
    : null;
$deployedUnit = $hasUnitAssignment && $input['deployed_unit'] !== null
    ? trim($input['deployed_unit'])
    : null;

$allowedStatuses = ['PENDING', 'IN_PROGRESS', 'FOR_RESOLUTION', 'RESOLVED', 'CRITICAL'];

if (empty($referenceNumber) || !in_array($newStatus, $allowedStatuses)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid reference number or target status.']);
    exit();
}

// 1. Fetch case details
$stmtSelect = $conn->prepare("SELECT id, status, assigned_officer_name, deployed_unit FROM incident_reports WHERE reference_number = ? LIMIT 1");
$stmtSelect->bind_param("s", $referenceNumber);
$stmtSelect->execute();
$res = $stmtSelect->get_result();

if ($res->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Incident record not found.']);
    exit();
}

$caseData   = $res->fetch_assoc();
$incidentId = $caseData['id'];
$oldStatus  = $caseData['status'];
$assignedOfficerName = $hasOfficerAssignment ? $assignedOfficerName : $caseData['assigned_officer_name'];
$deployedUnit = $hasUnitAssignment ? $deployedUnit : $caseData['deployed_unit'];
$stmtSelect->close();

// 2. Update case status
$stmtUpdate = $conn->prepare("UPDATE incident_reports SET status = ?, assigned_officer_name = ?, deployed_unit = ? WHERE id = ?");
$stmtUpdate->bind_param("sssi", $newStatus, $assignedOfficerName, $deployedUnit, $incidentId);

if ($stmtUpdate->execute()) {
    $stmtUpdate->close();

    // 3. Log milestone audit entry
    $note = !empty($actionNote) ? $actionNote : "Status transitioned from {$oldStatus} to {$newStatus}.";
    $stmtMilestone = $conn->prepare("INSERT INTO case_milestones (incident_id, tracking_id, status_snapshot, officer_in_charge, deployed_unit, action_note) VALUES (?, ?, ?, ?, ?, ?)");
    $stmtMilestone->bind_param("isssss", $incidentId, $referenceNumber, $newStatus, $assignedOfficerName, $deployedUnit, $note);
    $stmtMilestone->execute();
    $stmtMilestone->close();

    echo json_encode([
        'success'    => true,
        'message'    => "Case status updated to {$newStatus}.",
        'old_status' => $oldStatus,
        'new_status' => $newStatus,
        'assigned_officer_name' => $assignedOfficerName,
        'deployed_unit' => $deployedUnit
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update incident status.']);
}

$conn->close();