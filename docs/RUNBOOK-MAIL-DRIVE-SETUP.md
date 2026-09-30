# Runbook — Mail + Google Drive setup en prod

Estado: escrito 2026-09-30, después de commit `3f60080` (Track C+D Meditación) y `0232bbdedi` (Track A Diario visual).

Diagnóstico previo (verificado en prod 2026-09-30):

- tnsvt.com está apuntado a nameservers `dns-parking.com` → buzones de Hostinger NO
  funcionan todavía (sería re-apuntar nameservers y esperar propagación).
- Hostinger shared **bloquea outgoing SMTP** a gmail/hostinger-relay en puertos 25/587.
- **Sí funciona**: `smtp-relay.brevo.com:587` (STARTTLS) y `:465` (TLS), `smtp.hostinger.com:465` (TLS).
- El server tiene `exim` instalado pero `proxyexec` falla (permisos) → `mail()` de PHP no funciona.

## 1. Google Drive API Key (10 min) — habilita `/meditacion` sync

### 1.1 Crear API Key

1. https://console.cloud.google.com/ → **Project picker → New Project** → nombre `TNSVT Meditacion` → Create.
3. **APIs & Services → Library** → buscar "Google Drive API" → Enable.
4. **APIs & Services → Credentials** → **+ Create Credentials → API key**.
5. Copiar la key (string tipo `AIzaSyD...`). Click Close.
6. **CRÍTICO**: click en la key recién creada → **Application restrictions**:
   - "IP addresses" → "Add" → pegar la IP del server (ver 1.2).
   - "API restrictions" → "Restrict key" → seleccionar solo "Google Drive API".
7. Si la key se filtra: rotar (Credentials → click en key → Regenerate).

### 1.2 Obtener IP del server

```bash
ssh -i ~/.ssh/id_tnsvt_deploy_oc -p 65002 u310596868@185.173.111.201 "curl -s https://api.ipify.org"
```

Anotar. Hostinger shared tiene IP dinámica: si Brevo/sync empieza a fallar, regenerá la key y actualizá la IP.

### 1.3 Hacer pública la carpeta de Drive

- Crear/elegir carpeta en Drive.
- Click derecho → Compartir → "Cualquier persona con el enlace" → Lector.
- Copiar URL. Formato: `https://drive.google.com/drive/folders/1abcDEFghijKLMNopq_xyz`.
- El ID es la parte final después de `folders/`. También válido: `file/d/ID/view` o ID raw.

### 1.4 Setear API Key en server

```bash
ssh -i ~/.ssh/id_tnsvt_deploy_oc -p 65002 u310596868@185.173.111.201
nano ~/domains/tnsvt.com/public_html/.env.local
```

Agregar al final:

```dotenv
###> tnsvt/google-drive ###
GOOGLE_DRIVE_API_KEY=AIzaSyD_TU_KEY_REAL
###< tnsvt/google-drive ###
```

### 1.5 Warmup + smoke

```bash
cd ~/domains/tnsvt.com/public_html
rm -rf var/cache/prod
php bin/console cache:warmup --env=prod --no-debug
```

No requiere redeploy completo (composer, migrations, etc.) — solo warmup.

### 1.6 Verificar

```bash
# API key sin auth: el endpoint /api/music/current sigue público
curl -s https://tnsvt.com/api/music/current | jq
# Esperá: 200 con `{hasMusic, current, activeIndex, total, loop, playlist}`

# Como admin: ir a /meditacion, pegar URL carpeta, click "Sincronizar ahora"
```

Errores comunes:
- `Drive: API key inválida...` → IP mal configurada en GCP. Re-generá.
- `Drive: Carpeta no encontrada...` → la carpeta no es pública ("Anyone with the link" vs "Public on the web"). Cambiá en Drive.
- `Drive: Cuota de Drive agotada (429)` → más de 12k requests/min en el proyecto GCP. Raro.

## 2. Mail real en prod con Brevo (10 min)

Hostinger mail requiere mover nameservers a Hostinger y esperar propagación. Brevo es inmediato (300/día gratis).

### 2.1 Crear cuenta Brevo

1. https://www.brevo.com/ → Sign up free → confirmar mail real (Gmail, etc.).
2. Dashboard → completar nombre empresa y país.

### 2.2 Verificar remitente

1. Brevo → **Settings → Senders & Domains**.
2. **Add an email** → `tu-mail-real@gmail.com` (rápido) o **Add a domain** (`tnsvt.com`, lento si está parqueado).
3. Click "Send verification email" → abrir mail → click en el link → queda verificado.

### 2.3 Crear SMTP key

1. Brevo → **Settings → SMTP & API → SMTP**.
2. **Generate a new SMTP key** → nombre `TNSVT prod` → Generate.
3. Copiar: `user` (mail Brevo) y `password` (string tipo `xsmtpsib-...`). El password NO se vuelve a mostrar.
4. Servidor: `smtp-relay.brevo.com`. Puerto 587 (STARTTLS) o 465 (TLS) — ambos validados en prod.

### 2.4 Configurar .env.local del server

```bash
ssh -i ~/.ssh/id_tnsvt_deploy_oc -p 65002 u310596868@185.173.111.201
nano ~/domains/tnsvt.com/public_html/.env.local
```

Agregar:

```dotenv
###> symfony/mailer ###
MAILER_DSN=smtp://USUARIO_BREVO:xsmtpsib-TU_PASSWORD@smtp-relay.brevo.com:587
MAIL_FROM=noreply@tnsvt.com
###< symfony/mailer ###

###> tnsvt/2fa ###
TWO_FACTOR_MODE=optin
TWO_FACTOR_GRACE_UNTIL=
###< tnsvt/2fa ###
```

`MAIL_FROM` debe coincidir con el mail verificado en Brevo (paso 2.2). Si todavía no moviste nameservers, usá `tu-mail-real@gmail.com` como MAIL_FROM hasta que `tnsvt.com` esté en Brevo.

Guardar y warmup:

```bash
cd ~/domains/tnsvt.com/public_html
rm -rf var/cache/prod
php bin/console cache:warmup --env=prod --no-debug
```

### 2.5 Smoke

```bash
curl -s -X POST https://tnsvt.com/api/auth/password/forgot \
  -H 'Content-Type: application/json' \
  -d '{"code":"ADMIN01"}'
# Esperá: 200 {success:true, message:"Si el código existe y tiene mail verificado..."}
# Revisá tu mail real — debería llegar el código de 6 dígitos.
```

Diagnóstico si no llega:
1. Brevo dashboard → **Logs → Email**. Ver si rebotó o nunca salió.
2. Server: `tail -50 ~/domains/tnsvt.com/public_html/var/log/prod-$(date +%F).log | grep -i mail`
3. Si ves `[MAIL] dumped to mailbox` → `MAILER_DSN` no se levantó (typo, o warmup no corrió).

### 2.6 Setear mail real del admin

El endpoint `forgot` emite código solo si `user.email` está cargado y `user.email_verified_at` no es null.

```sql
-- Conectar via phpMyAdmin (hPanel) o SSH si tenés sudo:
UPDATE users SET email = 'tu-mail-real@gmail.com', email_verified_at = NOW() WHERE code = 'ADMIN01';
```

Después desde panel admin (`/sanctum/users/ADMIN01` → "Email") queda en `users.email` para futuras referencias.

### 2.7 Fases 2FA (siguiente nivel)

```dotenv
TWO_FACTOR_MODE=optin      # no obligatorio, sólo si el user carga mail
TWO_FACTOR_GRACE_UNTIL=    # vacío
```

Subir a `mandatory` cuando todos hayan cargado mail:

```dotenv
TWO_FACTOR_MODE=mandatory
TWO_FACTOR_GRACE_UNTIL=2026-12-31   # fin de plazo
```

## 3. Rollback

Mail o Drive rotos → revertir a modo dev:

```dotenv
# En .env.local del server, comentar / dejar fallback:
GOOGLE_DRIVE_API_KEY=  # vacío → controller devuelve 502 con error claro
MAILER_DSN=null://null  # vuelve a mailbox dump a var/mailbox/*.json
TWO_FACTOR_MODE=disabled  # desactiva 2FA
```

Warmup. La app sigue funcionando — solo pierde las features nuevas.

## 4. Migración a Hostinger mail (opcional, 24-48h)

Si querés buzones reales `*@tnsvt.com`:

1. hPanel → Domains → tnsvt.com → "Change nameservers" → poner `ns1.hostinger.com`, `ns2.hostinger.com`.
2. Esperar 24-48h de propagación DNS.
3. hPanel → Emails → Create mail box → `admin@tnsvt.com`.
4. Brevo: Settings → Senders → Add `tnsvt.com` (DNS records: SPF/DKIM auto).
5. Reemplazar `MAILER_DSN` y `MAIL_FROM` en `.env.local`.
6. Warmup + smoke.

**Más lento pero más "dueño del dominio"**. Brevo alcanza para producción.