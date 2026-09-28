# Backups de base de datos — Guía operativa

> Documenta la operativa de backups de la base de datos de producción
> (`tnsvt.com` en Hostinger shared). Es **complemento** de
> `AGENTS.md § Database backups` con instrucciones paso a paso.
>
> Riesgo asociado (audit `AUDIT-2026-09-28 #4`): sin un cron real instalado,
> una migration rota puede dejar la DB inconsistente sin posibilidad de
> rollback de datos.

## 1. Estado actual

| Componente | Estado |
|---|---|
| Script de backup | ✅ `scripts/db-backup.sh` (en repo) |
| Backup manual desde SSH | ✅ Funcional (`~/bin/db-backup.sh` en Hostinger) |
| Cron automatico en hPanel | ⚠️ **NO instalado** — accion manual del owner |
| Off-server backup | ❌ No configurado (Riesgo #7 en `RISK_MITIGATION.md`) |

## 2. Por qué no se puede automatizar desde código

Hostinger **shared hosting** deshabilita `proc_open`, `crontab`, `at` y
`systemd-run` en `disable_functions` por seguridad (es INI_SYSTEM y no se
puede sobreescribir). Esto bloquea:

- `composer install` (resuelto — vendor/ pre-shipped)
- Cron jobs via shell (`crontab -e`)
- Tareas programadas por systemd

**Solución**: usar el **Cron Jobs UI de hPanel** (no requiere `proc_open`,
se configura via web y se persiste en el panel de Hostinger).

## 3. Setup paso a paso

### 3.1 Verificar que el script esta en el server

```bash
ssh -i ~/.ssh/id_tnsvt_deploy_oc -p 65002 u310596868@185.173.111.201 \
    'ls -la ~/bin/db-backup.sh && head -20 ~/bin/db-backup.sh'
```

Si no existe, copiar desde el repo:

```bash
scp -P 65002 -i ~/.ssh/id_tnsvt_deploy_oc \
    scripts/db-backup.sh \
    u310596868@185.173.111.201:~/bin/db-backup.sh

ssh -i ~/.ssh/id_tnsvt_deploy_oc -p 65002 u310596868@185.173.111.201 \
    'chmod 700 ~/bin/db-backup.sh && mkdir -p ~/backups'
```

### 3.2 Test manual (antes de agendar)

```bash
ssh -i ~/.ssh/id_tnsvt_deploy_oc -p 65002 u310596868@185.173.111.201 \
    '~/bin/db-backup.sh'
```

Debe terminar con algo como:

```
[2026-09-28 03:00:01] Starting backup: /home/u310596868/backups/db_tnsvt_20260928_030001.sql.gz
[2026-09-28 03:00:14] OK: /home/u310596868/backups/db_tnsvt_20260928_030001.sql.gz (1234567 bytes)
[2026-09-28 03:00:14] Done. 7 backup(s) on disk, total size: 12M
```

### 3.3 Programar el cron en hPanel

1. Login en https://hpanel.hostinger.com
2. **Hosting** → tu dominio → **Advanced** → **Cron Jobs**
3. **Add New Cron Job**:
   - **Tipo**: Advanced (shell command)
   - **Command**:
     ```
     /home/u310596868/bin/db-backup.sh >> /home/u310596868/backups/db-backup.log 2>&1
     ```
   - **Schedule**: `0 3 * * *` (todos los dias a las 03:00 hora del server)
4. Save.

### 3.4 Verificar que se ejecuta

Manualmente se puede forzar ejecutando el comando desde SSH. Después:

```bash
ssh -i ~/.ssh/id_tnsvt_deploy_oc -p 65002 u310596868@185.173.111.201 \
    'ls -lt ~/backups/db_tnsvt_*.sql.gz | head -3 && echo "---" && tail -20 ~/backups/db-backup.log'
```

Si ves un archivo con la fecha actual y la línea `Done.`, está funcionando.

## 4. Restore (emergencia)

```bash
# 1. Listar backups disponibles
ssh -i ~/.ssh/id_tnsvt_deploy_oc -p 65002 u310596868@185.173.111.201 \
    'ls -lt ~/backups/db_tnsvt_*.sql.gz'

# 2. Elegir el más reciente antes del incidente
# 3. Descargar a local
scp -P 65002 -i ~/.ssh/id_tnsvt_deploy_oc \
    u310596868@185.173.111.201:~/backups/db_tnsvt_20260927_030000.sql.gz \
    .

# 4. Restaurar via socket (NO TCP)
ssh -i ~/.ssh/id_tnsvt_deploy_oc -p 65002 u310596868@185.173.111.201 \
    'gunzip -c ~/backups/db_tnsvt_20260927_030000.sql.gz \
     | mysql --socket=/var/lib/mysql/mysql.sock \
              -u u310596868_tnsvt_v2 \
              u310596868_tnsvt_v2'
```

> **IMPORTANTE**: usar `--socket` (NO `-h 127.0.0.1`). El usuario MySQL de
> Hostinger tiene privilegios solo para `localhost` (Unix socket).

## 5. Riesgos residuales

| Riesgo | Mitigación parcial |
|---|---|
| Backup en el mismo server que la DB (no disaster-proof) | Documentado: agregar S3 / B2 / NAS cuando exista |
| Si se llena el disco del server, los backups fallan | Script chequea tamaño + gzip integrity, falla ruidosamente |
| Si la DB crece mucho (>500MB), el backup tarda | `--quick` + `--single-transaction` minimizan el lock time |
| Si Hostinger borra la cuenta, los backups se pierden | Off-server upload es el siguiente paso (Riesgo #7) |

## 6. Próximos pasos (mejoras)

- [ ] **Off-server backup**: agregar `rclone b2 put` (o S3) antes del `find ... -delete` en `db-backup.sh`
- [ ] **Monitoreo**: alerta via email/Slack si el backup falla >24h (Risk #3)
- [ ] **Test de restore periodico**: sim restore mensual en un side-DB para validar integridad
- [ ] **Dashboard `/sanctum/monitoring`**: visualiza edad del último backup (Fase 4 del audit)