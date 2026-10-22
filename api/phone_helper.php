<?php
function normalizePhilippineMobile(string $phoneNumber): ?string
{
    $phone = preg_replace('/[\s().-]+/', '', trim($phoneNumber));
    if ($phone === null || preg_match('/^\+?\d+$/', $phone) !== 1) {
        return null;
    }

    if (preg_match('/^\+?6309\d{9}$/', $phone) === 1) {
        return '63' . substr($phone, -10);
    }
    if (preg_match('/^09\d{9}$/', $phone) === 1) {
        return '63' . substr($phone, 1);
    }
    if (preg_match('/^\+?639\d{9}$/', $phone) === 1) {
        return ltrim($phone, '+');
    }
    if (preg_match('/^9\d{9}$/', $phone) === 1) {
        return '63' . $phone;
    }

    return null;
}

function isRegisteredMobile(PDO $pdo, string $normalizedPhone): bool
{
    $stmt = $pdo->query("SELECT contact_number FROM users WHERE contact_number IS NOT NULL AND contact_number <> ''");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $storedPhone) {
        if (normalizePhilippineMobile((string) $storedPhone) === $normalizedPhone) {
            return true;
        }
    }

    return false;
}
