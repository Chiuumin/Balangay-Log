<?php
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function residentRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!isLoggedIn() || getUserRole() !== 'RESIDENT') {
    residentRespond(['success' => false, 'message' => 'Resident access is required.'], 403);
}

try {
    $pdo = getDatabaseConnection();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $actorId = (int) $_SESSION['user_id'];

    if ($method === 'GET') {
        // Fetch verified resident identity
        $userStmt = $pdo->prepare('SELECT id, full_name, username, email_or_phone, contact_number, address, purok, profile_picture FROM users WHERE id = ? LIMIT 1');
        $userStmt->execute([$actorId]);
        $resident = $userStmt->fetch() ?: [];

        $fullName = (string) ($resident['full_name'] ?? '');
        $contact = (string) ($resident['contact_number'] ?? $resident['email_or_phone'] ?? '');

        // Fetch reports filed by this resident from incident_reports
        $reportsStmt = $pdo->prepare('SELECT id, reference_number, complainant_name, complainant_phone, incident_type, narrative_description, incident_datetime, purok, priority_level, status, ai_recommendation, created_at FROM incident_reports WHERE complainant_name = ? OR complainant_phone = ? ORDER BY created_at DESC, id DESC');
        $reportsStmt->execute([$fullName, $contact]);
        $reports = $reportsStmt->fetchAll() ?: [];

        // Dynamic system options
        $puroks = $pdo->query('SELECT name FROM system_puroks WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $categories = $pdo->query('SELECT name FROM incident_categories WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_COLUMN) ?: [];

        residentRespond([
            'success' => true,
            'resident' => $resident,
            'reports' => $reports,
            'puroks' => $puroks,
            'categories' => $categories
        ]);
    }

    if ($method !== 'POST') {
        residentRespond(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $expectedCsrf = (string) ($_SESSION['csrf_token'] ?? '');
    $providedCsrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($expectedCsrf === '' || !hash_equals($expectedCsrf, $providedCsrf)) {
        residentRespond(['success' => false, 'message' => 'This page has expired. Refresh and try again.'], 403);
    }

    $input = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') ? $_POST : json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        residentRespond(['success' => false, 'message' => 'Invalid request data.'], 400);
    }

    $action = trim((string) ($input['action'] ?? ''));

    switch ($action) {
        case 'profile_update':
            $name = requiredText($input, 'full_name', 120);
            $contact = optionalText($input, 'contact_number', 30);
            $picturePath = null;

            if (!empty($_FILES['profile_picture']['tmp_name'])) {
                $picture = $_FILES['profile_picture'];
                if ($picture['error'] !== UPLOAD_ERR_OK || $picture['size'] > 2 * 1024 * 1024) {
                    residentRespond(['success' => false, 'message' => 'Profile picture must be smaller than 2 MB.'], 422);
                }
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($picture['tmp_name']);
                $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!isset($extensions[$mime])) {
                    residentRespond(['success' => false, 'message' => 'Use a JPG, PNG, or WebP profile picture.'], 422);
                }
                $directory = __DIR__ . '/../uploads/profiles';
                if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                    residentRespond(['success' => false, 'message' => 'Could not create the profile image directory.'], 500);
                }
                $filename = 'resident-' . $actorId . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
                if (!move_uploaded_file($picture['tmp_name'], $directory . '/' . $filename)) {
                    residentRespond(['success' => false, 'message' => 'Could not save the profile picture.'], 500);
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
            logAudit('PROFILE_UPDATED', ['full_name' => $name, 'contact_number' => $contact], $actorId, $actorId);
            residentRespond([
                'success' => true,
                'message' => 'Account information updated.',
                'picture' => $picturePath
            ]);

        case 'password_update':
            $currentPassword = (string) ($input['current_password'] ?? '');
            $newPassword = (string) ($input['new_password'] ?? '');
            $confirmPassword = (string) ($input['confirm_password'] ?? '');
            if (strlen($newPassword) < 8 || $newPassword !== $confirmPassword) {
                residentRespond(['success' => false, 'message' => 'New passwords must match and be at least 8 characters.'], 422);
            }
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
            $stmt->execute([$actorId]);
            $hash = (string) $stmt->fetchColumn();
            if (!password_verify($currentPassword, $hash)) {
                residentRespond(['success' => false, 'message' => 'Current password is incorrect.'], 422);
            }
            $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $actorId]);
            logAudit('PASSWORD_CHANGED', [], $actorId, $actorId);
            residentRespond(['success' => true, 'message' => 'Password changed successfully.']);

        case 'incident_create':
            $complainant = requiredText($input, 'complainant_name', 120);
            $phone = requiredText($input, 'contact_number', 30);
            $purok = requiredText($input, 'purok', 60);
            $houseNo = optionalText($input, 'house_number', 60);
            $street = optionalText($input, 'street_name', 100);
            $type = requiredText($input, 'category', 100);
            $priority = trim((string) ($input['priority'] ?? 'Moderate'));
            $narrative = requiredText($input, 'narrative', 4000);
            $channel = trim((string) ($input['reporting_channel'] ?? 'Citizen Online Report'));

            if (!in_array($priority, ['Low', 'Moderate', 'High', 'Critical'], true)) {
                $priority = 'Moderate';
            }

            $year = date('Y');
            $countRes = $pdo->query('SELECT COUNT(*) FROM incident_reports')->fetchColumn();
            $referenceNumber = sprintf('TRK-%s-%03d', $year, ((int) $countRes) + 1);

            $urgencyScore = 20.00;
            $recommendation = 'Standard administrative intake. Log for regular review.';
            $initialStatus = 'PENDING';

            if ($priority === 'Critical') {
                $urgencyScore = 85.00;
                $recommendation = 'Critical threat detected. Dispatch Immediate Response Unit and alert PNP.';
                $initialStatus = 'CRITICAL';
            } elseif ($priority === 'High') {
                $urgencyScore = 65.00;
                $recommendation = 'Active incident. Deploy Barangay Tanod unit for on-site verification.';
            }

            $fullNarrative = $narrative;
            if ($houseNo || $street) {
                $fullNarrative .= "\n\n[Location Specifics: " . trim($houseNo . ' ' . $street) . "]";
            }

            $stmt = $pdo->prepare('INSERT INTO incident_reports (reference_number, complainant_name, complainant_phone, incident_type, narrative_description, incident_datetime, purok, scenario_type, verification_level, ai_urgency_score, priority_level, ai_recommendation, status, created_at) VALUES (?, ?, ?, ?, ?, NOW(), ?, \'ONLINE_PORTAL\', \'VERIFIED\', ?, ?, ?, ?, NOW())');
            $stmt->execute([
                $referenceNumber, $complainant, $phone, $type,
                $fullNarrative, $purok, $urgencyScore, $priority,
                $recommendation, $initialStatus
            ]);

            $newIncidentId = (int) $pdo->lastInsertId();

            // Insert initial case milestone
            $stmtM = $pdo->prepare('INSERT INTO case_milestones (incident_id, tracking_id, status_snapshot, action_note, created_at) VALUES (?, ?, ?, ?, NOW())');
            $actionNote = "Initial report logged as {$type}. Priority: {$priority} (Score: {$urgencyScore}).";
            $stmtM->execute([$newIncidentId, $referenceNumber, $initialStatus, $actionNote]);

            logAudit('INCIDENT_FILED', ['reference_number' => $referenceNumber, 'type' => $type], $actorId);
            residentRespond([
                'success' => true,
                'message' => 'Incident filed successfully.',
                'reference_number' => $referenceNumber
            ]);

        case 'get_milestones':
            $ref = requiredText($input, 'reference_number', 30);
            $stmtM = $pdo->prepare('SELECT id, tracking_id, status_snapshot, officer_in_charge, deployed_unit, action_note, created_at FROM case_milestones WHERE tracking_id = ? ORDER BY created_at DESC, id DESC');
            $stmtM->execute([$ref]);
            residentRespond([
                'success' => true,
                'milestones' => $stmtM->fetchAll() ?: []
            ]);

        default:
            residentRespond(['success' => false, 'message' => 'Unknown resident action.'], 422);
    }
} catch (PDOException $e) {
    residentRespond(['success' => false, 'message' => 'DATABASE ERROR: ' . $e->getMessage()], 500);
} catch (Throwable $e) {
    error_log('Resident console error: ' . $e->getMessage());
    residentRespond(['success' => false, 'message' => 'The resident request could not be completed.'], 500);
}

function requiredText(array $input, string $key, int $maxLength): string
{
    $value = trim((string) ($input[$key] ?? ''));
    if ($value === '' || mb_strlen($value) > $maxLength) {
        residentRespond(['success' => false, 'message' => ucfirst(str_replace('_', ' ', $key)) . ' is required and must be no longer than ' . $maxLength . ' characters.'], 422);
    }
    return $value;
}

function optionalText(array $input, string $key, int $maxLength): ?string
{
    $value = trim((string) ($input[$key] ?? ''));
    if (mb_strlen($value) > $maxLength) {
        residentRespond(['success' => false, 'message' => ucfirst(str_replace('_', ' ', $key)) . ' is too long.'], 422);
    }
    return $value === '' ? null : $value;
}