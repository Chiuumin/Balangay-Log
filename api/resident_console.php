<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/case_intake.php';

if (!defined('SEV_RANK')) {
    define('SEV_RANK', [
        'Low' => 1,
        'Moderate' => 2,
        'Medium' => 2,
        'High' => 3,
        'Critical' => 4,
        'Urgent' => 4
    ]);
}

if (!defined('SEV_BAND')) {
    define('SEV_BAND', [
        'Low' => [0.0, 0.25],
        'Moderate' => [0.26, 0.60],
        'High' => [0.61, 0.85],
        'Critical' => [0.86, 1.00]
    ]);
}

if (!function_exists('sev_rank')) {
    function sev_rank(string $p): int {
        return SEV_RANK[$p] ?? 1;
    }
}

if (!function_exists('triage_case')) {
    function triage_case(string $type, string $narrative): array {
        if (class_exists('TriageEngine')) {
            $res = TriageEngine::evaluateIncident($narrative);
            return [
                'source' => 'AI+RULES',
                'priority' => $res['priority_level'] ?? 'Low',
                'confidence' => 0.95,
                'rationale' => $res['reasoning'] ?? '',
                'triggers' => $res['detected_keywords'] ?? [],
                'score' => (float)($res['ai_urgency_score'] ?? 0.1),
                'recommendation' => $res['ai_recommendation'] ?? '',
                'ai_priority' => $res['priority_level'] ?? 'Low',
                'model' => 'claude-3-5-sonnet-latest',
                'latency_ms' => 150
            ];
        }
        return [
            'source' => 'RULES_ONLY',
            'priority' => 'Low',
            'confidence' => 0.5,
            'rationale' => 'Fallback evaluation',
            'triggers' => [],
            'score' => 0.1,
            'recommendation' => 'Proceed with standard desk logging.',
            'ai_priority' => 'Low',
            'model' => 'fallback',
            'latency_ms' => 0
        ];
    }
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function residentRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function residentTableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare('
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        LIMIT 1
    ');
    $stmt->execute([$tableName]);
    return (bool) $stmt->fetchColumn();
}

if (!isLoggedIn() || getUserRole() !== 'RESIDENT') {
    residentRespond(['success' => false, 'message' => 'Resident authentication required.'], 403);
}
session_write_close();

try {
    $pdo = getDatabaseConnection();
    $userId = (int) $_SESSION['user_id'];
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    $userStmt = $pdo->prepare('SELECT id, full_name, email_or_phone, contact_number, purok, profile_picture FROM users WHERE id = ? LIMIT 1');
    $userStmt->execute([$userId]);
    $resident = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$resident) {
        residentRespond(['success' => false, 'message' => 'User account not found.'], 404);
    }

    $residentName = trim((string) ($resident['full_name'] ?? ''));
    $residentPhone = trim((string) ($resident['contact_number'] ?? $resident['email_or_phone'] ?? ''));

    if ($method === 'GET') {
        $stmt = $pdo->prepare("
            SELECT 
                r.*,
                r.status AS true_status,
                COALESCE(
                    (SELECT action_note FROM case_milestones WHERE incident_id = r.id ORDER BY id DESC LIMIT 1),
                    r.ai_recommendation
                ) AS latest_note
            FROM incident_reports r
            WHERE r.complainant_name = ? OR r.complainant_phone = ?
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$residentName, $residentPhone]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $residentVisibleRec = '/^(Unit Dispatched:|Case settled amicably|Flagged \/ closed)/';

        $reports = array_map(function ($row) use ($residentVisibleRec) {
            $row['status'] = $row['true_status'] ?: 'PENDING';

            $rec = (string) ($row['ai_recommendation'] ?? '');
            if ($rec !== '' && preg_match($residentVisibleRec, $rec) !== 1) {
                $rec = '';
            }
            if ($rec === '' && !empty($row['latest_note'])) {
                $rec = (string) $row['latest_note'];
            }
            $row['ai_recommendation'] = $rec !== '' ? $rec : null;

            $row['ai_urgency_score']        = null;
            $row['original_ai_priority']    = null;
            $row['severity_review_status']  = null;
            return $row;
        }, $rows);

        residentRespond([
            'success' => true,
            'resident' => $resident,
            'reports' => $reports
        ]);
    }

    if ($method !== 'POST') {
        residentRespond(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $expectedCsrf = (string) ($_SESSION['csrf_token'] ?? '');
    $providedCsrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($expectedCsrf === '' || !hash_equals($expectedCsrf, $providedCsrf)) {
        residentRespond(['success' => false, 'message' => 'Security token invalid. Refresh page.'], 403);
    }

    $isMultipart = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
    $input = $isMultipart ? $_POST : json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        residentRespond(['success' => false, 'message' => 'Invalid request payload.'], 400);
    }

    $action = trim((string) ($input['action'] ?? ''));

    switch ($action) {
        case 'get_milestones':
            $refNumber = trim((string) ($input['reference_number'] ?? ''));
            if (!$refNumber) {
                residentRespond(['success' => false, 'message' => 'Tracking number required.'], 422);
            }

            // Verify resident ownership and get complete incident row
            $cStmt = $pdo->prepare('
                SELECT id, reference_number, incident_type, narrative_description, incident_datetime,
                       purok, priority_level, status, ai_recommendation, deployed_unit, created_at
                FROM incident_reports
                WHERE reference_number = ? AND (complainant_name = ? OR complainant_phone = ?)
                LIMIT 1
            ');
            $cStmt->execute([$refNumber, $residentName, $residentPhone]);
            $case = $cStmt->fetch(PDO::FETCH_ASSOC);

            if (!$case) {
                residentRespond(['success' => false, 'message' => 'Case not found or unauthorized.'], 404);
            }

            // Fetch all milestones including milestone_stage
            $mStmt = $pdo->prepare('
                SELECT id, status_snapshot, milestone_stage, officer_in_charge, deployed_unit, action_note, created_at
                FROM case_milestones
                WHERE incident_id = ? OR tracking_id = ?
                ORDER BY id ASC
            ');
            $mStmt->execute([(int) $case['id'], $refNumber]);
            $milestones = $mStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Fetch endorsements (PNP or Lupon)
            $eStmt = $pdo->prepare('
                SELECT type, status, referred_at, endorsed_at, remarks
                FROM endorsements
                WHERE case_id = ?
                ORDER BY id DESC
            ');
            $eStmt->execute([(int)$case['id']]);
            $endorsements = $eStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Fetch dispatch logs
            $dStmt = $pdo->prepare('
                SELECT unit, status, dispatched_at, arrived_at
                FROM tanod_dispatch_logs
                WHERE incident_id = ?
                ORDER BY id ASC
            ');
            $dStmt->execute([(int)$case['id']]);
            $dispatchLogs = $dStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            residentRespond([
                'success' => true,
                'case' => $case,
                'milestones' => $milestones,
                'endorsements' => $endorsements,
                'dispatch_logs' => $dispatchLogs,
                'findings' => $findings,
                'resolutions' => $resolutions,
                'closures' => $closures
            ]);

case 'incident_create':
            $complainant = trim((string) ($input['complainant_name'] ?? $residentName));
            $phone = trim((string) ($input['contact_number'] ?? $residentPhone));
            $purok = trim((string) ($input['purok'] ?? $resident['purok'] ?? ''));
            $houseNo = trim((string) ($input['house_number'] ?? ''));
            $street = trim((string) ($input['street_name'] ?? ''));
            $category = trim((string) ($input['category'] ?? $input['incident_type'] ?? 'General Incident'));
            $priority = trim((string) ($input['priority'] ?? $input['priority_level'] ?? 'Low'));
            $narrative = trim((string) ($input['narrative'] ?? $input['narrative_description'] ?? ''));

            if (!$complainant || !$phone || !$purok || !$narrative) {
                residentRespond(['success' => false, 'message' => 'Please fill in all required fields.'], 422);
            }

            $locationDetails = trim("{$houseNo} {$street}");
            $fullNarrative = $narrative . ($locationDetails ? "\n[Location: {$locationDetails}]" : '');

            $r = online_case_run([
                'complainant' => $complainant,
                'phone'       => $phone,
                'purok'       => $purok,
                'category'    => $category,
                'narrative'   => $fullNarrative,
                'priority'    => $priority,
            ], $userId);

            if ($r['status'] === 200) {
                residentRespond(['success' => true, 'message' => "Incident reported successfully. Tracking code: {$r['body']['reference_number']}"]);
            }
            residentRespond($r['body'], $r['status']);

        case 'profile_update':
            $fullName = trim((string) ($input['full_name'] ?? ''));
            $contact = trim((string) ($input['contact_number'] ?? ''));

            if (!$fullName) {
                residentRespond(['success' => false, 'message' => 'Full name cannot be blank.'], 422);
            }

            $profilePicPath = null;
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['profile_picture'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    residentRespond(['success' => false, 'message' => 'Invalid image format.'], 422);
                }

                $uploadDir = __DIR__ . '/../uploads/profiles/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $fileName = 'profile_' . $userId . '_' . time() . '.' . $ext;
                $targetFile = $uploadDir . $fileName;

                if (move_uploaded_file($file['tmp_name'], $targetFile)) {
                    $profilePicPath = 'uploads/profiles/' . $fileName;
                }
            }

            if ($profilePicPath) {
                $upStmt = $pdo->prepare('UPDATE users SET full_name = ?, contact_number = ?, profile_picture = ? WHERE id = ?');
                $upStmt->execute([$fullName, $contact, $profilePicPath, $userId]);
            } else {
                $upStmt = $pdo->prepare('UPDATE users SET full_name = ?, contact_number = ? WHERE id = ?');
                $upStmt->execute([$fullName, $contact, $userId]);
            }

            residentRespond(['success' => true, 'message' => 'Profile details saved successfully.']);

        case 'password_update':
            $current = (string) ($input['current_password'] ?? '');
            $new = (string) ($input['new_password'] ?? '');
            $confirm = (string) ($input['confirm_password'] ?? '');

            if (strlen($new) < 8) {
                residentRespond(['success' => false, 'message' => 'Password must be at least 8 characters long.'], 422);
            }
            if ($new !== $confirm) {
                residentRespond(['success' => false, 'message' => 'Passwords do not match.'], 422);
            }

            $passStmt = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
            $passStmt->execute([$userId]);
            $hash = (string) $passStmt->fetchColumn();

            if (!password_verify($current, $hash)) {
                residentRespond(['success' => false, 'message' => 'Current password incorrect.'], 422);
            }

            $upPass = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
            $upPass->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);

            residentRespond(['success' => true, 'message' => 'Password successfully updated.']);

        default:
            residentRespond(['success' => false, 'message' => 'Unknown action.'], 422);
    }
} catch (PDOException $e) {
    error_log('[resident_console] database error: ' . $e->getMessage());
    residentRespond(['success' => false, 'message' => 'Database error. Please try again.'], 500);
} catch (Throwable $e) {
    error_log('[resident_console] ' . $e->getMessage());
    residentRespond(['success' => false, 'message' => 'System error: ' . $e->getMessage()], 500);
}