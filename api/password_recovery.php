<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/sms_helper.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

requireJsonPost();

const RECOVERY_SEND_FAILURE_MESSAGE = 'Unable to send a verification code right now. Please try again later.';

function recoveryRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function recoveryAcknowledge(): void
{
    recoveryRespond(['success' => false, 'message' => RECOVERY_SEND_FAILURE_MESSAGE]);
}

function recoveryProviderAccepted(string $phone): void
{
    recoveryRespond([
        'success' => true,
        'message' => 'The SMS provider accepted the request; delivery to the phone is not confirmed.',
        'masked_phone' => recoveryMaskPhone($phone),
        'provider_accepted' => true,
    ]);
}

function recoveryClearSession(): void
{
    unset(
        $_SESSION['password_recovery_user_id'],
        $_SESSION['password_recovery_flow_token'],
        $_SESSION['password_recovery_reset_token']
    );
}

function recoveryMaskPhone(string $phone): string
{
    $digits = normalizeSmsPhone($phone);
    return '+63 9** *** ' . substr($digits, -4);
}

function recoveryValidPhone(string $phone): bool
{
    return preg_match('/^639[0-9]{9}$/', normalizeSmsPhone($phone)) === 1;
}

function recoveryGetChallenge(PDO $pdo, int $userId, string $flowHash, bool $forUpdate): ?array
{
    $sql = 'SELECT r.*, u.contact_number, u.status AS account_status, u.authorization_status AS account_authorization_status
            FROM password_recovery_challenges r
            INNER JOIN users u ON u.id = r.user_id
            WHERE r.user_id = ? AND r.flow_token_hash = ?
            LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $flowHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function recoveryAccountIsActive(array $challenge): bool
{
    return strtoupper((string) $challenge['account_status']) === 'ACTIVE'
        && in_array(
            strtoupper((string) $challenge['account_authorization_status']),
            ['ACTIVE', 'AUTHORIZED'],
            true
        );
}

function recoverySendSms(PDO $pdo, int $userId, string $flowHash, string $phone, string $otp): bool
{
    $delivery = sendIprogSmsResult(
        normalizeSmsPhone($phone),
        "BalangayLog password reset code: {$otp}. It expires in 5 minutes. Do not share this code."
    );

    if ($delivery['success']) {
        return true;
    }

    // Keep internal diagnostics to a category; never log the OTP, phone, or provider response.
    $category = (string) ($delivery['category'] ?? 'unknown_failure');
    $allowedCategories = [
        'configuration_failure',
        'provider_rejection',
        'timeout',
        'malformed_response',
        'transport_failure',
    ];
    if (!in_array($category, $allowedCategories, true)) {
        $category = 'unknown_failure';
    }
    error_log('[password_recovery] sms_send_failed category=' . $category);
    $stmt = $pdo->prepare(
        'UPDATE password_recovery_challenges
         SET otp_hash = NULL, otp_expires_at = NULL
         WHERE user_id = ? AND flow_token_hash = ?'
    );
    $stmt->execute([$userId, $flowHash]);
    return false;
}

function recoveryRequestData(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function recoveryWindowCount(array $challenge): int
{
    if (empty($challenge['send_window_started_at'])) {
        return 0;
    }
    $startedAt = strtotime((string) $challenge['send_window_started_at'] . ' UTC');
    return $startedAt !== false && $startedAt > time() - 3600
        ? (int) $challenge['window_send_count']
        : 0;
}

function recoveryCooldownRemaining(?string $lastSentAt): int
{
    if ($lastSentAt === null || $lastSentAt === '') {
        return 0;
    }
    $sentAt = strtotime($lastSentAt . ' UTC');
    return $sentAt === false ? 0 : max(0, 30 - (time() - $sentAt));
}

function recoveryStart(PDO $pdo, array $data): void
{
    recoveryClearSession();

    // Fail safely if the recovery migration has not been installed.
    $pdo->query('SELECT user_id FROM password_recovery_challenges LIMIT 0');

    if (isLoggedIn()) {
        recoveryAcknowledge();
    }

    $username = trim((string) ($data['username'] ?? ''));
    if ($username === '' || strlen($username) > 80 || !preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
        recoveryAcknowledge();
    }

    $stmt = $pdo->prepare(
        "SELECT id, contact_number
         FROM users
         WHERE username = ?
           AND status = 'ACTIVE'
           AND authorization_status IN ('ACTIVE', 'AUTHORIZED')
         LIMIT 1"
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !recoveryValidPhone((string) ($user['contact_number'] ?? ''))) {
        recoveryAcknowledge();
    }

    $userId = (int) $user['id'];
    $phone = (string) $user['contact_number'];
    $flowToken = bin2hex(random_bytes(32));
    // Persist hashes only; keep the plaintext OTP in memory only long enough to send it.
    $flowHash = hash('sha256', $flowToken);
    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otpHash = password_hash($otp, PASSWORD_DEFAULT);
    if ($otpHash === false) {
        recoveryAcknowledge();
    }

    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare(
            'SELECT window_send_count, send_window_started_at, last_sent_at
             FROM password_recovery_challenges
             WHERE user_id = ?
             LIMIT 1
             FOR UPDATE'
        );
        $lock->execute([$userId]);
        $existing = $lock->fetch(PDO::FETCH_ASSOC);
        $windowCount = $existing ? recoveryWindowCount($existing) : 0;
        if ($windowCount >= 3) {
            $pdo->rollBack();
            recoveryAcknowledge();
        }
        if ($existing && recoveryCooldownRemaining($existing['last_sent_at'] ?? null) > 0) {
            $pdo->rollBack();
            recoveryAcknowledge();
        }

        if ($existing) {
            $update = $pdo->prepare(
                'UPDATE password_recovery_challenges
                 SET flow_token_hash = ?,
                     otp_hash = ?,
                     otp_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE),
                     failed_attempts = 0,
                     session_send_count = 1,
                     last_sent_at = UTC_TIMESTAMP(),
                     verified_at = NULL,
                     reset_token_hash = NULL,
                     reset_expires_at = NULL,
                     window_send_count = ?,
                     send_window_started_at = IF(?, UTC_TIMESTAMP(), send_window_started_at)
                 WHERE user_id = ?'
            );
            $update->execute([$flowHash, $otpHash, $windowCount + 1, $windowCount === 0 ? 1 : 0, $userId]);
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO password_recovery_challenges
                    (user_id, flow_token_hash, otp_hash, otp_expires_at, failed_attempts,
                     session_send_count, last_sent_at, window_send_count, send_window_started_at)
                 VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE), 0, 1,
                         UTC_TIMESTAMP(), 1, UTC_TIMESTAMP())'
            );
            $insert->execute([$userId, $flowHash, $otpHash]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $_SESSION['password_recovery_user_id'] = $userId;
    $_SESSION['password_recovery_flow_token'] = $flowToken;

    if (!recoverySendSms($pdo, $userId, $flowHash, $phone, $otp)) {
        recoveryAcknowledge();
    }

    recoveryProviderAccepted($phone);
}

function recoveryResend(PDO $pdo): void
{
    $userId = (int) ($_SESSION['password_recovery_user_id'] ?? 0);
    $previousFlowToken = (string) ($_SESSION['password_recovery_flow_token'] ?? '');
    if ($userId <= 0 || $previousFlowToken === '') {
        recoveryAcknowledge();
    }

    $previousFlowHash = hash('sha256', $previousFlowToken);
    $flowToken = bin2hex(random_bytes(32));
    $flowHash = hash('sha256', $flowToken);
    // Replacing these hashes invalidates the previous flow token and OTP challenge.
    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otpHash = password_hash($otp, PASSWORD_DEFAULT);
    if ($otpHash === false) {
        recoveryRespond(['success' => false, 'message' => 'Unable to issue a verification code.'], 500);
    }

    $pdo->beginTransaction();
    try {
        $challenge = recoveryGetChallenge($pdo, $userId, $previousFlowHash, true);
        if (!$challenge || !recoveryAccountIsActive($challenge) || !recoveryValidPhone((string) $challenge['contact_number'])) {
            $pdo->rollBack();
            recoveryAcknowledge();
        }
        if (!empty($challenge['verified_at']) || !empty($challenge['reset_token_hash'])) {
            $pdo->rollBack();
            recoveryAcknowledge();
        }
        // Enforce the send cap and cooldown on the server, independent of the browser timer.
        if ((int) $challenge['session_send_count'] >= 3 || recoveryWindowCount($challenge) >= 3) {
            $pdo->rollBack();
            recoveryAcknowledge();
        }
        if (recoveryCooldownRemaining($challenge['last_sent_at'] ?? null) > 0) {
            $pdo->rollBack();
            recoveryAcknowledge();
        }

        $windowCount = recoveryWindowCount($challenge);
        $update = $pdo->prepare(
            'UPDATE password_recovery_challenges
             SET flow_token_hash = ?,
                 otp_hash = ?,
                 otp_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE),
                 failed_attempts = 0,
                 session_send_count = session_send_count + 1,
                 last_sent_at = UTC_TIMESTAMP(),
                 verified_at = NULL,
                 reset_token_hash = NULL,
                 reset_expires_at = NULL,
                 window_send_count = ?,
                 send_window_started_at = IF(?, UTC_TIMESTAMP(), send_window_started_at)
             WHERE user_id = ? AND flow_token_hash = ?'
        );
        $update->execute([
            $flowHash,
            $otpHash,
            $windowCount + 1,
            $windowCount === 0 ? 1 : 0,
            $userId,
            $previousFlowHash,
        ]);
        $pdo->commit();
        $_SESSION['password_recovery_flow_token'] = $flowToken;
        $phone = (string) $challenge['contact_number'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    if (!recoverySendSms($pdo, $userId, $flowHash, $phone, $otp)) {
        recoveryAcknowledge();
    }

    recoveryProviderAccepted($phone);
}

function recoveryVerify(PDO $pdo, array $data): void
{
    $userId = (int) ($_SESSION['password_recovery_user_id'] ?? 0);
    $flowToken = (string) ($_SESSION['password_recovery_flow_token'] ?? '');
    $otp = trim((string) ($data['otp'] ?? ''));
    if ($userId <= 0 || $flowToken === '') {
        recoveryRespond(['success' => false, 'message' => 'The code is invalid or expired. Try again or restart recovery.']);
    }

    $flowHash = hash('sha256', $flowToken);
    $pdo->beginTransaction();
    try {
        $challenge = recoveryGetChallenge($pdo, $userId, $flowHash, true);
        if (!$challenge || !recoveryAccountIsActive($challenge) || !empty($challenge['verified_at']) || !empty($challenge['reset_token_hash'])) {
            $pdo->rollBack();
            recoveryRespond(['success' => false, 'message' => 'The code is invalid or expired. Try again or restart recovery.']);
        }

        // The database expiry is checked here so a client cannot extend the five-minute window.
        if (
            empty($challenge['otp_hash'])
            || empty($challenge['otp_expires_at'])
            || strtotime((string) $challenge['otp_expires_at'] . ' UTC') <= time()
        ) {
            $pdo->prepare('UPDATE password_recovery_challenges SET otp_hash = NULL, otp_expires_at = NULL WHERE user_id = ?')
                ->execute([$userId]);
            $pdo->commit();
            recoveryRespond(['success' => false, 'message' => 'The code is invalid or expired. Try again or restart recovery.']);
        }

        // Count each failed guess and consume the challenge after the fifth attempt.
        if (!preg_match('/^\d{6}$/', $otp) || !password_verify($otp, (string) $challenge['otp_hash'])) {
            $attempts = (int) $challenge['failed_attempts'] + 1;
            $stmt = $pdo->prepare(
                'UPDATE password_recovery_challenges
                 SET failed_attempts = ?, otp_hash = IF(? >= 5, NULL, otp_hash),
                     otp_expires_at = IF(? >= 5, NULL, otp_expires_at)
                 WHERE user_id = ?'
            );
            $stmt->execute([$attempts, $attempts, $attempts, $userId]);
            $pdo->commit();
            recoveryRespond(['success' => false, 'message' => 'The code is invalid or expired. Try again or restart recovery.']);
        }

        $resetToken = bin2hex(random_bytes(32));
        // The one-time reset token is kept in the session; only its hash is stored in the database.
        $resetHash = hash('sha256', $resetToken);
        $stmt = $pdo->prepare(
            'UPDATE password_recovery_challenges
             SET otp_hash = NULL,
                 otp_expires_at = NULL,
                 verified_at = UTC_TIMESTAMP(),
                 reset_token_hash = ?,
                 reset_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)
             WHERE user_id = ? AND flow_token_hash = ?'
        );
        $stmt->execute([$resetHash, $userId, $flowHash]);
        $pdo->commit();
        $_SESSION['password_recovery_reset_token'] = $resetToken;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    recoveryRespond([
        'success' => true,
        'message' => 'Code verified. You can now choose a new password.',
        'verified_phone' => recoveryMaskPhone((string) $challenge['contact_number']),
    ]);
}

function recoveryReset(PDO $pdo, array $data): void
{
    $userId = (int) ($_SESSION['password_recovery_user_id'] ?? 0);
    $flowToken = (string) ($_SESSION['password_recovery_flow_token'] ?? '');
    $resetToken = (string) ($_SESSION['password_recovery_reset_token'] ?? '');
    $password = (string) ($data['password'] ?? '');
    $confirmPassword = (string) ($data['confirm_password'] ?? '');

    if ($userId <= 0 || $flowToken === '' || $resetToken === '') {
        recoveryRespond(['success' => false, 'message' => 'Password reset authorization has expired. Start again.']);
    }
    if (strlen($password) < 8 || strlen($password) > 72 || $password !== $confirmPassword) {
        recoveryRespond(['success' => false, 'message' => 'Passwords must match and be between 8 and 72 bytes.'], 422);
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    if ($passwordHash === false) {
        recoveryRespond(['success' => false, 'message' => 'Unable to set the new password.'], 500);
    }

    $flowHash = hash('sha256', $flowToken);
    $resetHash = hash('sha256', $resetToken);
    $pdo->beginTransaction();
    try {
        $challenge = recoveryGetChallenge($pdo, $userId, $flowHash, true);
        $validReset = $challenge
            && recoveryAccountIsActive($challenge)
            && !empty($challenge['reset_token_hash'])
            && hash_equals((string) $challenge['reset_token_hash'], $resetHash)
            && !empty($challenge['reset_expires_at'])
            && strtotime((string) $challenge['reset_expires_at'] . ' UTC') > time();
        if (!$validReset) {
            $pdo->rollBack();
            recoveryRespond(['success' => false, 'message' => 'Password reset authorization has expired. Start again.']);
        }

        // Change the account password and consume reset authorization in the same transaction.
        $update = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $update->execute([$passwordHash, $userId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Account password update did not affect one row.');
        }
        $spentFlowHash = hash('sha256', random_bytes(32));
        $consume = $pdo->prepare(
            'UPDATE password_recovery_challenges
             SET flow_token_hash = ?,
                 otp_hash = NULL,
                 otp_expires_at = NULL,
                 failed_attempts = 0,
                 session_send_count = 0,
                 verified_at = NULL,
                 reset_token_hash = NULL,
                 reset_expires_at = NULL
             WHERE user_id = ? AND flow_token_hash = ? AND reset_token_hash = ?'
        );
        $consume->execute([$spentFlowHash, $userId, $flowHash, $resetHash]);
        if ($consume->rowCount() !== 1) {
            throw new RuntimeException('Recovery challenge could not be consumed.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    recoveryClearSession();
    recoveryRespond(['success' => true, 'message' => 'Your password has been reset. Return to login and sign in with your new password.']);
}

function recoveryCancel(PDO $pdo): void
{
    $userId = (int) ($_SESSION['password_recovery_user_id'] ?? 0);
    $flowToken = (string) ($_SESSION['password_recovery_flow_token'] ?? '');
    if ($userId > 0 && $flowToken !== '') {
        $stmt = $pdo->prepare(
            'UPDATE password_recovery_challenges
             SET flow_token_hash = ?,
                 otp_hash = NULL,
                 otp_expires_at = NULL,
                 failed_attempts = 0,
                 session_send_count = 0,
                 verified_at = NULL,
                 reset_token_hash = NULL,
                 reset_expires_at = NULL
             WHERE user_id = ? AND flow_token_hash = ?'
        );
        $stmt->execute([hash('sha256', random_bytes(32)), $userId, hash('sha256', $flowToken)]);
    }
    recoveryClearSession();
    recoveryRespond(['success' => true]);
}

$data = recoveryRequestData();
$action = (string) ($data['action'] ?? '');

try {
    $pdo = getDatabaseConnection();
    switch ($action) {
        case 'start':
            recoveryStart($pdo, $data);
            break;
        case 'resend':
            recoveryResend($pdo);
            break;
        case 'verify':
            recoveryVerify($pdo, $data);
            break;
        case 'reset':
            recoveryReset($pdo, $data);
            break;
        case 'cancel':
            recoveryCancel($pdo);
            break;
        default:
            recoveryRespond(['success' => false, 'message' => 'Invalid recovery request.'], 400);
    }
} catch (Throwable $e) {
    error_log('[password_recovery] request_failed category=recovery_state_unavailable exception=' . get_class($e));
    if (in_array($action, ['start', 'resend'], true)) {
        recoveryRespond(['success' => false, 'message' => RECOVERY_SEND_FAILURE_MESSAGE], 503);
    }
    recoveryRespond(['success' => false, 'message' => 'Password recovery is temporarily unavailable. Please try again later.'], 503);
}
