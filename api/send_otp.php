<?php
session_start();
header('Content-Type: application/json');

// Make sure this path correctly points to where you saved sms_helper.php!
// If sms_helper.php is in the same 'api' folder, leave it. 
// If it's in the root folder, change to: require_once __DIR__ . '/../sms_helper.php';
require_once 'sms_helper.php'; 

$input = json_decode(file_get_contents('php://input'), true);
$phone = trim($input['contact_number'] ?? '');

if (empty($phone)) {
    echo json_encode(['success' => false, 'message' => 'Phone number is required.']);
    exit;
}

// 1. STRICT FORMATTING: Convert any format (09..., +639..., 9...) to 639XXXXXXXXX
$clean_phone = preg_replace('/[^0-9]/', '', $phone); // Remove spaces, dashes, +
if (strpos($clean_phone, '0') === 0) {
    $clean_phone = '63' . substr($clean_phone, 1);
} elseif (strlen($clean_phone) === 10 && strpos($clean_phone, '9') === 0) {
    $clean_phone = '63' . $clean_phone;
}

// 2. Generate and store OTP
$otp = rand(100000, 999999);
$_SESSION['registration_otp'] = $otp;
// Store the ORIGINAL phone number the user typed to validate later in signup.php
$_SESSION['registration_phone'] = $phone; 

// 3. Send SMS
$message = "BalangayLog capstone test successful. Ref: {$otp}";
$api_response = sendIprogSms($clean_phone, $message);

// 4. Check for cURL errors from the helper
if (strpos($api_response, 'Curl error:') !== false) {
    echo json_encode(['success' => false, 'message' => 'Server connection failed: ' . $api_response]);
    exit;
}

// 5. Parse IPROG's actual response
$response_data = json_decode($api_response, true);

// Check if status is explicitly an error, or if it is not 200 / "success"
if (isset($response_data['error']) || (isset($response_data['status']) && $response_data['status'] !== 200 && $response_data['status'] !== 'success')) {
    echo json_encode(['success' => false, 'message' => 'IPROG Error: ' . $api_response]);
    exit;
}

// If no explicit error, assume success
echo json_encode(['success' => true, 'message' => 'OTP sent successfully.']);
?>