<?php
/**
 * Captain console API for captain_dashboard.php (balangaylog_db).
 * GET  -> everything the dashboard shows, in one JSON payload.
 * POST -> case_endorse | advisory_create | report_generate.
 *
 * Reads:  incident_reports, system_puroks, users, endorsements, lupon_cases
 * Writes: endorsements, advisories, case_milestones, audit_logs
 * Run captain_schema.sql first (creates endorsements, lupon_cases, advisories).
 * Uses getDatabaseConnection(), isLoggedIn() and getUserRole() from ../auth.php.
 */
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const ENDORSE_NOTES = [
    'LUPON_CERTIFICATION' => 'Barangay Captain signed the Lupon certification.',
    'PNP_TRANSMITTAL'     => 'Barangay Captain signed the transmittal to the PNP.',
];

function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail(string $message, int $code = 400): void
{
    respond(['success' => false, 'message' => $message], $code);
}

function rows(PDO $db, string $sql, array $params = []): array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function audit(PDO $db, int $actorId, string $action, array $details): void
{
    $db->prepare('INSERT INTO audit_logs (actor_user_id, target_user_id, action, details, created_at) VALUES (?, NULL, ?, ?, NOW())')
       ->execute([$actorId, $action, json_encode($details, JSON_UNESCAPED_UNICODE)]);
}

// API checks answer with JSON (requireRole() would redirect to an HTML page, which breaks fetch).
if (!isLoggedIn()) {
    fail('Your session has ended.', 401);
}
if (getUserRole() !== 'CAPTAIN') {
    fail('You do not have access to this page.', 403);
}

$db = getDatabaseConnection();
$captain = getCurrentUser();
$captainId = (int) ($captain['id'] ?? 0);
if ($captainId <= 0 || ($captain['status'] ?? '') !== 'ACTIVE') {
    fail('Your session has ended.', 401);
}
// getCurrentUser() does not select the picture, so read it here.
$captain['profile_picture'] = rows($db, 'SELECT profile_picture FROM users WHERE id = ?', [$captainId])[0]['profile_picture'] ?? null;

try {
    /* ---------------- GET: dashboard data ---------------- */
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $monthStart = date('Y-m-01 00:00:00');
        $trendStart = date('Y-m-01 00:00:00', strtotime('first day of -5 month'));

        $monthly = (int) rows($db, 'SELECT COUNT(*) c FROM incident_reports WHERE incident_datetime >= ?', [$monthStart])[0]['c'];
        $resolvedThisMonth = (int) rows($db, "SELECT COUNT(*) c FROM incident_reports WHERE incident_datetime >= ? AND status = 'RESOLVED'", [$monthStart])[0]['c'];
        $critical = (int) rows($db, "SELECT COUNT(*) c FROM incident_reports WHERE status <> 'RESOLVED' AND (priority_level = 'Critical' OR status = 'CRITICAL')")[0]['c'];
        $mediation = (int) rows($db, "SELECT COUNT(*) c FROM lupon_cases WHERE status = 'ONGOING'")[0]['c'];
        $needed = (int) rows($db, "SELECT COUNT(*) c FROM endorsements WHERE status = 'PENDING'")[0]['c'];

        // Last six months, zero-filled so the line chart never has gaps.
        $counts = array_column(rows($db, "SELECT DATE_FORMAT(incident_datetime, '%Y-%m') ym, COUNT(*) c FROM incident_reports WHERE incident_datetime >= ? GROUP BY ym", [$trendStart]), 'c', 'ym');
        $trend = [];
        for ($i = 5; $i >= 0; $i--) {
            $stamp = strtotime("first day of -$i month");
            $trend[] = ['label' => date('M', $stamp), 'count' => (int) ($counts[date('Y-m', $stamp)] ?? 0)];
        }

        $purokSql = 'SELECT sp.name AS purok, COUNT(ir.id) AS `count` FROM system_puroks sp
            LEFT JOIN incident_reports ir ON ir.purok = sp.name %s
            WHERE sp.is_active = 1 GROUP BY sp.id, sp.name ORDER BY `count` DESC, sp.name';
        $byPurok = rows($db, sprintf($purokSql, 'AND ir.incident_datetime >= ?'), [$monthStart]);
        $byPurokAll = rows($db, sprintf($purokSql, ''));
        $byCategory = rows($db, 'SELECT incident_type AS category, COUNT(*) AS `count` FROM incident_reports WHERE incident_datetime >= ? GROUP BY incident_type ORDER BY `count` DESC LIMIT 10', [$monthStart]);

        $cases = rows($db, 'SELECT id, reference_number AS case_no, incident_type AS title, complainant_name AS complainant,
                purok, priority_level AS severity, status, narrative_description AS narrative, incident_datetime
            FROM incident_reports ORDER BY incident_datetime DESC LIMIT 500');

        $endorsements = rows($db, "SELECT e.id, e.case_id, e.type, e.referred_at, ir.reference_number AS case_no,
                ir.incident_type AS title, ir.purok, u.full_name AS referred_by
            FROM endorsements e
            JOIN incident_reports ir ON ir.id = e.case_id
            LEFT JOIN users u ON u.id = e.referred_by_user_id
            WHERE e.status = 'PENDING' ORDER BY e.referred_at ASC");

        $lupon = rows($db, 'SELECT l.id, l.stage, l.next_hearing, l.status, l.parties,
                ir.reference_number AS case_no, ir.incident_type AS title, ir.purok
            FROM lupon_cases l JOIN incident_reports ir ON ir.id = l.case_id
            ORDER BY (l.next_hearing IS NULL), l.next_hearing ASC LIMIT 200');

        respond([
            'success' => true,
            'captain' => ['full_name' => $captain['full_name'] ?? '', 'profile_picture' => $captain['profile_picture'] ?? null],
            'overview' => [
                'monthly_incidents' => $monthly,
                'under_mediation' => $mediation,
                'endorsements_needed' => $needed,
                'resolution_rate' => $monthly > 0 ? (int) round($resolvedThisMonth / $monthly * 100) : 0,
                'critical_incidents' => $critical,
            ],
            'cases' => $cases,
            'endorsements' => $endorsements,
            'lupon' => $lupon,
            'trend' => $trend,
            'by_purok' => $byPurok,
            'by_purok_all' => $byPurokAll,
            'by_category' => $byCategory,
            'puroks' => rows($db, 'SELECT id, name FROM system_puroks WHERE is_active = 1 ORDER BY name'),
        ]);
    }

    /* ---------------- POST: actions ---------------- */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fail('Method not allowed.', 405);
    }

    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        fail('Your session token is invalid. Refresh the page and try again.', 403);
    }

    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        fail('Invalid request.');
    }

    switch ($input['action'] ?? '') {
        case 'case_endorse':
            $id = (int) ($input['id'] ?? 0);
            $remarks = trim((string) ($input['remarks'] ?? ''));
            if ($id <= 0 || mb_strlen($remarks) > 500) {
                fail('Check the endorsement details and try again.');
            }
            $db->beginTransaction();
            // Lock the row so two sessions cannot sign the same referral twice.
            $e = rows($db, "SELECT e.id, e.case_id, e.type, ir.reference_number, ir.status AS case_status
                FROM endorsements e JOIN incident_reports ir ON ir.id = e.case_id
                WHERE e.id = ? AND e.status = 'PENDING' FOR UPDATE", [$id])[0] ?? null;
            if (!$e) {
                $db->rollBack();
                fail('This referral was already endorsed or no longer exists.', 409);
            }
            $db->prepare("UPDATE endorsements SET status = 'ENDORSED', endorsed_by_user_id = ?, endorsed_at = NOW(), remarks = ? WHERE id = ?")
               ->execute([$captainId, $remarks !== '' ? $remarks : null, $id]);

            $note = (ENDORSE_NOTES[$e['type']] ?? 'Barangay Captain endorsed this case.') . ($remarks !== '' ? " Remarks: $remarks" : '');
            $db->prepare('INSERT INTO case_milestones (incident_id, tracking_id, status_snapshot, officer_in_charge, action_note, created_at) VALUES (?, ?, ?, ?, ?, NOW())')
               ->execute([$e['case_id'], $e['reference_number'], $e['case_status'], (string) ($captain['full_name'] ?? 'Barangay Captain'), $note]);

            audit($db, $captainId, 'case_endorsed', ['case_no' => $e['reference_number'], 'type' => $e['type'], 'remarks' => $remarks]);
            $db->commit();
            respond(['success' => true, 'message' => "Case {$e['reference_number']} endorsed and signed."]);

        case 'advisory_create':
            $title = trim((string) ($input['title'] ?? ''));
            $body = trim((string) ($input['body'] ?? ''));
            $purokId = ($input['purok_id'] ?? '') === '' ? null : (int) $input['purok_id'];
            if ($title === '' || mb_strlen($title) > 120 || $body === '' || mb_strlen($body) > 2000) {
                fail('Enter a title (up to 120 characters) and a message (up to 2,000 characters).');
            }
            if ($purokId !== null && !rows($db, 'SELECT 1 FROM system_puroks WHERE id = ? AND is_active = 1', [$purokId])) {
                fail('Choose a valid purok.');
            }
            $db->prepare('INSERT INTO advisories (title, body, purok_id, issued_by_user_id, created_at) VALUES (?, ?, ?, ?, NOW())')
               ->execute([$title, $body, $purokId, $captainId]);
            audit($db, $captainId, 'advisory_issued', ['title' => $title, 'purok_id' => $purokId]);
            respond(['success' => true, 'message' => 'Advisory published.']);

        case 'report_generate':
            $month = (string) ($input['month'] ?? '');
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || $month > date('Y-m')) {
                fail('Choose a valid month.');
            }
            audit($db, $captainId, 'dilg_report_generated', ['month' => $month]);
            // Point this at your report page (print view / PDF) once it exists.
            respond(['success' => true, 'message' => 'Monthly DILG report is ready.', 'url' => 'api/dilg_report.php?month=' . $month]);

        default:
            fail('Unknown action.');
    }
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('captain_console: ' . $error->getMessage());
    fail('Something went wrong on the server. Please try again.', 500);
}