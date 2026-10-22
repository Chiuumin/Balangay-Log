<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../auth.php';

if (file_exists(__DIR__ . '/../sms_helper.php')) {
    require_once __DIR__ . '/../sms_helper.php';
} elseif (file_exists(__DIR__ . '/sms_helper.php')) {
    require_once __DIR__ . '/sms_helper.php';
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function sendJson(array $data, int$code = 200): void
{
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function sendFail(string $message, int$code = 400): void
{
    sendJson(['success' => false, 'message' => $message],$code);
}

$db = null;
if (function_exists('getDatabaseConnection')) {
    $db = getDatabaseConnection();
} elseif (function_exists('getDbConnection')) {
    $db = getDbConnection();
} elseif (isset($pdo) &&$pdo instanceof PDO) {
    $db =$pdo;
}

if (!$db) {
    sendFail('Database connection could not be established.', 500);
}

function dbRows(PDO $db, string $sql, array$params = []): array
{
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

if (!isLoggedIn()) {
    sendFail('Your session has ended. Please sign in again.', 401);
}

$userRole = strtoupper(trim((string) getUserRole()));
if ($userRole !== 'CAPTAIN') {
    sendFail('Access restricted to Barangay Captain (Current role: ' . $userRole . ').', 403);
}

$captainId = (int) ($_SESSION['user_id'] ?? 0);
$userRows = dbRows($db, 'SELECT id, full_name, username, contact_number, profile_picture FROM users WHERE id = ? LIMIT 1', [$captainId]);
$captain =$userRows[0] ?? [];

if (empty($captain)) {
    sendFail('Captain account record not found in database.', 401);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $monthStart = date('Y-m-01 00:00:00');$trendStart = date('Y-m-01 00:00:00', strtotime('first day of -5 month'));

        $hasIncidentDt = !empty(dbRows($db, "SHOW COLUMNS FROM incident_reports LIKE 'incident_datetime'"));
        $dateCol =$hasIncidentDt ? 'incident_datetime' : 'created_at';

        $mRow = dbRows($db, "SELECT COUNT(*) c FROM incident_reports WHERE {$dateCol} >= ?", [$monthStart]);
        $monthly = (int) ($mRow[0]['c'] ?? 0);

        $resRow = dbRows($db, "SELECT COUNT(*) c FROM incident_reports WHERE {$dateCol} >= ? AND status = 'RESOLVED'", [$monthStart]);
        $resolved = (int) ($resRow[0]['c'] ?? 0);

        $critRow = dbRows($db, "SELECT COUNT(*) c FROM incident_reports WHERE status <> 'RESOLVED' AND (priority_level = 'Critical' OR status = 'CRITICAL')");
        $critical = (int) ($critRow[0]['c'] ?? 0);

        $medRow = dbRows($db, "SELECT COUNT(*) c FROM lupon_cases WHERE status = 'ONGOING'");
        $mediation = (int) ($medRow[0]['c'] ?? 0);
        if ($mediation === 0) {
            $medAlt = dbRows($db, "SELECT COUNT(*) c FROM incident_reports WHERE status = 'FOR_RESOLUTION'");
            $mediation = (int) ($medAlt[0]['c'] ?? 0);
        }

        $endRow = dbRows($db, "SELECT COUNT(*) c FROM endorsements WHERE status = 'PENDING'");
        $needed = (int) ($endRow[0]['c'] ?? 0);

        $countsRows = dbRows($db, "SELECT DATE_FORMAT({$dateCol}, '\%Y-\%m') ym, COUNT(*) c FROM incident_reports WHERE {$dateCol} >= ? GROUP BY ym", [$trendStart]);$counts = array_column($countsRows, 'c', 'ym');$trend = [];
        for ($i = 5; $i >= 0; $i--) {$stamp = strtotime("first day of -$i month");
            $trend[] = ['label' => date('M',$stamp), 'count' => (int) ($counts[date('Y-m',$stamp)] ?? 0)];
        }

        $purokTable = !empty(dbRows($db, "SHOW TABLES LIKE 'system_puroks'")) ? 'system_puroks' : 'puroks';
        $hasIsActive = !empty(dbRows($db, "SHOW COLUMNS FROM {$purokTable} LIKE 'is_active'"));
        $activeClause =$hasIsActive ? 'WHERE sp.is_active = 1' : '';

        $purokSql = "SELECT sp.name AS purok, COUNT(ir.id) AS `count` FROM {$purokTable} sp
            LEFT JOIN incident_reports ir ON ir.purok = sp.name %s
            {$activeClause} GROUP BY sp.id, sp.name ORDER BY `count` DESC, sp.name";

        $byPurok = dbRows($db, sprintf($purokSql, "AND ir.{$dateCol} >= ?"), [$monthStart]);$byPurokAll = dbRows($db, sprintf($purokSql, ''), []);

        $byCategory = dbRows($db, "SELECT incident_type AS category, COUNT(*) AS `count` FROM incident_reports WHERE {$dateCol} >= ? GROUP BY incident_type ORDER BY `count` DESC LIMIT 10", [$monthStart]);

        $cases = dbRows($db, "SELECT id, reference_number AS case_no, incident_type AS title, complainant_name AS complainant,
                purok, priority_level AS severity, status, narrative_description AS narrative, {$dateCol} AS incident_datetime
            FROM incident_reports ORDER BY {$dateCol} DESC LIMIT 500");

        $endorsements = dbRows($db, "SELECT e.id, e.case_id, e.type, e.referred_at, ir.reference_number AS case_no,
                ir.incident_type AS title, ir.purok, u.full_name AS referred_by
            FROM endorsements e
            JOIN incident_reports ir ON ir.id = e.case_id
            LEFT JOIN users u ON u.id = e.referred_by_user_id
            WHERE e.status = 'PENDING' ORDER BY e.referred_at ASC");

        $lupon = dbRows($db, "SELECT l.id, l.stage, l.next_hearing, l.status, l.parties,
                ir.reference_number AS case_no, ir.incident_type AS title, ir.purok
            FROM lupon_cases l JOIN incident_reports ir ON ir.id = l.case_id
            ORDER BY (l.next_hearing IS NULL), l.next_hearing ASC LIMIT 200");

        $puroksList = dbRows($db, "SELECT id, name FROM {$purokTable} " . ($hasIsActive ? "WHERE is_active = 1" : "") . " ORDER BY name");

        sendJson([
            'success' => true,
            'captain' => [
                'full_name' => $captain['full_name'] ?? 'Barangay Captain',
                'contact_number' => $captain['contact_number'] ?? '',
                'profile_picture' => $captain['profile_picture'] ?? null
            ],
            'overview' => [
                'monthly_incidents' => $monthly,
                'under_mediation' => $mediation,
                'endorsements_needed' => $needed,
                'resolution_rate' => $monthly > 0 ? (int) round($resolved / $monthly * 100) : 0,
                'critical_incidents' => $critical,
            ],
            'cases' => $cases,
            'endorsements' => $endorsements,
            'lupon' => $lupon,
            'trend' => $trend,
            'by_purok' => $byPurok,
            'by_purok_all' => $byPurokAll,
            'by_category' => $byCategory,
            'puroks' => $puroksList,
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendFail('Method not allowed.', 405);
    }

    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        sendFail('Security token expired. Refresh the page.', 403);
    }

    $isMultipart = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
    $input = $isMultipart ? $_POST : json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        sendFail('Invalid payload format.');
    }

    $action = $input['action'] ?? '';

    if ($action === 'analytics_data') {
        $filters = is_array($input['filters'] ?? null) ? $input['filters'] : [];
        foreach (['year', 'month', 'from', 'to', 'category', 'purok', 'status'] as $filterKey) {
            if (isset($filters[$filterKey]) && !is_scalar($filters[$filterKey])) {
                sendFail('One or more report filters are invalid.');
            }
        }
        $yearValue = trim((string) ($filters['year'] ?? ''));
        $monthValue = trim((string) ($filters['month'] ?? ''));
        $fromValue = trim((string) ($filters['from'] ?? ''));
        $toValue = trim((string) ($filters['to'] ?? ''));
        $category = trim((string) ($filters['category'] ?? ''));
        $purok = trim((string) ($filters['purok'] ?? ''));
        $status = strtoupper(trim((string) ($filters['status'] ?? '')));
        $observedStatuses = array_column(dbRows($db, 'SELECT DISTINCT status FROM incident_reports WHERE status <> \'\' ORDER BY status'), 'status');
        $allowedStatuses = array_values(array_unique(array_merge(
            ['PENDING', 'IN_PROGRESS', 'FOR_RESOLUTION', 'RESOLVED', 'CRITICAL', 'DISPATCHED', 'CLOSED', 'DISMISSED'],
            array_map('strtoupper', $observedStatuses)
        )));

        if ($yearValue !== '' && (!preg_match('/^\d{4}$/', $yearValue) || (int) $yearValue < 2000 || (int) $yearValue > (int) date('Y') + 1)) {
            sendFail('Select a valid reporting year.');
        }
        if ($monthValue !== '' && (!ctype_digit($monthValue) || (int) $monthValue < 1 || (int) $monthValue > 12)) {
            sendFail('Select a valid month.');
        }
        foreach (['from' => $fromValue, 'to' => $toValue] as $field => $dateValue) {
            if ($dateValue !== '') {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);
                if (!$date || $date->format('Y-m-d') !== $dateValue) {
                    sendFail('Enter valid start and end dates.');
                }
            }
        }
        if ($fromValue !== '' && $toValue !== '' && $fromValue > $toValue) {
            sendFail('The start date must be on or before the end date.');
        }
        if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
            sendFail('Select a valid case status.');
        }
        if (strlen($category) > 100 || strlen($purok) > 100) {
            sendFail('The selected category or purok is invalid.');
        }

        try {
            $knownCategories = array_column(dbRows($db, 'SELECT DISTINCT incident_type AS name FROM incident_reports WHERE incident_type <> \'\' ORDER BY name'), 'name');
            $knownPuroks = array_column(dbRows($db, 'SELECT DISTINCT purok AS name FROM incident_reports WHERE purok <> \'\' ORDER BY name'), 'name');
            if ($category !== '' && !in_array($category, $knownCategories, true)) {
                sendFail('Select a valid incident category.');
            }
            if ($purok !== '' && !in_array($purok, $knownPuroks, true)) {
                sendFail('Select a valid purok.');
            }

            $where = [];
            $params = [];
            if ($yearValue !== '') {
                $where[] = 'ir.incident_datetime >= ? AND ir.incident_datetime < ?';
                $params[] = $yearValue . '-01-01 00:00:00';
                $params[] = ((int) $yearValue + 1) . '-01-01 00:00:00';
            }
            if ($monthValue !== '') {
                $where[] = 'MONTH(ir.incident_datetime) = ?';
                $params[] = (int) $monthValue;
            }
            if ($fromValue !== '') {
                $where[] = 'ir.incident_datetime >= ?';
                $params[] = $fromValue . ' 00:00:00';
            }
            if ($toValue !== '') {
                $where[] = 'ir.incident_datetime < DATE_ADD(?, INTERVAL 1 DAY)';
                $params[] = $toValue;
            }
            if ($category !== '') {
                $where[] = 'ir.incident_type = ?';
                $params[] = $category;
            }
            if ($purok !== '') {
                $where[] = 'ir.purok = ?';
                $params[] = $purok;
            }
            if ($status !== '') {
                $where[] = 'ir.status = ?';
                $params[] =$status;
            }

            $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
            $hasMilestones = (bool) dbRows($db, "SHOW TABLES LIKE 'case_milestones'");
            $milestoneJoin =$hasMilestones
                ? "LEFT JOIN (SELECT incident_id, MAX(created_at) AS resolved_at FROM case_milestones
                    WHERE status_snapshot = 'RESOLVED' GROUP BY incident_id) cm ON cm.incident_id = ir.id"
                : '';
            $resolvedAtSql =$hasMilestones ? 'cm.resolved_at' : 'NULL';

            $hasEndorsements = (bool) dbRows($db, "SHOW TABLES LIKE 'endorsements'");
            $endorsementJoin = '';
            $endorsementDateSql = 'NULL';$endorsementTypeSql = 'NULL';
            $receivingOfficeSql = 'NULL';$endorsementColumns = [];
            if ($hasEndorsements) {
                $endorsementColumns = array_column(dbRows($db, "SHOW COLUMNS FROM endorsements"), 'Field');
                if (in_array('case_id', $endorsementColumns, true) && in_array('status', $endorsementColumns, true)) {$aggregateColumns = [];
                    if (in_array('endorsed_at', $endorsementColumns, true)) {$aggregateColumns[] = "MAX(CASE WHEN status = 'ENDORSED' THEN endorsed_at END) AS endorsed_at";
                        $endorsementDateSql = 'e.endorsed_at';
                    }
                    if (in_array('type', $endorsementColumns, true)) {$aggregateColumns[] = "MAX(CASE WHEN status = 'ENDORSED' THEN type END) AS endorsement_type";
                        $endorsementTypeSql = 'e.endorsement_type';
                    }
                    foreach (['receiving_agency', 'receiving_office', 'referred_to', 'agency_name'] as $officeColumn) {
                        if (in_array($officeColumn, $endorsementColumns, true)) {$aggregateColumns[] = "MAX(CASE WHEN status = 'ENDORSED' THEN {$officeColumn} END) AS receiving_office";
                            $receivingOfficeSql = 'e.receiving_office';
                            break;
                        }
                    }
                    if ($aggregateColumns) {$endorsementJoin = 'LEFT JOIN (SELECT case_id, ' . implode(', ', $aggregateColumns) .
                            ' FROM endorsements GROUP BY case_id) e ON e.case_id = ir.id';
                    }
                }
            }

            $stmt =$db->prepare("SELECT ir.reference_number AS case_no, ir.incident_datetime AS reported_at,
                    ir.incident_type AS category, ir.purok, ir.priority_level AS priority, ir.status,
                    {$resolvedAtSql} AS resolved_at, {$endorsementDateSql} AS endorsed_at,
                    {$endorsementTypeSql} AS endorsement_type, {$receivingOfficeSql} AS receiving_office
                FROM incident_reports ir {$milestoneJoin} {$endorsementJoin}{$whereSql}
                ORDER BY ir.incident_datetime DESC, ir.id DESC");
            $stmt->execute($params);
            $records =$stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $currentYear = (int) date('Y');$observedYears = array_map('intval', array_column(
                dbRows($db, 'SELECT YEAR(incident_datetime) AS year FROM incident_reports
                    WHERE YEAR(incident_datetime) BETWEEN 2000 AND ?
                    GROUP BY YEAR(incident_datetime) ORDER BY year', [$currentYear]),
                'year'
            ));
            $firstYear = max(2000, min($observedYears ?: [$currentYear - 5], $currentYear - 5));$years = range($currentYear,$firstYear);
            $categories =$knownCategories;
            $puroks =$knownPuroks;
            $comparisonWhere = [];
            $comparisonParams = [];$comparisonWhere[] = 'YEAR(incident_datetime) BETWEEN 2000 AND YEAR(CURDATE())';
            if ($category !== '') {$comparisonWhere[] = 'incident_type = ?';
                $comparisonParams[] =$category;
            }
            if ($purok !== '') {$comparisonWhere[] = 'purok = ?';
                $comparisonParams[] =$purok;
            }
            if ($status !== '') {$comparisonWhere[] = 'status = ?';
                $comparisonParams[] =$status;
            }
            if ($monthValue !== '') {$comparisonWhere[] = 'MONTH(incident_datetime) = ?';
                $comparisonParams[] = (int)$monthValue;
            }
            $fromMonthDay =$fromValue !== '' ? date('m-d', strtotime($fromValue)) : '';$toMonthDay = $toValue !== '' ? date('m-d', strtotime($toValue)) : '';
            if ($fromMonthDay !== '' &&$toMonthDay !== '') {
                if ($fromMonthDay <= $toMonthDay) {$comparisonWhere[] = "DATE_FORMAT(incident_datetime, '%m-%d') BETWEEN ? AND ?";
                    $comparisonParams[] =$fromMonthDay;
                    $comparisonParams[] =$toMonthDay;
                } else {
                    $comparisonWhere[] = "(DATE_FORMAT(incident_datetime, '%m-%d') >= ? OR DATE_FORMAT(incident_datetime, '%m-%d') <= ?)";
                    $comparisonParams[] =$fromMonthDay;
                    $comparisonParams[] =$toMonthDay;
                }
            } elseif ($fromMonthDay !== '') {$comparisonWhere[] = "DATE_FORMAT(incident_datetime, '%m-%d') >= ?";
                $comparisonParams[] =$fromMonthDay;
            } elseif ($toMonthDay !== '') {$comparisonWhere[] = "DATE_FORMAT(incident_datetime, '%m-%d') <= ?";
                $comparisonParams[] =$toMonthDay;
            }
            $comparisonSql = $comparisonWhere ? ' WHERE ' . implode(' AND ', $comparisonWhere) : '';
            $yearlyComparison = dbRows($db, "SELECT YEAR(incident_datetime) AS year, COUNT(*) AS total
                FROM incident_reports{$comparisonSql}
                GROUP BY YEAR(incident_datetime) ORDER BY year", $comparisonParams);

            $pendingEndorsements = 0;
            if ($hasEndorsements && in_array('case_id', $endorsementColumns, true) && in_array('status',$endorsementColumns, true)) {
                $pendingStmt =$db->prepare("SELECT COUNT(DISTINCT e.case_id) FROM endorsements e
                    JOIN incident_reports ir ON ir.id = e.case_id
                    WHERE e.status = 'PENDING'" . ($where ? ' AND ' . implode(' AND ', $where) : ''));
                $pendingStmt->execute($params);
                $pendingEndorsements = (int)$pendingStmt->fetchColumn();
            }

            sendJson([
                'success' => true,
                'records' => $records,
                'total' => count($records),
                'pending_endorsements' => $pendingEndorsements,
                'yearly_comparison' => $yearlyComparison,
                'options' => [
                    'years' => $years,
                    'categories' => $categories,
                    'puroks' => $puroks,
                    'statuses' => $allowedStatuses,
                ],
                'coverage_note' => 'Report totals use incident records available in the database. Historical data completeness is not tracked.',
            ]);
        } catch (Throwable $e) {
            error_log('Captain analytics query failed: ' . $e->getMessage());
            sendFail('Analytics data could not be loaded. Please try again.', 500);
        }
    } elseif ($action === 'case_endorse') {
        $id = (int) ($input['id'] ?? 0);
        $remarks = trim((string) ($input['remarks'] ?? ''));

        $db->beginTransaction();
        try {
            $eRows = dbRows($db, "SELECT e.id, e.case_id, e.type, ir.reference_number, ir.status AS case_status,
                    ir.complainant_name, ir.complainant_phone
                FROM endorsements e 
                JOIN incident_reports ir ON ir.id = e.case_id
                WHERE e.id = ? AND e.status = 'PENDING'", [$id]);
            $e =$eRows[0] ?? null;

            if (!$e) {$db->rollBack();
                sendFail('Endorsement record not found or already signed.', 404);
            }

            //  Update endorsement status
            $db->prepare("UPDATE endorsements SET status = 'ENDORSED', endorsed_by_user_id = ?, endorsed_at = NOW(), remarks = ? WHERE id = ?")
               ->execute([$captainId, $remarks ?: null, $id]);

            $db->prepare("UPDATE incident_reports SET status = 'IN_PROGRESS' WHERE id = ?")
               ->execute([$e['case_id']]);

            $milestoneNote = "Captain endorsement signed and approved." . ($remarks ? " Remarks: {$remarks}" : "");
            $db->prepare("INSERT INTO case_milestones (incident_id, tracking_id, status_snapshot, officer_in_charge, action_note, created_at)
                VALUES (?, ?, 'IN_PROGRESS', ?, ?, NOW())")
               ->execute([$e['case_id'],$e['reference_number'], $captain['full_name'] ?? 'Barangay Captain',$milestoneNote]);

            $db->commit();

            //  Trigger SMS notification to resident including the reference/tracking ID
            if (!empty($e['complainant_phone']) && function_exists('sendIprogSms')) {
                $phone = trim($e['complainant_phone']);
                $cleanPhone = preg_replace('/[^0-9]/', '',$phone);
                if (strpos($cleanPhone, '0') === 0) {
                    $cleanPhone = '63' . substr($cleanPhone, 1);
                } elseif (strlen($cleanPhone) === 10 && strpos($cleanPhone, '9') === 0) {
                    $cleanPhone = '63' .$cleanPhone;
                }

                $name = trim($e['complainant_name'] ?? 'Resident');$ref = $e['reference_number'];$smsMsg = "BalangayLog Update: Hi {$name}, your case [Ref: {$ref}] has been officially endorsed/signed by the Barangay Captain.";
                if ($remarks !== '') {
                    $smsMsg .= " Remarks: {$remarks}";
                }

                @sendIprogSms($cleanPhone,$smsMsg);
            }

            sendJson(['success' => true, 'message' => "Case #{$e['reference_number']} endorsed successfully."]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {$db->rollBack();
            }
            throw $e;
        }
    } elseif ($action === 'advisory_create') {$title = trim((string) ($input['title'] ?? ''));$body = trim((string) ($input['body'] ?? ''));$purokId = !empty($input['purok_id']) ? (int)$input['purok_id'] : null;

        if (empty($title) || empty($body)) {
            sendFail('Title and message are required.');
        }

        $db->prepare('INSERT INTO advisories (title, body, purok_id, issued_by_user_id, created_at) VALUES (?, ?, ?, ?, NOW())')
           ->execute([$title,$body, $purokId,$captainId]);
        sendJson(['success' => true, 'message' => 'Advisory published.']);
    } elseif ($action === 'profile_update') {
        $name = trim((string) ($input['full_name'] ?? ''));
        $contact = trim((string) ($input['contact_number'] ?? ''));
        if (!$name) {
            sendFail('Full name cannot be blank.');
        }

        $picPath = null;
        if (!empty($_FILES['profile_picture']['tmp_name']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {$dir = __DIR__ . '/../uploads/profiles/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);$ext = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);$fileName = 'captain_' . $captainId . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $dir .$fileName)) {
                $picPath = 'uploads/profiles/' .$fileName;
            }
        }

        if ($picPath) {$db->prepare('UPDATE users SET full_name = ?, contact_number = ?, profile_picture = ? WHERE id = ?')
               ->execute([$name,$contact, $picPath,$captainId]);
        } else {
            $db->prepare('UPDATE users SET full_name = ?, contact_number = ? WHERE id = ?')
               ->execute([$name, $contact,$captainId]);
        }
        $_SESSION['full_name'] =$name;
        sendJson(['success' => true, 'message' => 'Profile updated successfully.']);
    } elseif ($action === 'password_update') {
        $current = (string) ($input['current_password'] ?? '');
        $new = (string) ($input['new_password'] ?? '');
        $confirm = (string) ($input['confirm_password'] ?? '');

        if (strlen($new) < 8 || $new !==$confirm) {
            sendFail('Passwords must match and have at least 8 characters.');
        }

        $stmt =$db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$captainId]);
        $hash = (string)$stmt->fetchColumn();

        if (!password_verify($current,$hash)) {
            sendFail('Current password incorrect.');
        }

        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
               ->execute([password_hash($new, PASSWORD_DEFAULT),$captainId]);
        sendJson(['success' => true, 'message' => 'Password changed successfully.']);
    } elseif ($action === 'report_generate') {
        $month = (string) ($input['month'] ?? date('Y-m'));
        sendJson(['success' => true, 'message' => 'Monthly report generated.', 'url' => 'api/dilg_report.php?month=' . $month]);
    } else {
        sendFail('Unknown action.');
    }
} catch (Throwable $e) {
    if (ob_get_length()) ob_clean();
    sendFail('Server execution error: ' . $e->getMessage(), 500);
}