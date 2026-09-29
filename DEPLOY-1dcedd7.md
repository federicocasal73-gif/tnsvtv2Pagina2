# Deploy del commit 1dcedd7 (fixes auditoría 2026-09-29)

El push a `main` ya está hecho (`1dcedd7`). Solo falta ejecutar el SSH
para que el server de Hostinger tome los cambios.

## Comando (copiar y correr desde tu máquina con SSH)

```bash
ssh -i ~/.ssh/id_tnsvt_deploy_oc -p 65002 -o StrictHostKeyChecking=accept-new \
    u310596868@185.173.111.201 \
    "cd ~/domains/tnsvt.com/public_html && \
    git fetch origin main && \
    git reset --hard origin/main && \
    rm -rf var/cache/prod var/cache/dev && \
    APP_ENV=prod php bin/console cache:clear --no-warmup && \
    if [ ! -f config/jwt/private.pem ]; then \
        echo '[deploy] Generating JWT keypair (first deploy on this server)...'; \
        php bin/generate-jwt-keys.php; \
    else \
        echo '[deploy] JWT keys already present, leaving them alone'; \
    fi && \
    APP_ENV=prod php bin/console cache:warmup --no-debug && \
    APP_ENV=prod php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration && \
    APP_ENV=prod php bin/console asset-map:compile --no-interaction && \
    APP_ENV=prod php bin/console app:assets:clean --no-interaction --apply"
```

## Verificación post-deploy

Una vez que termine el SSH (1-2 min), corramos el smoke del commit:

```bash
TNSVT_ADMIN_CODE=<tu_código_admin> \
TNSVT_ADMIN_PASSWORD='<password en .env.local>' \
TNSVT_USER_VICTIM_CODE=<código_user_test> \
bash bin/post-audit-smoke.sh https://tnsvt.com
```

El smoke valida:

1. Endpoints públicos (no afectados por el deploy, sanity check).
2. Login admin funciona.
3. **B3 fix**: `/sanctum/api/dashboard` devuelve 200 + `globalPnl: 0` + `globalPnlWarning: 'tournament_trades_subsystem_deprecated'`.
4. **B5 fix**: admin puede leer `?code=VICTIM` (200) en `/sanctum/api/oracle/emotional-bias`.
5. **B2 fix**: `POST /api/admin/wallet/credit` con `X-Admin-Password` devuelve `success: true` (no `user_not_found`).
6. **B4 fix**: `GET /api/auth/check` con header `X-Game-Code: ADMIN01` devuelve `authenticated: true` (firewall registró el authenticator).

## ¿Y si falla?

| Síntoma | Causa probable | Fix |
|---|---|---|
| `cache:warmup` falla por missing `private.pem` | El server no tiene el PEM y `.env.local` no tiene `JWT_PASSPHRASE` real | Editar `.env.local` con `JWT_PASSPHRASE=<el real>` y re-correr el comando |
| `doctrine:migrations:migrate` falla | Migración incompatible | Revisar `var/log/prod.log` en el server. Si es necesaria una fix-up migration, revertir con `bin/rollback.sh tnsvt-release-<anterior>` |
| `asset-map:compile` falla | Stale cache | `rm -rf var/cache/* public/assets/*` y reintentar |
| B3 sigue dando 500 | El cache del server no se limpió | Verificar que `var/cache/prod` se borró. Si persiste, `php bin/console cache:clear --env=prod` |
| Smoke test falla en B4 (X-Game-Code) | El cache del server no tomó el cambio de `security.yaml` | Mismo punto que arriba |

## Rollback

Si algo sale mal:

```bash
ssh -i ~/.ssh/id_tnsvt_deploy_oc -p 65002 u310596868@185.173.111.201 \
    "cd ~/domains/tnsvt.com/public_html && \
    git reset --hard 03b3a6b && \
    rm -rf var/cache/* && \
    APP_ENV=prod php bin/console cache:warmup --no-debug"
```

(`03b3a6b` es el commit anterior a mi fix, seguro según los tests.)

## Decisión sobre rotación de JWT keys (opcional)

Los `.pem` ya están en `.gitignore` (no hay urgencia). Pero si querés
invalidar todas las sesiones existentes como medida defensiva:

```bash
ssh -i ~/.ssh/id_tnsvt_deploy_oc -p 65002 u310596868@185.173.111.201 \
    "cd ~/domains/tnsvt.com/public_html && \
    php bin/generate-jwt-keys.php --force && \
    rm -rf var/cache/* && \
    APP_ENV=prod php bin/console cache:warmup --no-debug"
```

(Esto cierra todas las sesiones — usuarios deben re-loguear.)