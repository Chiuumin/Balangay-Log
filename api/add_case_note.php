<?php
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
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$referenceNumber = trim($input['reference_number'] ?? '');
$actionNote = trim($input['action_note'] ?? '');
$officerInCharge = trim($input['officer_in_charge'] ?? 'Dispatcher');

if ($referenceNumber === '' || $actionNote === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Case reference and note are required.']);
    exit();
}

try {
    $stmtCase = $conn->prepare("SELECT id, status, assigned_officer_name, deployed_unit FROM incident_reports WHERE reference_number = ? LIMIT 1");
    $stmtCase->bind_param("s", $referenceNumber);
    $stmtCase->execute();
    $case = $stmtCase->get_result()->fetch_assoc();
    $stmtCase->close();

    if (!$case) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Incident record not found.']);
        $conn->close();
        exit();
    }

    $incidentId = (int)$case['id'];
    $status = $case['status'];
    $assignedOfficer = $case['assigned_officer_name'];
    $deployedUnit = $case['deployed_unit'];
    $officerInCharge = substr($officerInCharge, 0, 120);
    $stmtNote = $conn->prepare("INSERT INTO case_milestones (incident_id, tracking_id, status_snapshot, officer_in_charge, deployed_unit, action_note) VALUES (?, ?, ?, ?, ?, ?)");
    $stmtNote->bind_param("isssss", $incidentId, $referenceNumber, $status, $officerInCharge, $deployedUnit, $actionNote);
    $stmtNote->execute();
    $stmtNote->close();

    echo json_encode(['success' => true, 'message' => 'Note added to the case timeline.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to add note to the case timeline.']);
}

$conn->close();