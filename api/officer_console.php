<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function officerRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    require_once __DIR__ . '/../auth.php';
    if (file_exists(__DIR__ . '/../lib/triage.php')) {
        require_once __DIR__ . '/../lib/triage.php';
    }
} catch (Throwable $e) {
    officerRespond(['success' => false, 'message' => 'Include error: ' . $e->getMessage()], 500);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userType = $_SESSION['user_type'] 
         ?? $_SESSION['role'] 
         ?? ($_SESSION['user']['user_type'] ?? '') 
         ?? ($_SESSION['user']['role'] ?? '');

$allowedRoles = ['OFFICER', 'CAPTAIN', 'ADMIN', 'SYSTEM_ADMIN'];

if (!in_array(strtoupper((string) $userType), $allowedRoles, true)) {
    $userId = (int) ($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0));
    if ($userId > 0) {
        try {
            $checkDb = getDatabaseConnection();
            $roleStmt = $checkDb->prepare("SELECT user_type FROM users WHERE id = ? LIMIT 1");
            $roleStmt->execute([$userId]);
            $dbRole = strtoupper((string) $roleStmt->fetchColumn());
            if (in_array($dbRole, $allowedRoles, true)) {
                $_SESSION['user_type'] = $dbRole;
                $userType = $dbRole;
            }
        } catch (Throwable $e) {}
    }
}

if (!in_array(strtoupper((string) $userType), $allowedRoles, true)) {
    officerRespond(['success' => false, 'message' => 'Unauthorized access. Please log in again.'], 401);
}

$actorId   = (int) ($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0));
$actorName = (string) ($_SESSION['full_name'] ?? $_SESSION['user_full_name'] ?? ($_SESSION['user']['full_name'] ?? 'Desk Officer'));

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

        $pdo->prepare('INSERT INTO case_milestones
                (incident_id, tracking_id, status_snapshot, officer_in_charge, deployed_unit, action_note, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$incidentId, $ref, $t['milestone_status'], $t['officer'], $t['milestone_unit'] ?? null, $t['note']]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

try {
    $pdo = getDatabaseConnection();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $officerProfile = [];
        try {
            $officerStmt = $pdo->prepare('SELECT full_name, username, email_or_phone, contact_number, user_type, profile_picture FROM users WHERE id = ? LIMIT 1');
            $officerStmt->execute([$actorId]);
            $officerProfile = $officerStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}

        $pendingTriageCount = (int) ($pdo->query("SELECT COUNT(*) FROM incident_reports WHERE status IN ('PENDING', 'UNDER_REVIEW')")->fetchColumn() ?: 0);
        $activeDispatchedCount = (int) ($pdo->query("SELECT COUNT(*) FROM incident_reports WHERE status = 'DISPATCHED' OR (deployed_unit IS NOT NULL AND status NOT IN ('RESOLVED', 'CLOSED'))")->fetchColumn() ?: 0);
        $underInvestigationCount = (int) ($pdo->query("SELECT COUNT(*) FROM incident_reports WHERE status = 'IN_PROGRESS'")->fetchColumn() ?: 0);
        $resolvedTodayCount = (int) ($pdo->query("SELECT COUNT(*) FROM incident_reports WHERE status = 'RESOLVED' AND DATE(created_at) = CURDATE()")->fetchColumn() ?: 0);

        $criticalCase = null;
        try {
            $criticalStmt = $pdo->query("
                SELECT reference_number, complainant_name, incident_type, purok, narrative_description 
                FROM incident_reports 
                WHERE (priority_level = 'Critical' OR status = 'CRITICAL') 
                  AND status <> 'RESOLVED' 
                  AND (deployed_unit IS NULL OR deployed_unit = '') 
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $criticalCase = $criticalStmt ? $criticalStmt->fetch(PDO::FETCH_ASSOC) : null;
        } catch (Throwable $e) {}

        $triageQueue = [];
        try {
            $triageStmt = $pdo->query("
                SELECT * FROM incident_reports 
                WHERE status IN ('PENDING', 'UNDER_REVIEW') 
                ORDER BY created_at DESC 
                LIMIT 30
            ");
            $triageQueue = $triageStmt ? $triageStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {}

        $urgentCases = [];
        try {
            $urgentStmt = $pdo->query("
                SELECT * FROM incident_reports 
                WHERE priority_level IN ('Critical', 'High') AND status NOT IN ('RESOLVED', 'CLOSED') 
                ORDER BY (priority_level = 'Critical') DESC, created_at DESC
            ");
            $urgentCases = $urgentStmt ? $urgentStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {}

        $luponCases = [];
        try {
            $luponCases = $pdo->query("
                SELECT * FROM incident_reports 
                WHERE status = 'FOR_RESOLUTION' OR incident_type LIKE '%Dispute%' OR incident_type LIKE '%Altercation%'
                ORDER BY created_at DESC
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}

        $tanods = [];
        try {
            $tanodStmt = $pdo->query("
                SELECT id, full_name AS name, UPPER(COALESCE(status, 'AVAILABLE')) AS status, COALESCE(station, 'Patrol Beat') AS station 
                FROM tanod_personnel 
                ORDER BY id ASC
            ");
            $tanods = $tanodStmt ? $tanodStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
            $tanods = [];
        }

        $allCases = [];
        try {
            $allCases = $pdo->query("
                SELECT * FROM incident_reports 
                ORDER BY created_at DESC 
                LIMIT 100
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}

        $notifications = [];
        try {
            $notifications = $pdo->query("
                SELECT reference_number, incident_type, complainant_name, purok, priority_level, status, created_at 
                FROM incident_reports 
                WHERE status IN ('PENDING', 'UNDER_REVIEW', 'CRITICAL') 
                ORDER BY (priority_level = 'Critical' OR status = 'CRITICAL') DESC, created_at DESC 
                LIMIT 15
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}

        officerRespond([
            'success' => true,
            'officer' => [
                'full_name' => $officerProfile['full_name'] ?? $actorName,
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
                $updateTanods = $pdo->prepare("
                    UPDATE tanod_personnel 
                    SET status = 'DEPLOYED' 
                    WHERE :unitText LIKE CONCAT('%', full_name, '%')
                ");
                $updateTanods->execute([':unitText' => $unit]);
            } catch (Throwable $ignore) {}

            officerRespond(['success' => true, 'message' => "Tanod unit successfully dispatched for #{$refNumber}."]);

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

        case 'request_captain_endorsement':
            $needRef();
            $remarks = trim((string) ($input['remarks'] ?? 'Requested formal executive endorsement for case filing.'));
            
            $st = $pdo->prepare("SELECT id FROM incident_reports WHERE reference_number = ? LIMIT 1");
            $st->execute([$refNumber]);
            $incId = (int) $st->fetchColumn();

            if (!$incId) {
                officerRespond(['success' => false, 'message' => 'Incident record not found.'], 404);
            }

            $ins = $pdo->prepare("
                INSERT INTO endorsements (case_id, tracking_id, type, status, referred_by_user_id, referred_at, remarks)
                VALUES (?, ?, 'CAPTAIN_ENDORSEMENT', 'PENDING', ?, NOW(), ?)
            ");
            $ins->execute([$incId, $refNumber, $actorId, $remarks]);

            officerRespond(['success' => true, 'message' => "Captain endorsement requested for case #{$refNumber}."]);

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

        case 'get_case_review_details':
            $needRef();
            $stmt = $pdo->prepare("SELECT * FROM incident_reports WHERE reference_number = :ref LIMIT 1");
            $stmt->execute([':ref' => $refNumber]);
            $case = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$case) {
                officerRespond(['success' => false, 'message' => 'Case not found.'], 404);
            }

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

            $dupStmt = $pdo->prepare("
                SELECT reference_number, incident_type, complainant_name, created_at
                FROM incident_reports
                WHERE purok = :purok
                  AND id <> :current_id
                  AND created_at >= (NOW() - INTERVAL 2 HOUR)
                ORDER BY created_at DESC
                LIMIT 3
            ");
            $dupStmt->execute([
                ':purok'      => $case['purok'],
                ':current_id' => $case['id']
            ]);
            $recentPurokDuplicates = $dupStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $histStmt = $pdo->prepare("
                SELECT reference_number, incident_type, status, priority_level, created_at 
                FROM incident_reports 
                WHERE complainant_name = :cname
                  AND id <> :current_id
                ORDER BY created_at DESC 
                LIMIT 4
            ");
            $histStmt->execute([
                ':cname'      => $case['complainant_name'],
                ':current_id' => $case['id']
            ]);
            $complainantHistory = $histStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            officerRespond([
                'success'           => true,
                'case'              => $case,
                'ai_analysis'       => $aiData,
                'duplicates'        => $recentPurokDuplicates,
                'history_matches'   => $complainantHistory
            ]);

        case 'encode_case':
            $narrative = trim((string) ($input['narrative_description'] ?? $input['narrative'] ?? ''));
            if ($narrative === '') {
                officerRespond(['success' => false, 'message' => 'Narrative description is required.'], 400);
            }

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
                } catch (Throwable $t) {}
            }

            $finalCategory = !empty($input['incident_type']) && $input['incident_type'] !== 'General Incident' 
                ? trim((string) $input['incident_type']) 
                : ($analysis['incident_type'] ?? 'General Incident');

            $aiPriority = $analysis['priority_level'] ?? 'Low';
            $officerPriority = !empty($input['priority_level']) ? trim((string) ($input['priority_level'])) : $aiPriority;

            $weights = ['Low' => 1, 'Moderate' => 2, 'Medium' => 2, 'High' => 3, 'Critical' => 4];
            $aiWeight = $weights[$aiPriority] ?? 1;
            $requestedWeight = $weights[$officerPriority] ?? $aiWeight;

            $finalPriority = ($requestedWeight < $aiWeight) ? $officerPriority : $aiPriority;
            $refNumber = 'IR-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO incident_reports (
                        reference_number, complainant_name, complainant_phone, incident_type,
                        narrative_description, incident_datetime, purok, priority_level,
                        ai_urgency_score, ai_recommendation, status, assigned_officer_id, assigned_officer_name
                    ) VALUES (
                        :ref, :name, :phone, :type,
                        :narrative, NOW(), :purok, :priority,
                        :ai_score, :recommendation, 'PENDING', :officer_id, :officer_name
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
                    ':note'     => "Incident encoded. Assessed priority: {$finalPriority}."
                ]);

                $pdo->commit();

                officerRespond([
                    'success'          => true,
                    'message'          => "Case {$refNumber} successfully recorded.",
                    'reference_number' => $refNumber,
                    'priority'         => $finalPriority
                ]);

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

        default:
            officerRespond(['success' => false, 'message' => 'Unknown officer action.'], 422);
    }
} catch (PDOException $e) {
    officerRespond(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
} catch (Throwable $e) {
    officerRespond(['success' => false, 'message' => 'Server error: ' . $e->getMessage()], 500);
}