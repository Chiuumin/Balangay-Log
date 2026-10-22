<?php
session_start();
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/phone_helper.php';
require_once __DIR__ . '/sms_helper.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$input = json_decode(file_get_contents('php://input'), true);
$phone = trim($input['contact_number'] ?? '');

if (empty($phone)) {
    echo json_encode(['success' => false, 'message' => 'Phone number is required.']);
    exit;
}

$cleanPhone = normalizePhilippineMobile($phone);
if ($cleanPhone === null) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Enter a valid Philippine mobile number, such as 09171234567 or +639171234567.',
    ]);
    exit;
}

$pdo = getDatabaseConnection();
if (isRegisteredMobile($pdo, $cleanPhone)) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'code' => 'PHONE_ALREADY_USED',
        'message' => 'This phone number is already registered. Please use a different number.',
    ]);
    exit;
}

// 2. Generate and store the registration OTP in the existing session flow.
$otp = random_int(100000, 999999);
$_SESSION['registration_otp'] = $otp;
$_SESSION['registration_phone'] = $cleanPhone;

$message = "BalangayLog verification code: {$otp}. Do not share this code.";
$delivery = sendIprogSmsResult($cleanPhone, $message);

if (!$delivery['success']) {
    unset($_SESSION['registration_otp'], $_SESSION['registration_phone']);
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'SMS delivery could not be confirmed. Please try again later.']);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'The SMS provider accepted the OTP request; delivery to the phone is not confirmed.',
]);
?>