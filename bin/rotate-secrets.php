<?php

declare(strict_types=1);

// A.9a — Rotate ALL secrets in .env.local.
// Run with: php bin/rotate-secrets.php
// This script prints NEW values to copy into .env.local. The OLD file is backed up.

$projectDir = dirname(__DIR__);
$envLocalPath = $projectDir . '/.env.local';
$backupPath = $projectDir . '/.env.local.backup-' . date('Ymd-His');

if (!file_exists($envLocalPath)) {
    fwrite(STDERR, "No .env.local found at $envLocalPath\n");
    exit(1);
}

$newSecrets = [
    'APP_SECRET'           => bin2hex(random_bytes(32)),
    'JWT_PASSPHRASE'        => bin2hex(random_bytes(32)),
    'ADMIN_PASSWORD'        => bin2hex(random_bytes(32)),
    'ACADEMIA_ADMIN_PASS'   => bin2hex(random_bytes(32)),
    'MP_WEBHOOK_SECRET'     => bin2hex(random_bytes(32)),
    'BINANCE_PAY_SECRET'   => bin2hex(random_bytes(32)),
    'MERCURE_JWT_SECRET'    => bin2hex(random_bytes(32)),
    'FIREBASE_WEB_PUSH_VAPID_KEY' => bin2hex(random_bytes(32)),
];

echo "=== NEW SECRETS (copy these into .env.local) ===\n\n";
foreach ($newSecrets as $key => $value) {
    echo "$key=$value\n";
}

echo "\n=== IMPORTANT ===\n";
echo "1. Save the above values\n";
echo "2. The DATABASE_URL and FIREBASE_WEB_API_KEY are NOT rotated by this script\n";
echo "3. You must manually rotate DATABASE passwords and FIREBASE_API key in their respective consoles\n";
echo "4. After updating .env.local, regenerate JWT keys: php bin/generate-jwt-keys.php --force\n";
echo "5. Delete the backup file once verified: $backupPath\n";