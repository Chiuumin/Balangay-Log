<?php
// test_triage.php
require_once __DIR__ . '/lib/triage.php';

$narrative = "Nirereklamo ko po ang kapitbahay ko dahil nag-inuman sila magdamag, sobrang lakas ng videoke, at nung sinita ko ay pinagbantaan akong papaluin ng tubo kapag nagreklamo ulit.";

$result = TriageEngine::evaluateIncident($narrative);

// Pretty print the result to terminal or browser
header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $narrative = trim($input['narrative_description'] ?? '');

    if (empty($narrative)) {
        echo json_encode(['success' => false, 'error' => 'Narrative description is required.']);
        exit;
    }

    // Run triage engine
    $triageResult = TriageEngine::evaluateIncident($narrative);

    // Format output matching your incident_reports table schema
    echo json_encode([
        'success'           => true,
        'priority_level'    => $triageResult['priority_level'],
        'ai_urgency_score'  => $triageResult['ai_urgency_score'],
        'ai_recommendation' => $triageResult['ai_recommendation'],
        'detected_triggers' => $triageResult['detected_triggers']
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}