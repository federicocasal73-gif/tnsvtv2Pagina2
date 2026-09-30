# Plan: "Olvidé mi código" — recupero self-service por mail

**Estado:** pendiente de ejecución (redactado 2026-09-30, se ejecuta en otra ventana).
**Decisiones del dueño:** self-service por mail + solo mail verificado.
**Base:** `origin/main` @ `9c00fb7` (todo pusheado y deployado, verificado 2026-09-30).

> Los códigos de adepto son **inmutables por diseño** (identificador copiado
> como texto en ~10 tablas). No se regeneran ni renombran. Este plan implementa
> **recupero** (reenviar el código al mail verificado), no regeneración.

---

## 1. Contexto verificado (re-revisión 2026-09-30)

| Punto | Estado | Archivo:línea |
|---|---|---|
| No existe endpoint de recupero de código | Confirmado (`grep code/forgot` → 0) | — |
| Email 1:1 (409 si duplicado en enroll, perfil y admin) | Confirmado | `TwoFactorController.php:150-153`, `ProfileController.php:107`, `Sanctum/UsersController.php:140` |
| Verificación completa: enroll→verify setea `email_verified_at`; cambiar mail lo nullea | Confirmado | `TwoFactorController.php:80-81`, `ProfileController.php:113`, `Sanctum/UsersController.php:146-149` |
| Normalización `strtolower(trim())` idéntica en los 3 writers | Confirmado | `ProfileController.php:102`, `TwoFactorController.php:140` |
| **Hueco 1: el template del mail miente para este caso** — solo ramas `reset`/`enroll`, el `{% else %}` dice "(válido X minutos)" y un código de adepto no expira | Detectado en re-revisión | `templates/mail/auth_code.html.twig:6-15` |
| **Hueco 2: tests** — `TwoFactorTest::lastMailboxCode()` asume `/^\d{6}$/`; el dump de recupero tendrá `"code": "JUAN01"` → hace falta lector propio | Detectado en re-revisión | `tests/Functional/TwoFactorTest.php:61-97` |
| Rate limiter patrón `checkAndHit('bucket:'.$ip, 5, 3600)` | Confirmado | `TwoFactorController.php:134,173` |
| Paneles login: link forgot línea 99, panel `login-recover` línea 135, lista `showPanel` línea 343 | Confirmado | `templates/public/login.html.twig` |
| `showPanel` debe registrar el id nuevo o el panel no se muestra | Ojo | `login.html.twig:343` |

---

## 2. Backend — `POST /api/auth/code/forgot`

**Archivo:** `src/Controller/Api/TwoFactorController.php` (nuevo método, al lado de `forgot`).

```php
#[Route('/code/forgot', name: 'api_auth_code_forgot', methods: ['POST'])]
public function codeForgot(Request $request): JsonResponse
{
    $ip = $request->getClientIp() ?? '127.0.0.1';
    if ($this->rateLimiter->checkAndHit('code_forgot:' . $ip, 5, 3600) <= 0) {
        return $this->json(['success' => false, 'error' => 'Demasiados intentos. Probá en una hora.'], Response::HTTP_TOO_MANY_REQUESTS);
    }

    // Respuesta genérica SIEMPRE: no revelar si el mail existe.
    $data = json_decode($request->getContent(), true);
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    if ('' !== $email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $user = $this->users->findOneBy(['email' => $email]);
        if ($user instanceof User && $user->isActive() && $user->hasVerifiedEmail()) {
            $this->mailer->sendCode($email, (string) $user->getCode(), 'code');
        }
    }

    return $this->json([
        'success' => true,
        'message' => 'Si el mail existe y está verificado, enviamos tu código.',
    ]);
}
```

Notas:
- Sin challenge/entidad nueva: el payload ES el código (para admins no alcanza sin password; para users sin password equivale al reset, misma barra de seguridad).
- `User` ya importado en el controller; `mailer` inyectar `App\Service\AppMailer` (revisar constructor actual y agregar parámetro).
- Sin migración. Sin cambios a `security.yaml` (cae en `^/` PUBLIC_ACCESS como `password/forgot`).

## 3. Mailer + template

**`src/Service/AppMailer.php`** — agregar subject:
```php
'code' => 'Recuperá tu código de adepto',
```

**`templates/mail/auth_code.html.twig`** — agregar rama (NO reusar el else, miente con expiración):
```twig
{% elseif purpose == 'code' %}
  <h2>Tu código de adepto</h2>
  <p>Este es tu código de acceso al Sanctum. Es permanente, guardalo en un lugar seguro:</p>
```

## 4. Frontend — `templates/public/login.html.twig`

- Link "¿Olvidaste tu código?" junto al de contraseña (línea ~99).
- Panel nuevo `login-recover-code` (email + enviar + volver + error), espejando la estructura de `login-recover` (línea ~135).
- Registrar el id en la lista `showPanel` (línea 343) — sin esto el panel no se muestra.
- Handler JS con `apiFetch('/api/auth/code/forgot', {method:'POST', body:{email}})` → mensaje genérico siempre (éxito o error de red aparte).
- Panel separado del flujo password: no se toca `login-recover`/`login-reset`.

## 5. Tests — `tests/Functional/CodeForgotTest.php` (nuevo)

Seguir convenciones de `TwoFactorTest` (mailbox en `var/mailbox`, limpieza en setUp) pero con **lector propio** (el helper existente asume 6 dígitos):

1. Mail verificado → 200 genérico + dump en mailbox con `"code": "<CODE>"`.
2. Mail desconocido → 200 genérico + **sin** dump nuevo.
3. Mail no verificado → 200 genérico + sin dump.
4. Usuario inactivo → 200 genérico + sin dump.
5. 6º intento en 1h → 429.

## 6. Docs + versión

- `CHANGELOG.md`: entrada del feature.
- `docs/USER-FLOWS.md` (sección auth): agregar flujo "Olvidé mi código".
- Bump `APP_VERSION` en `.env` (+ `.env.local` en deploy).

## 7. Deploy + smoke

1. Commit + push + deploy estándar (pull, warmup, `asset-map:compile`, `app:assets:clean --apply`, bump `APP_VERSION`, `pkill lsphp`).
2. Smoke en prod **solo con mail inexistente** (`{"email":"nadie@example.com"}` → 200 genérico; verificar que NO sale mail real ni crece mailbox — en prod no hay mailbox, el transporte es SMTP real).
3. No probar con mail real salvo que el dueño lo pida explícitamente.

## 8. Explícitamente fuera de scope

- Regenerar/renombrar códigos (inmutables por FKs de texto).
- Magic login links (infra mayor; el flujo código+password/nombre ya existe).
- Tocar `password/forgot`, `password/reset`, `reset-by-code`.
- Endpoint admin de recupero (el admin ya puede ver el código en `/sanctum/users` y pasarlo por fuera; además puede cargar el mail del adepto vía `PATCH .../security` para habilitarle el self-service).

## 9. Flujo para quien NO tiene mail verificado

El mensaje del panel debe decirlo: "Si no tenés mail verificado, pedíselo a un administrador." El admin lo busca en `/sanctum/users` (búsqueda por nombre), le pasa el código por fuera y le carga el mail (`PATCH /sanctum/api/users/{code}/security`) para la próxima.
