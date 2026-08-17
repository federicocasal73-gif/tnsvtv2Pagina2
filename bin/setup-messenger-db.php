<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

$env = $_SERVER['APP_ENV'] ?? 'dev';
$dbUrl = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? '';

echo "Environment: $env\n";

if (!str_starts_with($dbUrl, 'sqlite:')) {
    echo "[SKIP] Non-sqlite DB. Run 'bin/console doctrine:migrations:diff' and apply\n";
    exit(0);
}

// Extract path from sqlite:///... format
$path = substr($dbUrl, strlen('sqlite://'));
// Replace %kernel.project_dir% with actual project dir
$path = str_replace('%kernel.project_dir%', dirname(__DIR__), $path);
// Windows: strip leading slash if it appears
if (DIRECTORY_SEPARATOR === '\\' && str_starts_with($path, '/')) {
    $path = substr($path, 1);
}
// Convert relative to absolute
if (!preg_match('/^[a-z]:/i', $path) && !str_starts_with($path, '/')) {
    $path = dirname(__DIR__) . '/' . $path;
}
$path = realpath($path) ?: $path;

if (!file_exists($path)) {
    echo "[ERROR] SQLite DB not found at: $path\n";
    exit(1);
}

echo "SQLite: $path\n";

$pdo = new PDO('sqlite:' . $path);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='messenger_messages'")->fetchAll();
if (!empty($tables)) {
    echo "[OK] messenger_messages table already exists\n";
    exit(0);
}

$sql = file_get_contents(dirname(__DIR__) . '/bin/setup-messenger-db.sql');
$pdo->exec($sql);

echo "[OK] messenger_messages table created with indexes\n";