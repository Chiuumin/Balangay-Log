<?php
// Catch fatal errors/parse errors that happen outside regular try-catch blocks
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(500);
        }
        echo json_encode([
            'success' => false,
            'error'   => 'Fatal PHP Error: ' . $error['message'] . ' in ' . basename($error['file']) . ' on line ' . $error['line']
        ], JSON_UNESCAPED_UNICODE);
    }
});

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=UTF-8');

try {
    // Automatically find triage.php whether it's in the same folder or a /lib folder
    $possiblePaths = [
        __DIR__ . '/triage.php',
        __DIR__ . '/lib/triage.php',
        __DIR__ . '/../lib/triage.php', // Add this if api/ is a subdirectory and lib/ is in the root
    ];
    
    $triagePath = '';
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            $triagePath = $path;
            break;
        }
    }

    if ($triagePath === '') {
        throw new RuntimeException("Could not locate triage.php in API directory or lib directory.");
    }

    require_once $triagePath;

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $narrative = trim((string) (
        $input['narrative_description'] ?? 
        $input['incidentNarrative'] ?? 
        $input['narrative'] ?? ''
    ));

    if ($narrative === '') {
        echo json_encode([
            'success' => false, 
            'error'   => 'Narrative description is required.'
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    // Run triage engine
    $triageResult = TriageEngine::evaluateIncident($narrative);

    $jsonOutput = json_encode([
        'success'           => true,
        'category'          => $triageResult['incident_type'] ?? 'Others',
        'priority_level'    => $triageResult['priority_level'] ?? 'Low',
        'ai_urgency_score'  => (float) ($triageResult['ai_urgency_score'] ?? 0.1),
        'ai_recommendation' => $triageResult['ai_recommendation'] ?? '',
        'detected_triggers' => $triageResult['detected_keywords'] ?? []
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    if ($jsonOutput === false) {
        throw new RuntimeException('JSON encoding failed: ' . json_last_error_msg());
    }

    echo $jsonOutput;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Server Exception: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
?>