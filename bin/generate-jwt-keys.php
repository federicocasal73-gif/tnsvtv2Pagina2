#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

$secretKey = $_ENV['JWT_SECRET_KEY'] ?? '%kernel.project_dir%/config/jwt/private.pem';
$publicKey = $_ENV['JWT_PUBLIC_KEY'] ?? '%kernel.project_dir%/config/jwt/public.pem';
$passphrase = $_ENV['JWT_PASSPHRASE'] ?? '';

$projectDir = dirname(__DIR__);
$secretKey = str_replace('%kernel.project_dir%', $projectDir, $secretKey);
$publicKey = str_replace('%kernel.project_dir%', $projectDir, $publicKey);

if (file_exists($secretKey) && file_exists($publicKey) && !in_array('--force', $argv, true)) {
    echo "[OK] Keys already exist:\n  - $secretKey\n  - $publicKey\n";
    exit(0);
}

$dir = dirname($secretKey);
if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    fwrite(STDERR, "Failed to create directory: $dir\n");
    exit(1);
}

$openssl = 'openssl';
if (PHP_OS_FAMILY === 'Windows') {
    $candidates = [
        'C:\\Program Files\\Git\\usr\\bin\\openssl.exe',
        'C:\\Program Files\\OpenSSL-Win64\\bin\\openssl.exe',
        'C:\\Program Files\\OpenSSL\\bin\\openssl.exe',
    ];
    foreach ($candidates as $c) {
        if (file_exists($c)) {
            $openssl = $c;
            break;
        }
    }
}

$cmd = [
    $openssl, 'genpkey',
    '-algorithm', 'RSA',
    '-pkeyopt', 'rsa_keygen_bits:4096',
    '-aes-256-cbc',
    '-pass', 'pass:' . $passphrase,
    '-out', $secretKey,
];
echo "Running: " . implode(' ', array_map('escapeshellarg', $cmd)) . "\n";

$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open($cmd, $descriptors, $pipes);
if (!is_resource($proc)) {
    fwrite(STDERR, "Failed to start openssl\n");
    exit(1);
}
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);
$exit = proc_close($proc);

if ($exit !== 0) {
    fwrite(STDERR, "openssl genpkey failed (exit $exit):\n$stderr\n");
    exit(1);
}

$cmd = [
    $openssl, 'pkey',
    '-in', $secretKey,
    '-passin', 'pass:' . $passphrase,
    '-pubout',
    '-out', $publicKey,
];
$proc = proc_open($cmd, $descriptors, $pipes);
fclose($pipes[0]);
stream_get_contents($pipes[1]);
fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);
$exit = proc_close($proc);

if ($exit !== 0) {
    fwrite(STDERR, "openssl pkey failed (exit $exit):\n$stderr\n");
    exit(1);
}

chmod($secretKey, 0600);
chmod($publicKey, 0644);

echo "[OK] JWT keypair generated:\n";
echo "  - $secretKey (private, 0600)\n";
echo "  - $publicKey (public, 0644)\n";
echo "  - bits: 4096, digest: sha512\n";