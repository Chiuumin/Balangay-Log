<?php
/**
 * Calls the Anthropic Messages API, forcing tool use for guaranteed structured JSON.
 * Optimized for long narratives with extended timeout, keep-alive, and compression.
 */
function anthropic_tool_call(
    string $model,
    string $system,
    string $userText,
    array $tool,
    int $maxTokens = 1000,
    int $timeoutSec = 60
): array {
    // Prevent PHP from terminating execution before the AI responds
    if (function_exists('set_time_limit')) {
        @set_time_limit(max(120, $timeoutSec + 30));
    }

    $configPath = __DIR__ . '/../config/secrets.php';
    if (!file_exists($configPath)) {
        throw new RuntimeException("Missing configuration file: config/secrets.php");
    }

    $secrets = require $configPath;
    $apiKey  = is_array($secrets) ? trim((string) ($secrets['ANTHROPIC_API_KEY'] ?? '')) : '';

    if (strpos($apiKey, 'sk-ant-') !== 0) {
        throw new RuntimeException("ANTHROPIC_API_KEY is missing or does not start with sk-ant- (check config/secrets.php).");
    }

    $payload = [
        'model'       => $model,
        'max_tokens'  => $maxTokens,
        'system'      => $system,
        'tools'       => [$tool],
        'tool_choice' => ['type' => 'tool', 'name' => $tool['name']],
        'messages'    => [
            ['role' => 'user', 'content' => $userText]
        ],
    ];

    $curlOpts = [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => $timeoutSec,
        CURLOPT_ENCODING       => '', // Enables gzip compression for faster transmission
    ];

    $caBundle = 'C:/xampp/apache/bin/curl-ca-bundle.crt'; // local XAMPP only
    if (file_exists($caBundle)) {
        $curlOpts[CURLOPT_CAINFO] = $caBundle;
    }

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, $curlOpts);
    $raw  = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException("cURL error ($err) after waiting up to {$timeoutSec}s. Check network connection.");
    }

    $data = json_decode($raw, true);

    if ($http !== 200 || !is_array($data)) {
        $apiError = is_array($data) ? ($data['error']['message'] ?? "HTTP $http") : substr((string) $raw, 0, 200);
        throw new RuntimeException("Anthropic API Error ($http): $apiError");
    }

    foreach ($data['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'tool_use') {
            return $block['input'];
        }
    }

    throw new RuntimeException('No tool_use block returned in response');
}