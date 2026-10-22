<?php
function normalizeSmsPhone(string $phoneNumber): string
{
    $cleanPhone = preg_replace('/\D+/', '', $phoneNumber) ?? '';
    if (strpos($cleanPhone, '0') === 0) {
        return '63' . substr($cleanPhone, 1);
    }
    if (strlen($cleanPhone) === 10 && strpos($cleanPhone, '9') === 0) {
        return '63' . $cleanPhone;
    }
    return $cleanPhone;
}

function sendIprogSmsResult(string $phoneNumber, string $message): array
{
    // Keep the provider credential in Apache/PHP's environment, never in source code.
    $apiToken = getenv('IPROG_SMS_API_TOKEN');
    if ($apiToken === false || trim($apiToken) === '' || trim($apiToken) === 'API_KEY_HERE') {
        return ['success' => false, 'category' => 'configuration_failure'];
    }

    if (!function_exists('curl_init')) {
        return ['success' => false, 'category' => 'configuration_failure'];
    }

    // IPROG's SMS endpoint expects form-encoded fields rather than a JSON body.
    $payload = http_build_query([
        'api_token' => trim($apiToken),
        'phone_number' => $phoneNumber,
        'message' => $message,
    ], '', '&', PHP_QUERY_RFC3986);

    $ch = curl_init('https://www.iprogsms.com/api/v1/sms_messages');
    if ($ch === false) {
        return ['success' => false, 'category' => 'transport_failure'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $curlErrorNumber = curl_errno($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return [
            'success' => false,
            'category' => $curlErrorNumber === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'transport_failure',
        ];
    }

    // HTTP success alone is not enough; require an explicit success status in the provider response.
    $responseData = json_decode($response, true);
    if ($httpStatus < 200 || $httpStatus >= 300) {
        return ['success' => false, 'category' => 'provider_rejection'];
    }

    if (!is_array($responseData) || json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false, 'category' => 'malformed_response'];
    }

    $providerStatus = $responseData['status'] ?? null;
    $recognizedSuccess = $providerStatus === 200
        || (is_string($providerStatus) && in_array(strtolower($providerStatus), ['200', 'success'], true));

    if ($recognizedSuccess && empty($responseData['error'])) {
        return ['success' => true, 'category' => 'provider_accepted'];
    }

    $providerRejected = !empty($responseData['error'])
        || (is_string($providerStatus)
            && in_array(strtolower($providerStatus), ['error', 'failed', 'failure', 'rejected'], true));
    return [
        'success' => false,
        'category' => $providerRejected ? 'provider_rejection' : 'malformed_response',
    ];
}

function sendIprogSms($phone_number, $message): string
{
    $result = sendIprogSmsResult((string) $phone_number, (string) $message);
    return $result['success']
        ? 'The SMS provider accepted the request; handset delivery is not confirmed.'
        : 'SMS delivery could not be confirmed.';
}
?>
