<?php
/**
 * Seed Solfeggio frequencies + 432Hz directly via SQL.
 * No container dependencies — works on Hostinger where proc_open is disabled.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

// Load .env.local
$envFile = __DIR__ . '/../.env.local';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (preg_match('/^([A-Z_][A-Z0-9_]*)=(.*)$/', $line, $m)) {
            $val = trim($m[2], '"\'');
            $_ENV[$m[1]] = $val;
            putenv("{$m[1]}=$val");
        }
    }
}

// Parse DATABASE_URL into individual components
$dbUrl = $_ENV['DATABASE_URL'] ?? '';
$dbUrl = trim($dbUrl, '"\'');
echo "DATABASE_URL: " . substr($dbUrl, 0, 50) . "...\n";

// Format: mysql://user:password@host:port/database?serverVersion=X&charset=Y
if (!preg_match('#^mysql://([^:]+):([^@]*)@([^:/]+):?(\d*)/([^?]+)#', $dbUrl, $m)) {
    fwrite(STDERR, "Could not parse DATABASE_URL: $dbUrl\n");
    exit(1);
}
$dbUser = $m[1];
$dbPass = urldecode($m[2]);
$dbHost = $m[3];
$dbPort = $m[4] ?: 3306;
$dbName = $m[5];

// Build DBAL connection
$conn = \Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_mysql',
    'host' => $dbHost,
    'port' => (int)$dbPort,
    'user' => $dbUser,
    'password' => $dbPass,
    'dbname' => $dbName,
    'charset' => 'utf8mb4',
]);

echo "Connected as $dbUser@$dbHost:$dbPort/$dbName\n";

$presets = [
    [432, 'Universal 432 Hz', 'tuning', 'Frecuencia natural de la Tierra. Verdi tuning.', ['meditation', 'grounding', 'calm']],
    [396, 'UT - 396 Hz (Liberación)', 'solfeggio', 'Libera miedo y culpa. Chakra Raíz.', ['liberation', 'grounding', 'fear_release']],
    [417, 'RE - 417 Hz (Cambio)', 'solfeggio', 'Facilita el cambio. Despeja traumas.', ['change', 'transformation', 'healing']],
    [528, 'MI - 528 Hz (Milagros / Reparación DNA)', 'solfeggio', 'Reparación del ADN. Frecuencia de los milagros.', ['dna_repair', 'love', 'miracles']],
    [639, 'FA - 639 Hz (Conexión)', 'solfeggio', 'Facilita la conexión y la comunicación.', ['relationships', 'communication', 'harmony']],
    [741, 'SOL - 741 Hz (Despertar Intuición)', 'solfeggio', 'Activa la intuición. Chakra del tercer ojo.', ['intuition', 'awakening', 'expression']],
    [852, 'LA - 852 Hz (Orden Espiritual)', 'solfeggio', 'Despierta intuición. Orden espiritual.', ['awakening', 'spiritual_order', 'intuition']],
    [963, 'TI - 963 Hz (Conciencia Divina)', 'solfeggio', 'Conecta con la conciencia superior. Chakra Corona.', ['divine_consciousness', 'unity', 'awakening']],
    [174, '174 Hz - Anestesia Natural', 'healing', 'Reduce dolor físico y emocional. La más baja de Solfeggio.', ['pain_relief', 'grounding', 'calm']],
    [285, '285 Hz - Regeneración', 'healing', 'Regeneración de tejidos y huesos. Influencia celular.', ['regeneration', 'healing', 'tissue_repair']],
    [256, 'Muladhara (Root) - 256 Hz', 'chakra', 'Chakra Raíz - conexión con la tierra.', ['grounding', 'stability', 'security']],
    [480, 'Sahasrara (Crown) - 480 Hz', 'chakra', 'Chakra Corona - conexión espiritual.', ['spiritual_connection', 'consciousness', 'enlightenment']],
];

$created = 0;
$skipped = 0;
foreach ($presets as [$hz, $name, $cat, $desc, $benefits]) {
    // Check if exists
    $exists = $conn->fetchOne('SELECT id FROM frequency_presets WHERE frequency = ?', [$hz]);
    if ($exists) {
        $skipped++;
        continue;
    }
    $conn->executeStatement(
        'INSERT INTO frequency_presets (name, frequency, category, description, active, benefits) VALUES (?, ?, ?, ?, 1, ?)',
        [$name, $hz, $cat, $desc, json_encode($benefits)]
    );
    $created++;
}

// Create user_frequencies table if missing
$hasUserFreq = (bool)$conn->fetchOne("SHOW TABLES LIKE 'user_frequencies'");
if (!$hasUserFreq) {
    $conn->executeStatement("
        CREATE TABLE user_frequencies (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            name VARCHAR(200) NOT NULL,
            frequency INT NOT NULL,
            type VARCHAR(50) NOT NULL,
            file_path VARCHAR(500) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            INDEX idx_uf_user (user_id, created_at)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
    ");
    echo "✓ Created user_frequencies table\n";
}

echo "✓ Frequencies seeded: $created created, $skipped skipped (already existed)\n";

// Verify
$count = (int)$conn->fetchOne('SELECT COUNT(*) FROM frequency_presets');
echo "✓ Total in frequency_presets: $count\n";

// List all
$rows = $conn->fetchAllAssociative('SELECT id, frequency, name, category FROM frequency_presets ORDER BY frequency ASC');
foreach ($rows as $r) {
    echo sprintf("  - %dHz [%s] %s\n", $r['frequency'], $r['category'], substr($r['name'], 0, 50));
}