<?php
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function adminRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!isLoggedIn() || !in_array(getUserRole(), ['SYSTEM_ADMIN', 'ADMIN'], true)) {
    adminRespond(['success' => false, 'message' => 'System administrator access is required.'], 403);
}

try {
    $pdo = getDatabaseConnection();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $pendingWhere = "user_type = 'RESIDENT' AND (status IN ('PENDING', 'PENDING_VERIFICATION') OR authorization_status IN ('PENDING', 'PENDING_VERIFICATION'))";
        $pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE {$pendingWhere}")->fetchColumn();
        $residentCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE user_type = 'RESIDENT'")->fetchColumn();
        $officialCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE user_type IN ('OFFICER', 'CAPTAIN')")->fetchColumn();
        $activeCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'ACTIVE' AND authorization_status IN ('ACTIVE', 'AUTHORIZED')")->fetchColumn();
        $equipmentInUse = (int) $pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM equipment_items WHERE status = 'IN_USE'")->fetchColumn();

        $users = $pdo->query("SELECT id, full_name, username, email_or_phone, contact_number, address, purok, date_of_birth, proof_of_residency, user_type, status, authorization_status, rejection_reason, profile_picture, created_at FROM users ORDER BY created_at DESC, id DESC LIMIT 1000")->fetchAll();
        $pendingStmt = $pdo->query("SELECT id, full_name, username, contact_number, address, purok, date_of_birth, proof_of_residency, user_type, status, authorization_status, created_at FROM users WHERE {$pendingWhere} ORDER BY created_at ASC LIMIT 8");
        $pendingUsers = $pendingStmt->fetchAll();
        $logs = getAuditLogs($pdo);
        $puroks = $pdo->query('SELECT p.*, (SELECT COUNT(*) FROM incident_reports ir WHERE ir.purok = p.name) AS usage_count FROM system_puroks p ORDER BY p.name')->fetchAll();
        $categories = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM incident_reports ir WHERE ir.incident_type = c.name) AS usage_count FROM incident_categories c ORDER BY c.name')->fetchAll();
        $equipment = $pdo->query("SELECT e.*, u.full_name AS assigned_name FROM equipment_items e LEFT JOIN users u ON u.id = e.assigned_user_id ORDER BY e.updated_at DESC, e.id DESC")->fetchAll();
        $officials = $pdo->query("SELECT id, full_name, user_type FROM users WHERE user_type IN ('OFFICER', 'CAPTAIN') AND status = 'ACTIVE' ORDER BY full_name")->fetchAll();
        $adminStmt = $pdo->prepare('SELECT full_name, contact_number, profile_picture FROM users WHERE id = ? LIMIT 1');
        $adminStmt->execute([(int) $_SESSION['user_id']]);
        $adminProfile = $adminStmt->fetch() ?: [];

        adminRespond([
            'success' => true,
            'overview' => [
                'pending_residents' => $pendingCount,
                'residents' => $residentCount,
                'officials' => $officialCount,
                'active_users' => $activeCount,
                'equipment_in_use' => $equipmentInUse,
            ],
            'users' => $users,
            'pending_users' => $pendingUsers,
            'logs' => $logs,
            'puroks' => $puroks,
            'categories' => $categories,
            'equipment' => $equipment,
            'officials_list' => $officials,
            'admin' => $adminProfile,
        ]);
    }

    if ($method !== 'POST') {
        adminRespond(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $expectedCsrf = (string) ($_SESSION['csrf_token'] ?? '');
    $providedCsrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($expectedCsrf === '' || !hash_equals($expectedCsrf, $providedCsrf)) {
        adminRespond(['success' => false, 'message' => 'This page has expired. Refresh and try again.'], 403);
    }

    $input = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') ? $_POST : json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        adminRespond(['success' => false, 'message' => 'Invalid request data.'], 400);
    }

    $action = trim((string) ($input['action'] ?? ''));
    $actorId = (int) $_SESSION['user_id'];
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT) ?: null;

    switch ($action) {
        case 'resident_approve':
            requireRecordId($id);
            $stmt = $pdo->prepare("UPDATE users SET status = 'ACTIVE', authorization_status = 'AUTHORIZED', rejection_reason = NULL WHERE id = ? AND user_type = 'RESIDENT' AND (status IN ('PENDING', 'PENDING_VERIFICATION') OR authorization_status IN ('PENDING', 'PENDING_VERIFICATION'))");
            $stmt->execute([$id]);
            if ($stmt->rowCount() !== 1) adminRespond(['success' => false, 'message' => 'Pending resident account not found.'], 404);
            logAudit('RESIDENT_APPROVED', ['message' => 'Approved resident account'], $actorId, $id);
            adminRespond(['success' => true, 'message' => 'Resident account approved.']);

        case 'resident_reject':
            requireRecordId($id);
            $reason = requiredText($input, 'reason', 1000);
            $stmt = $pdo->prepare("UPDATE users SET status = 'REJECTED', authorization_status = 'REJECTED', rejection_reason = ? WHERE id = ? AND user_type = 'RESIDENT' AND (status IN ('PENDING', 'PENDING_VERIFICATION') OR authorization_status IN ('PENDING', 'PENDING_VERIFICATION'))");
            $stmt->execute([$reason, $id]);
            if ($stmt->rowCount() !== 1) adminRespond(['success' => false, 'message' => 'Pending resident account not found.'], 404);
            logAudit('RESIDENT_REJECTED', ['reason' => $reason], $actorId, $id);
            adminRespond(['success' => true, 'message' => 'Resident registration rejected.']);

        case 'user_set_status':
            requireRecordId($id);
            $status = strtoupper(trim((string) ($input['status'] ?? '')));
            if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) adminRespond(['success' => false, 'message' => 'Invalid account status.'], 422);
            $reason = requiredText($input, 'reason', 1000);
            if ($id === $actorId && $status !== 'ACTIVE') adminRespond(['success' => false, 'message' => 'You cannot deactivate your own administrator account.'], 422);
            $authorization = $status === 'ACTIVE' ? 'AUTHORIZED' : 'DEACTIVATED';
            $stmt = $pdo->prepare("UPDATE users SET status = ?, authorization_status = ?, rejection_reason = ? WHERE id = ? AND user_type IN ('RESIDENT', 'OFFICER', 'CAPTAIN')");
            $stmt->execute([$status, $authorization, $reason, $id]);
            if ($stmt->rowCount() === 0) adminRespond(['success' => false, 'message' => 'No account was changed.'], 404);
            logAudit($status === 'ACTIVE' ? 'ACCOUNT_ACTIVATED' : 'ACCOUNT_DEACTIVATED', ['reason' => $reason], $actorId, $id);
            adminRespond(['success' => true, 'message' => 'Account status updated.']);

        case 'official_create':
            $name = requiredText($input, 'full_name', 120);
            $username = validUsername(requiredText($input, 'username', 80));
            $role = strtoupper(trim((string) ($input['role'] ?? '')));
            $contact = optionalText($input, 'contact_number', 30);
            $password = (string) ($input['password'] ?? '');
            if (!in_array($role, ['OFFICER', 'CAPTAIN'], true)) adminRespond(['success' => false, 'message' => 'Choose Officer or Captain.'], 422);
            if (strlen($password) < 8) adminRespond(['success' => false, 'message' => 'Temporary password must be at least 8 characters.'], 422);
            ensureUsernameAvailable($pdo, $username);
            $stmt = $pdo->prepare("INSERT INTO users (full_name, username, email_or_phone, password_hash, contact_number, user_type, status, authorization_status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'ACTIVE', 'AUTHORIZED', NOW())");
            $stmt->execute([$name, $username, $username, password_hash($password, PASSWORD_DEFAULT), $contact, $role]);
            $newId = (int) $pdo->lastInsertId();
            logAudit('OFFICIAL_ACCOUNT_CREATED', ['name' => $name, 'role' => $role, 'username' => $username], $actorId, $newId);
            adminRespond(['success' => true, 'message' => 'Official account created.']);

        case 'official_update':
            requireRecordId($id);
            $name = requiredText($input, 'full_name', 120);
            $username = validUsername(requiredText($input, 'username', 80));
            $contact = optionalText($input, 'contact_number', 30);
            ensureUsernameAvailable($pdo, $username, $id);
            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, username = ?, email_or_phone = ?, contact_number = ? WHERE id = ? AND user_type IN ('OFFICER', 'CAPTAIN')");
            $stmt->execute([$name, $username, $username, $contact, $id]);
            if ($stmt->rowCount() < 1 && !officialExists($pdo, $id)) adminRespond(['success' => false, 'message' => 'Official account not found.'], 404);
            logAudit('OFFICIAL_ACCOUNT_UPDATED', ['name' => $name, 'username' => $username], $actorId, $id);
            adminRespond(['success' => true, 'message' => 'Official account updated.']);

        case 'password_reset':
            requireRecordId($id);
            $password = (string) ($input['password'] ?? '');
            if (strlen($password) < 8) adminRespond(['success' => false, 'message' => 'Password must be at least 8 characters.'], 422);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND user_type IN ('OFFICER', 'CAPTAIN')");
            $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            if ($stmt->rowCount() !== 1) adminRespond(['success' => false, 'message' => 'Officer or captain account not found.'], 404);
            logAudit('PASSWORD_RESET_BY_ADMIN', ['target_user_id' => $id], $actorId, $id);
            adminRespond(['success' => true, 'message' => 'Password reset. Share the temporary password securely.']);

        case 'parameter_add':
            $table = parameterTable($input['type'] ?? '');
            $name = requiredText($input, 'name', 100);
            $stmt = $pdo->prepare("INSERT INTO {$table} (name, is_active) VALUES (?, 1)");
            $stmt->execute([$name]);
            logAudit('SYSTEM_PARAMETER_ADDED', ['type' => $input['type'], 'name' => $name], $actorId);
            adminRespond(['success' => true, 'message' => 'System parameter added.']);

        case 'parameter_update':
            requireRecordId($id);
            $table = parameterTable($input['type'] ?? '');
            $name = requiredText($input, 'name', 100);
            $stmt = $pdo->prepare("UPDATE {$table} SET name = ? WHERE id = ?");
            $stmt->execute([$name, $id]);
            logAudit('SYSTEM_PARAMETER_UPDATED', ['type' => $input['type'], 'name' => $name], $actorId);
            adminRespond(['success' => true, 'message' => 'System parameter updated.']);

        case 'parameter_deactivate':
            requireRecordId($id);
            $table = parameterTable($input['type'] ?? '');
            $stmt = $pdo->prepare("UPDATE {$table} SET is_active = 0 WHERE id = ?");
            $stmt->execute([$id]);
            logAudit('SYSTEM_PARAMETER_DEACTIVATED', ['type' => $input['type'], 'id' => $id], $actorId);
            adminRespond(['success' => true, 'message' => 'Parameter deactivated. Existing records are unchanged.']);

        case 'equipment_save':
            $name = requiredText($input, 'name', 150);
            $category = strtoupper(trim((string) ($input['category'] ?? 'PATROL_EQUIPMENT')));
            $status = strtoupper(trim((string) ($input['status'] ?? 'AVAILABLE')));
            if (!in_array($category, ['PATROL_EQUIPMENT', 'COMMUNICATION', 'VEHICLE'], true)) adminRespond(['success' => false, 'message' => 'Invalid equipment category.'], 422);
            if (!in_array($status, ['AVAILABLE', 'IN_USE', 'UNDER_MAINTENANCE', 'DAMAGED', 'LOST'], true)) adminRespond(['success' => false, 'message' => 'Invalid equipment status.'], 422);
            $quantity = max(1, (int) ($input['quantity'] ?? 1));
            $serial = optionalText($input, 'serial_number', 120);
            $plate = optionalText($input, 'plate_number', 30);
            $condition = requiredText($input, 'condition_label', 60);
            $vehicleType = optionalText($input, 'vehicle_type', 100);
            $acquired = optionalText($input, 'acquired_at', 10) ?: null;
            $notes = optionalText($input, 'notes', 4000);
            if ($id) {
                $stmt = $pdo->prepare('UPDATE equipment_items SET name=?, category=?, serial_number=?, plate_number=?, quantity=?, condition_label=?, status=?, vehicle_type=?, acquired_at=?, notes=? WHERE id=?');
                $stmt->execute([$name, $category, $serial, $plate, $quantity, $condition, $status, $vehicleType, $acquired, $notes, $id]);
                $itemId = $id;
                $event = 'EQUIPMENT_UPDATED';
            } else {
                $stmt = $pdo->prepare('INSERT INTO equipment_items (name, category, serial_number, plate_number, quantity, condition_label, status, vehicle_type, acquired_at, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$name, $category, $serial, $plate, $quantity, $condition, $status, $vehicleType, $acquired, $notes]);
                $itemId = (int) $pdo->lastInsertId();
                $event = 'EQUIPMENT_ADDED';
            }
            addEquipmentHistory($pdo, $itemId, $actorId, $event, $name . ' · ' . $status);
            logAudit($event, ['equipment_id' => $itemId, 'name' => $name, 'status' => $status], $actorId);
            adminRespond(['success' => true, 'message' => 'Equipment saved.']);

        case 'equipment_assign':
            requireRecordId($id);
            $assignee = filter_var($input['assigned_user_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
            if (!$assignee) adminRespond(['success' => false, 'message' => 'Select an officer or captain to assign this item.'], 422);
            $nameStmt = $pdo->prepare('SELECT full_name FROM users WHERE id = ? AND user_type IN (\'OFFICER\', \'CAPTAIN\') AND status = \'ACTIVE\'');
            $nameStmt->execute([$assignee]);
            $assigneeName = $nameStmt->fetchColumn();
            if (!$assigneeName) adminRespond(['success' => false, 'message' => 'The selected official is not active.'], 422);
            $stmt = $pdo->prepare("UPDATE equipment_items SET assigned_user_id = ?, status = 'IN_USE' WHERE id = ?");
            $stmt->execute([$assignee, $id]);
            addEquipmentHistory($pdo, $id, $actorId, 'ASSIGNED', 'Assigned to ' . $assigneeName);
            logAudit('EQUIPMENT_ASSIGNED', ['equipment_id' => $id, 'assigned_to' => $assigneeName], $actorId);
            adminRespond(['success' => true, 'message' => 'Equipment assigned.']);

        case 'equipment_return':
            requireRecordId($id);
            $stmt = $pdo->prepare("UPDATE equipment_items SET assigned_user_id = NULL, status = 'AVAILABLE' WHERE id = ?");
            $stmt->execute([$id]);
            addEquipmentHistory($pdo, $id, $actorId, 'RETURNED', 'Item marked as returned and available.');
            logAudit('EQUIPMENT_RETURNED', ['equipment_id' => $id], $actorId);
            adminRespond(['success' => true, 'message' => 'Equipment marked as returned.']);

        case 'equipment_history':
            requireRecordId($id);
            $stmt = $pdo->prepare('SELECT h.action, h.details, h.created_at, u.full_name AS actor_name FROM equipment_history h LEFT JOIN users u ON u.id = h.actor_user_id WHERE h.equipment_id = ? ORDER BY h.created_at DESC LIMIT 100');
            $stmt->execute([$id]);
            adminRespond(['success' => true, 'history' => $stmt->fetchAll()]);

        case 'profile_update':
            $name = requiredText($input, 'full_name', 120);
            $contact = optionalText($input, 'contact_number', 30);
            $picturePath = null;
            if (!empty($_FILES['profile_picture']['tmp_name'])) {
                $picture = $_FILES['profile_picture'];
                if ($picture['error'] !== UPLOAD_ERR_OK || $picture['size'] > 2 * 1024 * 1024) adminRespond(['success' => false, 'message' => 'Profile picture must be smaller than 2 MB.'], 422);
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($picture['tmp_name']);
                $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!isset($extensions[$mime])) adminRespond(['success' => false, 'message' => 'Use a JPG, PNG, or WebP profile picture.'], 422);
                $directory = __DIR__ . '/../uploads/profiles';
                if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) adminRespond(['success' => false, 'message' => 'Could not create the profile image directory.'], 500);
                $filename = $actorId . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
                if (!move_uploaded_file($picture['tmp_name'], $directory . '/' . $filename)) adminRespond(['success' => false, 'message' => 'Could not save the profile picture.'], 500);
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
            adminRespond(['success' => true, 'message' => 'Account information updated.']);

        case 'password_update':
            $currentPassword = (string) ($input['current_password'] ?? '');
            $newPassword = (string) ($input['new_password'] ?? '');
            $confirmPassword = (string) ($input['confirm_password'] ?? '');
            if (strlen($newPassword) < 8 || $newPassword !== $confirmPassword) adminRespond(['success' => false, 'message' => 'New passwords must match and be at least 8 characters.'], 422);
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
            $stmt->execute([$actorId]);
            $hash = (string) $stmt->fetchColumn();
            if (!password_verify($currentPassword, $hash)) adminRespond(['success' => false, 'message' => 'Current password is incorrect.'], 422);
            $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $actorId]);
            logAudit('PASSWORD_CHANGED', [], $actorId, $actorId);
            adminRespond(['success' => true, 'message' => 'Password changed successfully.']);

        default:
            adminRespond(['success' => false, 'message' => 'Unknown administrator action.'], 422);
    }
} catch (PDOException $e) {
    if ((string) $e->getCode() === '23000') {
        adminRespond(['success' => false, 'message' => 'That name, username, or reference already exists.'], 409);
    }
    error_log('Admin console database error: ' . $e->getMessage());
    adminRespond(['success' => false, 'message' => 'Administrator data could not be loaded. Import admin_schema.sql if the setup migration has not been applied.'], 500);
} catch (Throwable $e) {
    error_log('Admin console error: ' . $e->getMessage());
    adminRespond(['success' => false, 'message' => 'The administrator request could not be completed.'], 500);
}

function getAuditLogs(PDO $pdo): array
{
    $where = [];
    $params = [];
    $query = trim((string) ($_GET['q'] ?? ''));
    $action = trim((string) ($_GET['action'] ?? ''));
    $userId = filter_var($_GET['user_id'] ?? null, FILTER_VALIDATE_INT);
    $from = trim((string) ($_GET['from'] ?? ''));
    $to = trim((string) ($_GET['to'] ?? ''));

    if ($query !== '') {
        $where[] = '(actor.full_name LIKE :query OR al.action LIKE :query_action OR al.details LIKE :query_details OR target.full_name LIKE :query_target)';
        $params['query'] = '%' . $query . '%';
        $params['query_action'] = '%' . $query . '%';
        $params['query_details'] = '%' . $query . '%';
        $params['query_target'] = '%' . $query . '%';
    }
    if ($action !== '') {
        $where[] = 'al.action = :action';
        $params['action'] = $action;
    }
    if ($userId) {
        $where[] = '(al.actor_user_id = :actor_id OR al.target_user_id = :target_id)';
        $params['actor_id'] = $userId;
        $params['target_id'] = $userId;
    }
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $where[] = 'al.created_at >= :from_date';
        $params['from_date'] = $from . ' 00:00:00';
    }
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $where[] = 'al.created_at <= :to_date';
        $params['to_date'] = $to . ' 23:59:59';
    }
    $sql = 'SELECT al.id, al.action, al.details, al.created_at, al.actor_user_id, al.target_user_id, actor.full_name AS actor_name, target.full_name AS target_name FROM audit_logs al LEFT JOIN users actor ON actor.id = al.actor_user_id LEFT JOIN users target ON target.id = al.target_user_id';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY al.created_at DESC, al.id DESC LIMIT 300';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function requireRecordId(?int $id): void
{
    if (!$id) adminRespond(['success' => false, 'message' => 'A valid record ID is required.'], 422);
}

function requiredText(array $input, string $key, int $maxLength): string
{
    $value = trim((string) ($input[$key] ?? ''));
    if ($value === '' || mb_strlen($value) > $maxLength) adminRespond(['success' => false, 'message' => ucfirst(str_replace('_', ' ', $key)) . ' is required and must be no longer than ' . $maxLength . ' characters.'], 422);
    return $value;
}

function optionalText(array $input, string $key, int $maxLength): ?string
{
    $value = trim((string) ($input[$key] ?? ''));
    if (mb_strlen($value) > $maxLength) adminRespond(['success' => false, 'message' => ucfirst(str_replace('_', ' ', $key)) . ' is too long.'], 422);
    return $value === '' ? null : $value;
}

function validUsername(string $username): string
{
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,80}$/', $username)) adminRespond(['success' => false, 'message' => 'Username must be 3 to 80 characters and use letters, numbers, dots, underscores, or hyphens.'], 422);
    return $username;
}

function ensureUsernameAvailable(PDO $pdo, string $username, ?int $exceptId = null): void
{
    $sql = 'SELECT id FROM users WHERE (username = :username OR email_or_phone = :email)';
    $params = ['username' => $username, 'email' => $username];
    if ($exceptId) {
        $sql .= ' AND id <> :except_id';
        $params['except_id'] = $exceptId;
    }
    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    if ($stmt->fetch()) adminRespond(['success' => false, 'message' => 'That username is already in use.'], 409);
}

function parameterTable(string $type): string
{
    $tables = ['purok' => 'system_puroks', 'category' => 'incident_categories'];
    if (!isset($tables[$type])) adminRespond(['success' => false, 'message' => 'Invalid parameter type.'], 422);
    return $tables[$type];
}

function addEquipmentHistory(PDO $pdo, int $equipmentId, int $actorId, string $action, string $details): void
{
    $stmt = $pdo->prepare('INSERT INTO equipment_history (equipment_id, actor_user_id, action, details) VALUES (?, ?, ?, ?)');
    $stmt->execute([$equipmentId, $actorId, $action, $details]);
}

function officialExists(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND user_type IN ('OFFICER', 'CAPTAIN')");
    $stmt->execute([$id]);
    return (bool) $stmt->fetchColumn();
}
