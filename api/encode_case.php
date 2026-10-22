<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/case_intake.php';

requireJsonPost();
$inputPre = json_decode(file_get_contents('php://input'), true);
$actionPre = (string) ($inputPre['action'] ?? '');
$allowedRoles = in_array($actionPre, ['inspect_triage', 'inspect_triage_ai'], true) 
    ? ['RESIDENT', 'OFFICER', 'CAPTAIN', 'ADMIN', 'SYSTEM_ADMIN'] 
    : ['OFFICER', 'CAPTAIN'];

$actor = requireApiRole($allowedRoles);

$sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$tok  = (string) ($_SESSION['csrf_token'] ?? '');
if ($tok === '' || !hash_equals($tok, $sent)) {
    jsonError(403, 'Security token expired. Reload the page and try again.');
}
session_write_close();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) jsonError(400, 'Invalid or missing JSON payload.');
$action = (string) ($input['action'] ?? 'encode_case');

try {
    if ($action === 'inspect_triage') {
        $narrative = intake_pick($input, ['narrative_description', 'incidentNarrative', 'narrative']);
        if ($narrative === '') {
            echo json_encode(['success' => false, 'error' => 'Narrative is empty.']);
            exit;
        }
        echo json_encode(['success' => true] + analyze_narrative($narrative, intake_pick($input, ['incident_type', 'category'])), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'inspect_triage_ai') {
        $narrative = intake_pick($input, ['narrative_description', 'incidentNarrative', 'narrative']);
        if ($narrative === '') {
            echo json_encode(['success' => false, 'error' => 'Narrative is empty.']);
            exit;
        }
        $narrative = mb_substr($narrative, 0, 5000);
        $a      = analyze_narrative($narrative, intake_pick($input, ['incident_type', 'category']));
        $aiUsed = false;
        $aiNote = null;

        if (!ai_enabled()) {
            $aiNote = 'AI is turned off in config/secrets.php.';
        } elseif (mb_strlen($narrative) < 4) {
            $aiNote = 'Narrative too short for an AI check.';
        } else {
            try {
                [$a] = ai_refine_analysis($a, $narrative, 25);
                $aiUsed = true;
            } catch (Throwable $e) {
                error_log('[inspect_triage_ai] ' . $e->getMessage());
                $aiNote = 'AI unavailable; showing the rules result.';
            }
        }
        echo json_encode(['success' => true, 'ai_used' => $aiUsed, 'ai_note' => $aiNote] + $a, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'encode_case') {
        $r = encode_case_run($input, $actor);
        http_response_code($r['status']);
        echo json_encode($r['body'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    jsonError(400, 'Unknown action.');
} catch (Throwable $e) {
    error_log('[encode_case] ' . $e->getMessage());
    jsonError(500, 'The request could not be completed.');
}
?>