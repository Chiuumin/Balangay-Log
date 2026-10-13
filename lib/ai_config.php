<?php
/** Which AI provider is active. 'none' = rules only (free, nothing leaves the server). */
function ai_provider(): string {
    static $p = null;
    if ($p === null) {
        $cfg = @include __DIR__ . '/../config/secrets.php';
        $p = is_array($cfg) ? strtolower(trim((string) ($cfg['AI_PROVIDER'] ?? 'anthropic'))) : 'none';
    }
    return $p;
}

function ai_enabled(): bool {
    return ai_provider() === 'anthropic';
}