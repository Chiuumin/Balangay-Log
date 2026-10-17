<?php
// Display errors immediately to debug issues
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Convert MySQLi errors into catchable exceptions
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli("127.0.0.1", "root", "", "balangaylog_db", 3306);
    $conn->set_charset("utf8mb4");
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . $e->getMessage()
    ]);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing JSON payload.']);
    exit();
}

// -------------------------------------------------------------
// STRICT FIELD EXTRACTION & SANITIZATION
// -------------------------------------------------------------
$name      = trim($input['complainantName'] ?? '');
$phone     = trim($input['complainantPhone'] ?? '');
$purok     = trim($input['purok'] ?? '');
$street    = trim($input['streetAddress'] ?? '');
$type      = trim($input['incidentCategory'] ?? ($input['category'] ?? ''));
$title     = trim($input['incidentTitle'] ?? '');
$narrative = trim($input['incidentNarrative'] ?? ($input['narrative'] ?? ''));

// Priority & Audit Payload
$userPriority          = trim($input['priorityLevel'] ?? ($input['priority'] ?? ''));
$aiDetectedPriority    = trim($input['aiDetectedPriority'] ?? 'Low');
$isPriorityOverridden  = !empty($input['isPriorityOverridden']) ? 1 : 0;
$overrideJustification = !empty($input['overrideJustification']) ? trim($input['overrideJustification']) : null;

// -------------------------------------------------------------
// INPUT INTEGRITY & VALIDATION GATES
// -------------------------------------------------------------
if (empty($name) || strlen($name) < 3) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid complainant name is required (minimum 3 characters).']);
    exit();
}

if (!preg_match('/^09\d{9}$/', $phone)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A valid 11-digit Philippine contact number (09XXXXXXXXX) is required.']);
    exit();
}

if (empty($purok) || $purok === 'Select Purok') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please select a valid Purok / Zone.']);
    exit();
}

if (empty($type) || $type === 'General Incident') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please select a valid incident category.']);
    exit();
}

if (empty($title) || strlen($title) < 5) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Incident title must be descriptive (at least 5 characters).']);
    exit();
}

if (empty($narrative) || strlen($narrative) < 20) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Narrative description must provide substantial detail (at least 20 characters).']);
    exit();
}

$validPriorities = ['Low', 'High', 'Critical'];
if (!in_array($userPriority, $validPriorities)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid priority level specified.']);
    exit();
}

// Ensure justified downgrade audit check
if ($isPriorityOverridden && empty($overrideJustification)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Priority override requires a valid justification.']);
    exit();
}

// -------------------------------------------------------------
// GEO-COORDINATES & SCORING
// -------------------------------------------------------------
$lat = floatval($input['lat'] ?? 14.545300);
$lng = floatval($input['lng'] ?? 120.573900);
if ($lat == 0.0) $lat = 14.545300;
if ($lng == 0.0) $lng = 120.573900;

$finalPriority = $userPriority;

if ($finalPriority === 'Critical') {
    $urgencyScore = 85.00;
    $recommendation = 'Critical threat detected. Dispatch Immediate Response Unit and alert PNP.';
} elseif ($finalPriority === 'High') {
    $urgencyScore = 65.00;
    $recommendation = 'Active incident. Deploy Barangay Tanod unit for on-site verification.';
} else {
    $urgencyScore = 20.00;
    $recommendation = 'Standard administrative intake. Log for regular review.';
}

// -------------------------------------------------------------
// TRACKING REFERENCE & INSERT PREPARATION
// -------------------------------------------------------------
$year = date('Y');
$countRes = $conn->query("SELECT COUNT(*) as total FROM incident_reports");
$rowCount = ($countRes) ? ($countRes->fetch_assoc()['total'] + 1) : 1;
$trackingId = sprintf("TRK-%s-%03d", $year, $rowCount);

$initialStatus = ($finalPriority === 'Critical') ? 'CRITICAL' : 'PENDING';
$dt = date('Y-m-d H:i:s');

$scenarioType = strtoupper(str_replace(' ', '_', preg_replace('/[^A-Za-z0-9 ]/', '', ($input['intakeChannel'] ?? 'Walk-In Desk Blotter'))));
if (!in_array($scenarioType, ['WALK_IN', 'ONLINE_PORTAL', 'EMERGENCY'], true)) {
    $scenarioType = 'WALK_IN';
}

// Match the actual schema: these are the columns that exist in the database.
$stmt = $conn->prepare("INSERT INTO incident_reports 
    (reference_number, complainant_name, complainant_phone, incident_type, narrative_description, incident_datetime, purok, latitude, longitude, scenario_type, verification_level, ai_urgency_score, priority_level, ai_recommendation, status) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'VERIFIED', ?, ?, ?, ?)");

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
    exit();
}

// Exactly 14 type specifiers matching the 14 bound parameters below:
// s (1: reference_number)
// s (2: complainant_name)
// s (3: complainant_phone)
// s (4: incident_type)
// s (5: narrative_description)
// s (6: incident_datetime)
// s (7: purok)
// d (8: latitude)
// d (9: longitude)
// s (10: scenario_type)
// d (11: ai_urgency_score)
// s (12: priority_level)
// s (13: ai_recommendation)
// s (14: status)
$typeDefinition = "sssssssddsdsss";

$stmt->bind_param(
    $typeDefinition,
    $trackingId,
    $name,
    $phone,
    $type,
    $narrative,
    $dt,
    $purok,
    $lat,
    $lng,
    $scenarioType,
    $urgencyScore,
    $finalPriority,
    $recommendation,
    $initialStatus
);

if ($stmt->execute()) {
    $newIncidentId = $stmt->insert_id;

    $actionNote = "Initial report logged as {$type}. Priority: {$finalPriority} (Score: {$urgencyScore}).";
    if ($isPriorityOverridden) {
        $actionNote .= " [AI Downgraded: {$aiDetectedPriority} -> {$finalPriority} | Reason: {$overrideJustification}]";
    }

    $stmtM = $conn->prepare("INSERT INTO case_milestones (incident_id, tracking_id, status_snapshot, action_note) VALUES (?, ?, ?, ?)");
    if ($stmtM) {
        $stmtM->bind_param("isss", $newIncidentId, $trackingId, $initialStatus, $actionNote);
        $stmtM->execute();
        $stmtM->close();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Incident filed successfully.',
        'data' => [
            'reference_number' => $trackingId,
            'title'            => $title,
            'complainant'      => $name,
            'category'         => $type,
            'priority'         => $finalPriority,
            'status'           => $initialStatus,
            'urgency_score'    => $urgencyScore,
            'recommendation'   => $recommendation
        ]
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database insert error: ' . $stmt->error]);
}

$stmt->close();
$conn->close();