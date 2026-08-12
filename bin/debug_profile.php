<?php
require_once __DIR__ . '/../vendor/autoload.php';
$kernel = new App\Kernel('prod', false);
$kernel->boot();
$request = Symfony\Component\HttpFoundation\Request::create('/profile', 'GET');
try {
    $response = $kernel->handle($request);
    echo 'Status: ' . $response->getStatusCode() . PHP_EOL;
    if ($response->getStatusCode() >= 400) {
        echo substr($response->getContent(), 0, 3000) . PHP_EOL;
    }
} catch (\Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
}
