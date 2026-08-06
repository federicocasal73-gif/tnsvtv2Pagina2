<?php
/**
 * Seed initial users (DEMO + ADMIN01) via raw SQL + bcrypt hash.
 * No container dependency — works around Symfony 8.1 private services.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Doctrine\DBAL\DriverManager;

// Try to load .env.local manually
$envFile = dirname(__DIR__) . '/.env.local';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!preg_match('/^([A-Z_][A-Z0-9_]*)=(.*)$/', $line, $m)) continue;
        [$key, $val] = [$m[1], $m[2]];
        // Strip surrounding quotes (single or double)
        $val = trim($val, '"\'');
        $_ENV[$key] = $val;
        putenv("$key=$val");
    }
}

// Parse DATABASE_URL into components
$dbUrl = $_ENV['DATABASE_URL'] ?? '';
$dbUrl = trim($dbUrl, '"\'');
echo "DATABASE_URL: " . substr($dbUrl, 0, 60) . "...\n";

// Format: mysql://user:password@host:port/database?serverVersion=X&charset=Y
if (preg_match('#^mysql://([^:]+):([^@]*)@([^:/]+):?(\d*)/([^?]+)#', $dbUrl, $m)) {
    $user = $m[1];
    $password = urldecode($m[2]);
    $host = $m[3];
    $port = $m[4] ?: 3306;
    $dbname = $m[5];
} else {
    echo "ERROR: could not parse DATABASE_URL\n";
    exit(1);
}

$params = [
    'driver' => 'pdo_mysql',
    'host' => $host,
    'port' => (int)$port,
    'user' => $user,
    'password' => $password,
    'dbname' => $dbname,
    'charset' => 'utf8mb4',
    'driverOptions' => [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
    ],
];

echo "Connecting to DB: user={$user} dbname={$dbname}\n";
echo "Password length: " . strlen($password) . " (last char: " . substr($password, -1) . ")\n";

try {
    $conn = DriverManager::getConnection($params);
    $conn->executeQuery('SELECT 1');
    echo "DB connected!\n";
} catch (\Throwable $e) {
    echo "DB ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

// Hash admin password
$adminPass = 'MajsTaVTVmuOAZBINaoetzSLWwt5XDA9QQ8tQNcn5sUMWCA51MIuT7CIIg';
$adminHash = password_hash($adminPass, PASSWORD_BCRYPT);
echo "Admin hash: " . substr($adminHash, 0, 30) . "...\n";

// DEMO user (no password)
$now = (new DateTime())->format('Y-m-d H:i:s');
$demoJson = json_encode(['ROLE_USER']);
$adminJson = json_encode(['ROLE_USER', 'ROLE_ADMIN']);

// Check if DEMO exists
try {
    $demoExists = $conn->fetchOne('SELECT id FROM users WHERE code = ?', ['DEMO']);
} catch (\Throwable $e) {
    echo "Query error (DEMO check): " . $e->getMessage() . "\n";
    $demoExists = null;
}
if (!$demoExists) {
    try {
        $conn->executeStatement(
            "INSERT INTO users (code, name, roles, active, wallet_balance, max_accounts, notification_sound, coins, reputation, tier) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            ['DEMO', 'Demo', $demoJson, 1, '0.00', 3, 'chime', 0, 0.0, 'INITIATE']
        );
        echo "[OK] DEMO created (id=" . $conn->lastInsertId() . ")\n";
    } catch (\Throwable $e) {
        echo "ERROR creating DEMO: " . $e->getMessage() . "\n";
    }
} else {
    echo "[SKIP] DEMO already exists (id=$demoExists)\n";
}

// Check if ADMIN01 exists
try {
    $adminExists = $conn->fetchOne('SELECT id FROM users WHERE code = ?', ['ADMIN01']);
} catch (\Throwable $e) {
    echo "Query error (ADMIN01 check): " . $e->getMessage() . "\n";
    $adminExists = null;
}
if (!$adminExists) {
    try {
        $conn->executeStatement(
            "INSERT INTO users (code, name, email, password, roles, active, wallet_balance, max_accounts, notification_sound, coins, reputation, tier) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            ['ADMIN01', 'Admin', 'admin@tnsvt.app', $adminHash, $adminJson, 1, '0.00', 3, 'chime', 0, 0.0, 'MASTER']
        );
        echo "[OK] ADMIN01 created with MASTER tier (id=" . $conn->lastInsertId() . ")\n";
    } catch (\Throwable $e) {
        echo "ERROR creating ADMIN01: " . $e->getMessage() . "\n";
    }
} else {
    echo "[SKIP] ADMIN01 already exists (id=$adminExists)\n";
}

// Verify
echo "\n=== Users in DB ===\n";
$rows = $conn->fetchAllAssociative('SELECT code, name, tier, active, roles FROM users');
foreach ($rows as $r) {
    echo "  - {$r['code']} ({$r['name']}) tier={$r['tier']} active={$r['active']} roles={$r['roles']}\n";
}

echo "\nDone.\n";