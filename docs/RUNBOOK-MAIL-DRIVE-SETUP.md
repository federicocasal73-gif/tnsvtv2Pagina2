# Runbook — Mail + Google Drive setup en prod

Estado: revisado 2026-09-30 después de que el usuario pidió "Hostinger mail, no quiero pagar más". Diagnóstico actualizado (verificado con `fsockopen` desde prod):

- `tnsvt.com` sigue con NS `dns-parking.com` (parqueado) — **no se mueve**.
- SMTP saliente **SÍ funciona** desde el server a `smtp.hostinger.com:587` (STARTTLS, 3ms handshake) y a `smtp-relay.brevo.com:587` (11ms).
- Puertos 465 (TLS implícito) NO abren handshake desde este server → **usar 587**.
- `exim` instalado pero `proxyexec` falla → `php mail()` no funciona. Usar SMTP directo vía Symfony Mailer.
- `getmxhost(tnsvt.com) = none` → **no hay buzón `*@tnsvt.com` configurado** todavía. Hay que crearlo en hPanel.

## OPCIÓN RECOMENDADA: Hostinger Email (gratis con el hosting)

Ventajas:
- 100% gratis, incluido en tu plan actual de Hostinger.
- Mailboxes `*@tnsvt.com` reales (no un relay externo).
- SMTP relay funcionando desde el server.

Limitaciones:
- Algunos planes limitan a ~100 emails/día. Más que suficiente para 2FA + recupero.
- Requiere crear el buzón en hPanel → Emails (5 min en panel web).

### Paso A: Crear buzón en hPanel (5 min)

1. Login en https://hpanel.hostinger.com con tu cuenta.
2. **Emails → tu dominio (`tnsvt.com`)**.
3. Click **Manage** (o **Create** si es la primera vez).
4. **Create email account**:
   - Username: `admin` (queda `admin@tnsvt.com`)
   - Password: una cualquiera robusta (la usás en el .env.local, no se compromete)
   - Mailbox quota: 1 GB (suficiente)
   - Click **Create**
5. Anotá:
   - Email completo: `admin@tnsvt.com`
   - Password del buzón (NO la del hPanel — la del buzón)
   - **Servidor SMTP saliente**: `smtp.hostinger.com` puerto **587** con STARTTLS
   - Username SMTP: el mail completo (`admin@tnsvt.com`)
6. Click en el buzón → **Manage Email Account** → verás también opciones para webmail (Roundcube) si querés verificar el mail desde el browser.

Si querés otros buzones para los users (recomendable cuando crezca), repetí con `noreply@tnsvt.com`, `no-reply@tnsvt.com`, etc. Mismo SMTP.

### Paso B: Setear MAILER_DSN en .env.local del server (2 min)

SSH:

```bash
ssh -i ~/.ssh/id_tnsvt_deploy_oc -p 65002 u310596868@185.173.111.201
nano ~/domains/tnsvt.com/public_html/.env.local
```

Agregar/modificar:

```dotenv
###> symfony/mailer ###
MAILER_DSN=smtp://admin@tnsvt.com:TU_PASSWORD_DEL_BUZON@smtp.hostinger.com:587
MAIL_FROM=noreply@tnsvt.com
###< symfony/mailer ###

###> tnsvt/2fa ###
TWO_FACTOR_MODE=optin
TWO_FACTOR_GRACE_UNTIL=
###< tnsvt/2fa ###
```

Notas:
- `MAIL_FROM` puede ser el mismo `admin@tnsvt.com` o `noreply@tnsvt.com` si creaste un buzón dedicado.
- Si tu password tiene caracteres especiales (`@`, `:`, `/`, `#`, etc.), Symfony Mailer los URL-encodea automáticamente, pero ojo con `:`, `@`, `/` que **son** los delimitadores del DSN. Si tenés esos, encondéalos a `%XX` o cambialos en el panel.
- El user `admin@tnsvt.com` requiere el mail completo como username (no solo `admin`).

Guardar, warmup:

```bash
cd ~/domains/tnsvt.com/public_html
rm -rf var/cache/prod
php bin/console cache:warmup --env=prod --no-debug
```

### Paso C: Setear mail real del admin en la DB (1 min)

El endpoint `/api/auth/password/forgot` emite código solo si `users.email` está cargado y `users.email_verified_at` no es NULL. Si no lo está, vas a recibir `success:true` pero ningún código.

```bash
# Opción A: via panel admin (más seguro)
# Login como admin en tnsvt.com → /sanctum/users → click en tu propio row → editar email a admin@tnsvt.com

# Opción B: via SSH + SQL (más rápido)
mysql ... -e "UPDATE users SET email='admin@tnsvt.com', email_verified_at=NOW() WHERE code='ADMIN01';"
```

El mail que pongas es donde vas a recibir los códigos de 2FA + recupero. **Tiene que ser tu mail real**, no necesariamente `admin@tnsvt.com` (puede ser tu Gmail si querés).

### Paso D: Smoke test (1 min)

```bash
# Disparar forgot para tu admin
curl -s -X POST https://tnsvt.com/api/auth/password/forgot \
  -H 'Content-Type: application/json' \
  -d '{"code":"ADMIN01"}'
# Esperá: 200 {"success":true,"message":"Si el código existe..."}
# Revisá tu mail real — debería llegar el código de 6 dígitos en <30s.
```

Si no llega:
1. hPanel → Emails → `admin@tnsvt.com` → **Manage** → **Webmail** (Roundcube) → verificar si el mail llegó ahí. Si sí, el problema es del envío desde Symfony; si no, Hostinger no lo aceptó.
2. Logs server: `tail -50 ~/domains/tnsvt.com/public_html/var/log/prod-$(date +%F).log | grep -i mail`.
3. Si ves `[MAIL] send failed`, revisar que `MAILER_DSN` esté bien encoded (especialmente si el password tiene caracteres especiales).
4. Si ves `[MAIL] dumped to mailbox`, el mailer no se levantó — warmup no corrió o typo en variable.

> **Lección 2026-10-01 (incidente SMTP 535):** el smoke del Paso D es
> insuficiente — `code/forgot` y `password/forgot` responden 200 genérico
> **siempre** (anti-enumeración), incluso cuando el mail muere en el
> transporte. Un 200 NO prueba entrega. Tras cada prueba de mail:
> `grep -a 'MAIL' var/log/prod-$(date +%F).log` — ausencia de
> `[MAIL] send failed` + mail real en inbox = único verde válido.
> Ese día el `MAILER_DSN` tenía password vieja (hPanel la había rotado) y
> 9 envíos murieron con `535` sin que ningún smoke lo detectara.
> Regla: `&` en password → `%26` en el DSN; ante un 535, resetear la
> password del buzón en hPanel → Email Accounts y actualizar el DSN.

### Paso F: Habilitar 2FA opcional (10 min)

Una vez que los códigos llegan:

1. Login como admin en tnsvt.com → perfil → cargar tu mail → verificarlo con el código que te llegó.
2. Repetir para otros usuarios gradualmente.
3. Cuando todos tengan mail verificado y quieran forzar 2FA:

```dotenv
# .env.local del server
TWO_FACTOR_MODE=mandatory
TWO_FACTOR_GRACE_UNTIL=2026-12-31
```

Warmup. 2FA ahora es obligatorio para todos.

## Si Hostinger Email no está disponible en tu plan

Algunos planes Premium/Business sí lo traen; planes más baratos no. Si no aparece "Email Accounts" en tu hPanel, las opciones son:

### Plan B: Brevo (300/día gratis)

Ver sección 2 del runbook anterior (commit `e61ae32`). Mismo flujo, solo cambia el DSN:

```dotenv
MAILER_DSN=smtp://USUARIO_BREVO:xsmtpsib-...@smtp-relay.brevo.com:587
```

No requiere mailbox en tu dominio. `MAIL_FROM` debe ser un mail verificado en Brevo (Gmail, etc.).

### Plan C: Gmail App Password (gratis, 500/día)

1. Google account → Security → 2-Step Verification → enable.
2. App passwords → crear "TNSVT Symfony" → genera password de 16 chars.
3. `MAILER_DSN=smtp://tu-mail@gmail.com:APP_PASSWORD_16_CHARS@smtp.gmail.com:587`.

No requiere tu dominio. `MAIL_FROM=tu-mail@gmail.com`.

## 2. Google Drive API Key (10 min) — habilita `/meditacion` sync

### 2.1 Crear API Key
3. **APIs & Services → Library** → buscar "Google Drive API" → Enable.
4. **APIs & Services → Credentials** → **+ Create Credentials → API key**.
5. Copiar la key (string tipo `AIzaSyD...`). Click Close.
6. **CRÍTICO**: click en la key recién creada → **Application restrictions**:
   - "IP addresses" → "Add" → pegar la IP del server (ver 1.2).
   - "API restrictions" → "Restrict key" → seleccionar solo "Google Drive API".
7. Si la key se filtra: rotar (Credentials → click en key → Regenerate).

### 2.2 Obtener IP del server

```bash
ssh -i ~/.ssh/id_tnsvt_deploy_oc -p 65002 u310596868@185.173.111.201 "curl -s https://api.ipify.org"
```

Anotar. Hostinger shared tiene IP dinámica: si Brevo/sync empieza a fallar, regenerá la key y actualizá la IP.

### 2.3 Hacer pública la carpeta de Drive

- Crear/elegir carpeta en Drive.
- Click derecho → Compartir → "Cualquier persona con el enlace" → Lector.
- Copiar URL. Formato: `https://drive.google.com/drive/folders/1abcDEFghijKLMNopq_xyz`.
- El ID es la parte final después de `folders/`. También válido: `file/d/ID/view` o ID raw.

### 2.4 Setear API Key en server

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

### 2.5 Warmup + smoke

```bash
cd ~/domains/tnsvt.com/public_html
rm -rf var/cache/prod
php bin/console cache:warmup --env=prod --no-debug
```

No requiere redeploy completo (composer, migrations, etc.) — solo warmup.

### 2.6 Verificar

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

## 3. Mail real en prod con Brevo (10 min) — alternativa si Hostinger mail no está

Hostinger mail requiere mover nameservers a Hostinger y esperar propagación. Brevo es inmediato (300/día gratis).

### 3.1 Crear cuenta Brevo

1. https://www.brevo.com/ → Sign up free → confirmar mail real (Gmail, etc.).
2. Dashboard → completar nombre empresa y país.

### 3.2 Verificar remitente

1. Brevo → **Settings → Senders & Domains**.
2. **Add an email** → `tu-mail-real@gmail.com` (rápido) o **Add a domain** (`tnsvt.com`, lento si está parqueado).
3. Click "Send verification email" → abrir mail → click en el link → queda verificado.

### 3.3 Crear SMTP key

1. Brevo → **Settings → SMTP & API → SMTP**.
2. **Generate a new SMTP key** → nombre `TNSVT prod` → Generate.
3. Copiar: `user` (mail Brevo) y `password` (string tipo `xsmtpsib-...`). El password NO se vuelve a mostrar.
4. Servidor: `smtp-relay.brevo.com`. Puerto 587 (STARTTLS) o 465 (TLS) — ambos validados en prod.

### 3.4 Configurar .env.local del server

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

### 3.5 Smoke

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

### 3.6 Setear mail real del admin

El endpoint `forgot` emite código solo si `user.email` está cargado y `user.email_verified_at` no es null.

```sql
-- Conectar via phpMyAdmin (hPanel) o SSH si tenés sudo:
UPDATE users SET email = 'tu-mail-real@gmail.com', email_verified_at = NOW() WHERE code = 'ADMIN01';
```

Después desde panel admin (`/sanctum/users/ADMIN01` → "Email") queda en `users.email` para futuras referencias.

### 3.7 Fases 2FA (siguiente nivel)

```dotenv
TWO_FACTOR_MODE=optin      # no obligatorio, sólo si el user carga mail
TWO_FACTOR_GRACE_UNTIL=    # vacío
```

Subir a `mandatory` cuando todos hayan cargado mail:

```dotenv
TWO_FACTOR_MODE=mandatory
TWO_FACTOR_GRACE_UNTIL=2026-12-31   # fin de plazo
```

## 4. Rollback

Mail o Drive rotos → revertir a modo dev:

```dotenv
# En .env.local del server, comentar / dejar fallback:
GOOGLE_DRIVE_API_KEY=  # vacío → controller devuelve 502 con error claro
MAILER_DSN=null://null  # vuelve a mailbox dump a var/mailbox/*.json
TWO_FACTOR_MODE=disabled  # desactiva 2FA
```

Warmup. La app sigue funcionando — solo pierde las features nuevas.

## 5. Crear buzones extra para usuarios (cuando crezca)

En hPanel → Emails → Create new account para cada user con buzón dedicado (`nombre_usuario@tnsvt.com`). Mismo SMTP (`smtp.hostinger.com:587`). Ventaja: separar logs por user en hPanel, más orden.

Si tenés >100 usuarios activos, considerar habilitar el plan Email Premium de Hostinger (no es gratis, ~$1/mes/buzón) o migrar a Brevo/Gmail para mayor cuota de envío.