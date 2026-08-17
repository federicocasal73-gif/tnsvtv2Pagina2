#!/bin/bash
set -e

echo "=== Deploy remoto TNSVT v2 ==="
cd /home/u310596868/domains/tnsvt.com/public_html

echo "=== Verificando bin/console ==="
ls -la bin/console 2>/dev/null || echo "FALTA bin/console"

echo "=== Restaurando desde git ==="
git checkout HEAD -- bin/console
chmod +x bin/console
php bin/console --version

echo "=== Limpiando cache ==="
rm -rf var/cache/*
php bin/console cache:clear --env=prod --no-debug
php bin/console cache:warmup --env=prod --no-debug

echo "=== Verificando DB ==="
php bin/console doctrine:migrations:migrate --env=prod --no-interaction 2>&1 | tail -5

echo "=== Test HTTP ==="
curl -sI https://tnsvt.com | head -3

echo "=== Deploy completado ==="
