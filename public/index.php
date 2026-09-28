<?php

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

// Ocultar versión PHP (info disclosure, ver audit AUDIT-2026-09-28 #2)
header_remove('X-Powered-By');

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
