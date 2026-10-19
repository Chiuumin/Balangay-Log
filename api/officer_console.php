<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/case_intake.php';
require_once __DIR__ . '/../lib/triage.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function officerRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * One status change = one transaction: case update + milestone + outbox event.
 */
function officerTransition(PDO $pdo, string $ref, array $t): void
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id, status FROM incident_reports WHERE reference_number = ? LIMIT 1 FOR UPDATE');
        $st->execute([$ref]);
        $case = $st->fetch(PDO::FETCH_ASSOC);
        if (!$case) {
            $pdo->rollBack();
            officerRespond(['success' => false, 'message' => 'Incident record not found.'], 404);
        }
        $incidentId = (int) $case['id'];
        $oldStatus  = (string) $case['status'];

        $sets   = ['status = ?'];
        $params = [$t['status']];
        if (array_key_exists('unit', $t)) {
            $sets[] = 'deployed_unit = ?';
            $params[] = $t['unit'];
        }
        if (array_key_exists('recommendation', $t)) {
            $sets[] = 'ai_recommendation = ?';
            $params[] = $t['recommendation'];
        }
        $params[] = $incidentId;
        $pdo->prepare('UPDATE incident_reports SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

        $stage = $t['milestone_stage'] ?? match($t['status']) {
            'PENDING', 'UNDER_REVIEW', 'IN_PROGRESS' => 'DESK_REVIEW',
            'DISPATCHED', 'CRITICAL'                 => 'UNIT_DISPATCHED',
            'RESOLVED', 'CLOSED', 'FOR_RESOLUTION'   => 'RESOLVED',
            default                                  => 'DESK_REVIEW'
        };

        $pdo->prepare('INSERT INTO case_milestones
                (incident_id, tracking_id, status_snapshot, milestone_stage, officer_in_charge, deployed_unit, action_note, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$incidentId, $ref, $t['milestone_status'], $stage, $t['officer'], $t['milestone_unit'] ?? null, $t['note']]);
        $milestoneId = (int) $pdo->lastInsertId();

        try {
            $pdo->prepare("INSERT INTO outbox_events (event_type, incident_id, payload) VALUES ('STATUS_CHANGED', ?, ?)")
                ->execute([$incidentId, json_encode([
                    'milestone_id' => $milestoneId,
                    'old_status'   => $oldStatus,
                    'new_status'   => $t['status'],
                ])]);
        } catch (Throwable $ignore) {}

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

$actor     = requireApiRole(['OFFICER', 'CAPTAIN']);
$actorId   = (int) $actor['id'];
$actorName = (string) ($actor['full_name'] ?? 'Desk Officer');
session_write_close();

try {
    $pdo = getDatabaseConnection();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $officerStmt = $pdo->prepare('SELECT full_name, username, email_or_phone, contact_number, user_type, profile_picture FROM users WHERE id = ? LIMIT 1');
        $officerStmt->execute([$actorId]);
        $officerProfile = $officerStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $pendingTriageCount = (int) $pdo->query("SELECT COUNT(*) FROM incident_reports WHERE status IN ('PENDING', 'UNDER_REVIEW')")->fetchColumn();
        $activeDispatchedCount = (int) $pdo->query("SELECT COUNT(*) FROM incident_reports WHERE status = 'DISPATCHED' OR (deployed_unit IS NOT NULL AND status NOT IN ('RESOLVED', 'CLOSED'))")->fetchColumn();
        $underInvestigationCount = (int) $pdo->query("SELECT COUNT(*) FROM incident_reports WHERE status = 'IN_PROGRESS'")->fetchColumn();
        $resolvedTodayCount = (int) $pdo->query("SELECT COUNT(*) FROM incident_reports WHERE status = 'RESOLVED' AND DATE(created_at) = CURDATE()")->fetchColumn();

        $criticalStmt = $pdo->query("
            SELECT reference_number, complainant_name, incident_type, purok, narrative_description 
            FROM incident_reports 
            WHERE (priority_level = 'Critical' OR status = 'CRITICAL') 
              AND status <> 'RESOLVED' 
              AND (deployed_unit IS NULL OR deployed_unit = '') 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $criticalCase = $criticalStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $triageStmt = $pdo->query("
            SELECT id, reference_number, complainant_name, complainant_phone, incident_type, narrative_description, purok, priority_level, status, ai_urgency_score, ai_recommendation, created_at 
            FROM incident_reports 
            WHERE status IN ('PENDING', 'UNDER_REVIEW') 
            ORDER BY created_at DESC 
            LIMIT 30
        ");
        $triageQueue = $triageStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $urgentStmt = $pdo->query("
            SELECT id, reference_number, complainant_name, complainant_phone, incident_type, narrative_description, purok, priority_level, status, deployed_unit, created_at 
            FROM incident_reports 
            WHERE priority_level IN ('Critical', 'High') AND status NOT IN ('RESOLVED', 'CLOSED') 
            ORDER BY (priority_level = 'Critical') DESC, created_at DESC
        ");
        $urgentCases = $urgentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $luponCases = $pdo->query("
            SELECT id, reference_number, complainant_name, incident_type, narrative_description, purok, status, deployed_unit AS assigned_officer, created_at 
            FROM incident_reports 
            WHERE status = 'FOR_RESOLUTION' OR incident_type LIKE '%Dispute%' OR incident_type LIKE '%Altercation%'
            ORDER BY created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $tanods = [
            ['id' => 1, 'name' => 'Ramon Diaz', 'station' => 'Barangay Base', 'status' => 'AVAILABLE'],
            ['id' => 2, 'name' => 'Elena Flores', 'station' => 'Patrol 1', 'status' => 'AVAILABLE'],
            ['id' => 3, 'name' => 'Nestor Lim', 'station' => 'Purok 2', 'status' => 'DEPLOYED'],
            ['id' => 4, 'name' => 'Lito Navarro', 'station' => 'Zone 3', 'status' => 'DEPLOYED'],
            ['id' => 5, 'name' => 'Mario Aquino', 'station' => 'Base Station', 'status' => 'OFF_DUTY']
        ];

        $allCases = $pdo->query("
            SELECT id, reference_number, complainant_name, complainant_phone, incident_type, purok, priority_level, status, ai_recommendation, deployed_unit, created_at 
            FROM incident_reports 
            ORDER BY created_at DESC 
            LIMIT 100
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $notifications = $pdo->query("
            SELECT reference_number, incident_type, complainant_name, purok, priority_level, status, created_at 
            FROM incident_reports 
            WHERE status IN ('PENDING', 'UNDER_REVIEW', 'CRITICAL') 
            ORDER BY (priority_level = 'Critical' OR status = 'CRITICAL') DESC, created_at DESC 
            LIMIT 15
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        officerRespond([
            'success' => true,
            'officer' => [
                'full_name' => $officerProfile['full_name'] ?? 'Barangay Officer',
                'contact_number' => $officerProfile['contact_number'] ?? '',
                'profile_picture' => $officerProfile['profile_picture'] ?? null
            ],
            'metrics' => [
                'pending_triage' => $pendingTriageCount,
                'active_dispatched' => $activeDispatchedCount,
                'under_investigation' => $underInvestigationCount,
                'resolved_today' => $resolvedTodayCount
            ],
            'critical_case' => $criticalCase,
            'notifications' => $notifications,
            'triage_queue' => $triageQueue,
            'urgent_cases' => $urgentCases,
            'lupon_cases' => $luponCases,
            'tanods' => $tanods,
            'all_cases' => $allCases
        ]);
    }

    if ($method !== 'POST') {
        officerRespond(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $rawInput = file_get_contents('php://input');
    $jsonDecoded = json_decode($rawInput, true);
    $input = is_array($jsonDecoded) ? $jsonDecoded : $_POST;

    if (!is_array($input)) {
        officerRespond(['success' => false, 'message' => 'Invalid request data format.'], 400);
    }

    $action    = trim((string) ($input['action'] ?? ''));
    $refNumber = trim((string) ($input['reference_number'] ?? ''));

    $needRef = function () use ($refNumber): void {
        if ($refNumber === '') {
            officerRespond(['success' => false, 'message' => 'Reference number required.'], 422);
        }
    };
    $tooLong = function (string $value, int $max, string $label): void {
        if (mb_strlen($value) > $max) {
            officerRespond(['success' => false, 'message' => "{$label} is too long (max {$max} characters)."], 422);
        }
    };

    switch ($action) {
        case 'accept_blotter':
            $needRef();
            $customNote = trim((string) ($input['note'] ?? ''));
            $note = ($customNote !== '') ? $customNote : 'Accepted from desk triage into official blotter.';
            $tooLong($note, 1000, 'Desk note');

            officerTransition($pdo, $refNumber, [
                'status'           => 'IN_PROGRESS',
                'milestone_status' => 'IN_PROGRESS',
                'milestone_stage'  => 'DESK_REVIEW',
                'officer'          => $actorName,
                'note'             => $note,
            ]);
            officerRespond(['success' => true, 'message' => "Case #{$refNumber} accepted into active blotter."]);

        case 'dispatch_tanod':
            $needRef();
            $unit  = trim((string) ($input['deployed_unit'] ?? ''));
            $notes = trim((string) ($input['dispatch_notes'] ?? ''));
            if ($unit === '')  $unit  = 'Tanod Patrol Unit';
            if ($notes === '') $notes = 'Immediate responder unit dispatched on-site.';
            $tooLong($unit, 100, 'Unit name');
            $tooLong($notes, 1000, 'Dispatch notes');

            officerTransition($pdo, $refNumber, [
                'status'           => 'DISPATCHED',
                'milestone_status' => 'DISPATCHED',
                'milestone_stage'  => 'UNIT_DISPATCHED',
                'officer'          => $actorName,
                'unit'             => $unit,
                'milestone_unit'   => $unit,
                'recommendation'   => "Unit Dispatched: {$unit} assigned for on-site assessment.",
                'note'             => $notes,
            ]);

            try {
                $caseId = (int) $pdo->query("SELECT id FROM incident_reports WHERE reference_number = " . $pdo->quote($refNumber))->fetchColumn();
                if ($caseId > 0) {
                    $pdo->prepare('INSERT INTO tanod_dispatch_logs (incident_id, unit, dispatched_by, status, dispatched_at) VALUES (?, ?, ?, "DISPATCHED", NOW())')
                        ->execute([$caseId, $unit, $actorId]);
                }
            } catch (Throwable $e) {}

            officerRespond(['success' => true, 'message' => "Tanod unit successfully dispatched for #{$refNumber}."]);

        case 'endorse_pnp':
            $needRef();
            $policeStation = trim((string) ($input['police_station'] ?? 'PNP Municipal Police Station'));
            $blotterNumber = trim((string) ($input['blotter_number'] ?? ''));
            $reason        = trim((string) ($input['reason'] ?? 'Active threat / firearm involved exceeding barangay tanod scope.'));
            $tooLong($policeStation, 120, 'Police station name');
            $tooLong($reason, 500, 'Escalation remarks');

            officerTransition($pdo, $refNumber, [
                'status'           => 'CRITICAL',
                'milestone_status' => 'ESCALATED_PNP',
                'milestone_stage'  => 'DESK_REVIEW',
                'officer'          => $actorName,
                'unit'             => $policeStation,
                'recommendation'   => "Case formally endorsed to {$policeStation}. Legal transmittal active.",
                'note'             => "Case escalated to {$policeStation}. Reason: {$reason}" . ($blotterNumber ? " | Police Blotter/PIRF: {$blotterNumber}" : ''),
            ]);

            try {
                $caseId = (int) $pdo->query("SELECT id FROM incident_reports WHERE reference_number = " . $pdo->quote($refNumber))->fetchColumn();
                if ($caseId > 0) {
                    $endStmt = $pdo->prepare('INSERT INTO endorsements (case_id, type, status, referred_by_user_id, referred_at, remarks)
                                             VALUES (?, "PNP_TRANSMITTAL", "ENDORSED", ?, NOW(), ?)');
                    $endStmt->execute([$caseId, $actorId, "{$policeStation} - {$reason}" . ($blotterNumber ? " [Ref: {$blotterNumber}]" : '')]);
                }
            } catch (Throwable $e) {}

            officerRespond(['success' => true, 'message' => "Case #{$refNumber} officially escalated to {$policeStation}."]);

        case 'endorse_lupon':
            $needRef();
            $mediator = trim((string) ($input['mediator'] ?? ''));
            $schedule = trim((string) ($input['schedule'] ?? ''));
            if ($mediator === '') $mediator = 'Barangay Lupon Panel';
            if ($schedule === '') $schedule = date('Y-m-d 10:00:00', strtotime('+3 days'));
            $tooLong($mediator, 120, 'Mediator name');
            if (strtotime($schedule) === false) {
                officerRespond(['success' => false, 'message' => 'Invalid mediation schedule.'], 422);
            }

            officerTransition($pdo, $refNumber, [
                'status'           => 'FOR_RESOLUTION',
                'milestone_status' => 'FOR_RESOLUTION',
                'milestone_stage'  => 'RESOLVED',
                'officer'          => $mediator,
                'unit'             => $mediator,
                'note'             => "Endorsed to Katarungang Pambarangay. Mediation scheduled on {$schedule} with {$mediator}.",
            ]);
            officerRespond(['success' => true, 'message' => "Case #{$refNumber} endorsed to Lupon mediation."]);

        case 'settle_lupon':
            $needRef();
            $resolutionNotes = trim((string) ($input['notes'] ?? ''));
            if ($resolutionNotes === '') $resolutionNotes = 'Amicable settlement reached under Katarungang Pambarangay.';
            $tooLong($resolutionNotes, 1000, 'Resolution notes');

            officerTransition($pdo, $refNumber, [
                'status'           => 'RESOLVED',
                'milestone_status' => 'RESOLVED',
                'milestone_stage'  => 'RESOLVED',
                'officer'          => $actorName,
                'recommendation'   => 'Case settled amicably during mediation.',
                'note'             => $resolutionNotes,
            ]);
            officerRespond(['success' => true, 'message' => "Case #{$refNumber} marked as resolved and settled."]);

        case 'flag_reject':
            $needRef();
            officerTransition($pdo, $refNumber, [
                'status'           => 'RESOLVED',
                'milestone_status' => 'CLOSED',
                'milestone_stage'  => 'RESOLVED',
                'officer'          => $actorName,
                'recommendation'   => 'Flagged / closed as non-actionable or duplicate during triage.',
                'note'             => 'Case archived and closed at frontline desk.',
            ]);
            officerRespond(['success' => true, 'message' => "Case #{$refNumber} flagged and closed."]);

        // -------------------------------------------------------------
        // GET CASE REVIEW DETAILS (Deep AI Inspection for Modal)
        // -------------------------------------------------------------
        case 'get_case_review_details':
            $needRef();

            $stmt = $pdo->prepare("
                SELECT ir.*, u.full_name AS encoder_name
                FROM incident_reports ir
                LEFT JOIN users u ON ir.assigned_officer_id = u.id
                WHERE ir.reference_number = :ref
                LIMIT 1
            ");
            $stmt->execute([':ref' => $refNumber]);
            $case = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$case) {
                officerRespond(['success' => false, 'message' => 'Case not found.'], 404);
            }

            // Check if AI analysis JSON exists; if not, generate and store it
            $aiData = !empty($case['ai_analysis_json']) ? json_decode($case['ai_analysis_json'], true) : null;
            if (!$aiData && class_exists('TriageEngine')) {
                $aiData = TriageEngine::evaluateIncident($case['narrative_description']);
                
                try {
                    $upd = $pdo->prepare("
                        UPDATE incident_reports 
                        SET ai_analysis_json = :json, legal_routing = :lr, weapon_detected = :wp, vawc_minor_flag = :vf
                        WHERE id = :id
                    ");
                    $upd->execute([
                        ':json' => json_encode($aiData, JSON_UNESCAPED_UNICODE),
                        ':lr'   => $aiData['legal_routing'] ?? null,
                        ':wp'   => $aiData['weapon_flag'] ?? 'NONE',
                        ':vf'   => !empty($aiData['vawc_minor_flag']) ? 1 : 0,
                        ':id'   => $case['id']
                    ]);
                } catch (Throwable $ignore) {}
            }

            // Cross-check: Find prior cases in same Purok or Complainant name within last 12 months
            $histStmt = $pdo->prepare("
                SELECT reference_number, incident_type, status, created_at 
                FROM incident_reports 
                WHERE (complainant_name = :cname OR purok = :purok)
                  AND id <> :current_id
                ORDER BY created_at DESC 
                LIMIT 5
            ");
            $histStmt->execute([
                ':cname'      => $case['complainant_name'],
                ':purok'      => $case['purok'],
                ':current_id' => $case['id']
            ]);
            $historyMatches = $histStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            officerRespond([
                'success'         => true,
                'case'            => $case,
                'ai_analysis'     => $aiData,
                'history_matches' => $historyMatches
            ]);

        // -------------------------------------------------------------
        // ENCODE CASE: Automated AI Categorization & Severity
        // -------------------------------------------------------------
        case 'encode_case':
            $narrative = trim((string) ($input['narrative_description'] ?? $input['narrative'] ?? ''));
            if ($narrative === '') {
                officerRespond(['success' => false, 'message' => 'Narrative description is required.'], 400);
            }

            // Execute TriageEngine evaluation
            $analysis = [
                'incident_type'     => 'General Incident',
                'priority_level'    => 'Low',
                'ai_urgency_score'  => 0.10,
                'legal_routing'     => 'Standard Desk Blotter Recording',
                'weapon_flag'       => 'NONE',
                'vawc_minor_flag'   => false,
                'medical_flag'      => 'None indicated.',
                'substance_flag'    => 'None indicated.',
                'detected_keywords' => [],
                'trigger_excerpts'  => [],
                'reasoning'         => 'Brief narrative assessed under standard intake.',
                'ai_recommendation' => 'Proceed with regular verification.'
            ];

            if (class_exists('TriageEngine')) {
                try {
                    $aiResult = TriageEngine::evaluateIncident($narrative);
                    if (is_array($aiResult)) {
                        $analysis = array_merge($analysis, $aiResult);
                    }
                } catch (Throwable $t) {
                    error_log('TriageEngine execution note: ' . $t->getMessage());
                }
            }

            // AI determines both Category and Priority Level
            $finalCategory = !empty($input['incident_type']) && $input['incident_type'] !== 'General Incident' 
                ? trim((string)$input['incident_type']) 
                : ($analysis['incident_type'] ?? 'General Incident');

            $aiPriority = $analysis['priority_level'] ?? 'Low';
            $officerPriority = !empty($input['priority_level']) ? trim((string)$input['priority_level']) : $aiPriority;

            // Downgrade check
            $weights = ['Low' => 1, 'Moderate' => 2, 'Medium' => 2, 'High' => 3, 'Critical' => 4];
            $aiWeight = $weights[$aiPriority] ?? 1;
            $requestedWeight = $weights[$officerPriority] ?? $aiWeight;

            if ($requestedWeight < $aiWeight) {
                $justification = trim((string) ($input['overrideJustification'] ?? ''));
                if ($justification === '') {
                    officerRespond([
                        'success'           => false,
                        'requires_override' => true,
                        'message'           => 'Lowering the severity requires a factual justification[cite: 12].',
                        'ai'                => [
                            'priority'   => $aiPriority,
                            'source'     => 'AI+RULES',
                            'confidence' => (float) ($analysis['ai_urgency_score'] ?? 0.8),
                            'triggers'   => $analysis['detected_keywords'] ?? [],
                            'rationale'  => $analysis['reasoning'] ?? ''
                        ]
                    ], 409);
                }

                if (mb_strlen($justification) < 12 || in_array(strtolower($justification), ['mistake', 'error', 'wrong', 'settled', 'test'])) {
                    officerRespond([
                        'success'           => false,
                        'override_rejected' => true,
                        'message'           => 'Justification rejected: please state verifiable details (e.g., responder name or cleared threat)[cite: 12].',
                        'ai'                => [
                            'priority'  => $aiPriority,
                            'triggers'  => $analysis['detected_keywords'] ?? [],
                            'rationale' => $analysis['reasoning'] ?? ''
                        ]
                    ], 422);
                }
            }

            $finalPriority = ($requestedWeight < $aiWeight) ? $officerPriority : $aiPriority;
            $refNumber = 'IR-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO incident_reports (
                        reference_number, complainant_name, complainant_phone, incident_type,
                        narrative_description, incident_datetime, purok, priority_level,
                        ai_urgency_score, ai_recommendation, ai_analysis_json, legal_routing,
                        weapon_detected, vawc_minor_flag, status, assigned_officer_id, assigned_officer_name
                    ) VALUES (
                        :ref, :name, :phone, :type,
                        :narrative, NOW(), :purok, :priority,
                        :ai_score, :recommendation, :analysis_json, :legal_routing,
                        :weapon_flag, :vawc_flag, 'PENDING', :officer_id, :officer_name
                    )
                ");

                $stmt->execute([
                    ':ref'            => $refNumber,
                    ':name'           => trim((string) ($input['complainant_name'] ?? 'Walk-in Resident')),
                    ':phone'          => trim((string) ($input['complainant_phone'] ?? 'N/A')),
                    ':type'           => $finalCategory,
                    ':narrative'      => $narrative,
                    ':purok'          => trim((string) ($input['purok'] ?? 'Purok 1')),
                    ':priority'       => $finalPriority,
                    ':ai_score'       => (float) ($analysis['ai_urgency_score'] ?? 0.00),
                    ':recommendation' => $analysis['ai_recommendation'] ?? 'Standard intake',
                    ':analysis_json'  => json_encode($analysis, JSON_UNESCAPED_UNICODE),
                    ':legal_routing'  => $analysis['legal_routing'] ?? null,
                    ':weapon_flag'    => $analysis['weapon_flag'] ?? 'NONE',
                    ':vawc_flag'      => !empty($analysis['vawc_minor_flag']) ? 1 : 0,
                    ':officer_id'     => $actorId,
                    ':officer_name'   => $actorName
                ]);

                $incidentId = (int) $pdo->lastInsertId();

                $msStmt = $pdo->prepare("
                    INSERT INTO case_milestones (
                        incident_id, tracking_id, status_snapshot, officer_in_charge, action_note
                    ) VALUES (
                        :inc_id, :track_id, 'PENDING', :officer, :note
                    )
                ");
                $msStmt->execute([
                    ':inc_id'   => $incidentId,
                    ':track_id' => $refNumber,
                    ':officer'  => $actorName,
                    ':note'     => "Incident encoded. AI Category: {$finalCategory} | Assessed Priority: {$finalPriority}."
                ]);

                if ($actorId > 0) {
                    $auditStmt = $pdo->prepare("
                        INSERT INTO audit_logs (actor_user_id, action, details)
                        VALUES (:actor, 'ENCODE_INCIDENT', :details)
                    ");
                    $auditStmt->execute([
                        ':actor'   => $actorId,
                        ':details' => "Encoded incident record #{$refNumber} ({$finalCategory} - {$finalPriority})."
                    ]);
                }

                $pdo->commit();

                officerRespond([
                    'success'          => true,
                    'message'          => "Case {$refNumber} successfully recorded.",
                    'reference_number' => $refNumber,
                    'priority'         => $finalPriority,
                    'data'             => [
                        'reference_number' => $refNumber,
                        'priority'         => $finalPriority,
                        'ai'               => [
                            'priority'   => $aiPriority,
                            'source'     => 'AI+RULES',
                            'confidence' => (float) ($analysis['ai_urgency_score'] ?? 0.1),
                            'triggers'   => $analysis['detected_keywords'] ?? [],
                            'rationale'  => $analysis['reasoning'] ?? '',
                            'notice'     => $analysis['ai_recommendation'] ?? ''
                        ]
                    ]
                ]);

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

        case 'profile_update':
            $name = trim((string) ($input['full_name'] ?? ''));
            $contact = trim((string) ($input['contact_number'] ?? ''));

            if ($name === '') {
                officerRespond(['success' => false, 'message' => 'Full name cannot be blank.'], 422);
            }

            $picturePath = null;
            if (!empty($_FILES['profile_picture']['tmp_name'])) {
                $picture = $_FILES['profile_picture'];
                if ($picture['error'] !== UPLOAD_ERR_OK || $picture['size'] > 2 * 1024 * 1024) {
                    officerRespond(['success' => false, 'message' => 'Profile picture must be smaller than 2 MB.'], 422);
                }

                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($picture['tmp_name']);
                $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!isset($extensions[$mime])) {
                    officerRespond(['success' => false, 'message' => 'Use a JPG, PNG, or WebP profile picture.'], 422);
                }

                $directory = __DIR__ . '/../uploads/profiles';
                if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                    officerRespond(['success' => false, 'message' => 'Could not create profile images directory.'], 500);
                }

                $filename = 'officer_' . $actorId . '_' . bin2hex(random_bytes(6)) . '.' . $extensions[$mime];
                if (!move_uploaded_file($picture['tmp_name'], $directory . '/' . $filename)) {
                    officerRespond(['success' => false, 'message' => 'Could not save the profile picture.'], 500);
                }
                $picturePath = 'uploads/profiles/' . $filename;
            }

            if ($picturePath !== null) {
                $stmt = $pdo->prepare('UPDATE users SET full_name = ?, contact_number = ?, profile_picture = ? WHERE id = ?');
                $stmt->execute([$name, $contact, $picturePath, $actorId]);
            } else {
                $stmt = $pdo->prepare('UPDATE users SET full_name = ?, contact_number = ? WHERE id = ?');
                $stmt->execute([$name, $contact, $actorId]);
            }

            $_SESSION['full_name'] = $name;
            officerRespond(['success' => true, 'message' => 'Profile information updated successfully.']);

        case 'password_update':
            $currentPassword = (string) ($input['current_password'] ?? '');
            $newPassword = (string) ($input['new_password'] ?? '');
            $confirmPassword = (string) ($input['confirm_password'] ?? '');

            if (strlen($newPassword) < 8 || $newPassword !== $confirmPassword) {
                officerRespond(['success' => false, 'message' => 'New passwords must match and be at least 8 characters.'], 422);
            }

            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
            $stmt->execute([$actorId]);
            $hash = (string) $stmt->fetchColumn();

            if (!password_verify($currentPassword, $hash)) {
                officerRespond(['success' => false, 'message' => 'Current password is incorrect.'], 422);
            }

            $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $actorId]);

            officerRespond(['success' => true, 'message' => 'Password changed successfully.']);

        default:
            officerRespond(['success' => false, 'message' => 'Unknown officer action.'], 422);
    }
} catch (PDOException $e) {
    error_log('[officer_console] database error: ' . $e->getMessage());
    officerRespond(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
} catch (Throwable $e) {
    error_log('[officer_console] ' . $e->getMessage());
    officerRespond(['success' => false, 'message' => 'Server error: ' . $e->getMessage()], 500);
}