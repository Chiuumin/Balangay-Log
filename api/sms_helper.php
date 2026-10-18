<?php
function sendIprogSms($phone_number, $message) {
    // Replace with your actual token from the IPROG dashboard
    $api_token = ""; 
    $url = "https://www.iprogsms.com/api/v1/sms_messages";

    // Build the payload required by IPROG
    $payload = json_encode([
        "api_token" => $api_token,
        "phone_number" => $phone_number,
        "message" => $message
    ]);

    // Initialize cURL
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Content-Length: " . strlen($payload)
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    // Execute and close
    $response = curl_exec($ch);
    if(curl_errno($ch)){
        return "Curl error: " . curl_error($ch);
    }
    curl_close($ch);
    
    return $response;
}
?>
