# Runbook — 2FA por mail + recupero de contraseña

Estado: implementado y testeado SOLO local (2026-09-29). **No deployado a prod.**

## Qué se construyó

- `TwoFactorChallenge` + migración `Version20260930000000` (tabla + 3 columnas en `users`).
- `TwoFactorService` (modos `disabled|optin|mandatory`, gracia, issue/verify/resend) + `AppMailer`
  (Brevo por `MAILER_DSN`; con `null://` vuelca a `var/mailbox/*.json`).
- Login con ramas `two_factor_required` / `enrollment_required` + warning de gracia.
- Password con política mín 10: se exige si existe (admin igual que siempre).
- Endpoints: `2fa/verify`, `2fa/resend`, `2fa/enroll/start`, `password/forgot`,
  `password/reset`, `password/reset-by-code`, `profile/email[/verify]`,
  `profile/password`, `PATCH sanctum/api/users/{code}/security` (email + exempt).
- Login UI: paso-2 (código, timer, reenvío), enrolamiento, recupero, campo
  password siempre visible. Perfil: tarjeta Mail+Contraseña. Banner de gracia
  en shell. Exención 2FA en tarjetas admin.
- Exención auditable `two_factor_exempt` para cuentas de servicio (e2e/APK).

## Rollout a prod (cuando el dueño lo pida)

1. **Brevo:** cuenta gratis → Settings → SMTP & API → crear SMTP key.
   Verificar remitente/dominio `tnsvt.com` (o usar temporal de Brevo).
2. **Prod `.env.local`** (nunca commitear):
   ```
   MAILER_DSN=smtp://USER:PASS@smtp-relay.brevo.com:587
   MAIL_FROM=noreply@tnsvt.com
   TWO_FACTOR_MODE=optin
   TWO_FACTOR_GRACE_UNTIL=
   ```
3. Deploy normal + warmup. Probar con el propio admin: perfil → cargar mail →
   verificar → logout → login (debe pedir código).
4. **Fase mandatory:** setear `TWO_FACTOR_GRACE_UNTIL=YYYY-MM-DD` (+7 días) y
   `TWO_FACTOR_MODE=mandatory`, warmup. Anunciar a usuarios que carguen su mail.
5. **E2E/APK:** eximir al usuario e2e desde el panel (botón "Eximir 2FA").
   La APK debe manejar `error_code: two_factor_required` antes de exigirlo a
   humanos. Refresh tokens NO cambian (sesiones vivas siguen andando).
6. **Rollback:** `TWO_FACTOR_MODE=disabled` + warmup. Cero cambios de schema
   que revertir (columnas nullable, tabla aparte).

## Soporte (casos)

- "No me llega el mail": revisar spam; reenviar (cooldown 60s, máx 3);
  admin puede cargarle otro mail desde Usuarios.
- "Perdí acceso total": admin edita mail + el usuario usa "Olvidé contraseña".
- Lockout masivo: volver a `optin` o extender `GRACE_UNTIL`.

## Local

- `var/mailbox/*.json` contiene los códigos (transporte null).
- Tests: `TwoFactorTest` (14), `SensitiveFieldStripTest` (+1), resto intacto.
- `$_ENV['TWO_FACTOR_MODE']` conmuta por test; default `disabled` no rompe nada.
