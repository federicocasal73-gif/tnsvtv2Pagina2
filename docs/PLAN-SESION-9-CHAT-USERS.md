# PLAN — Sesión 9: Trono de Control + chat widget (3 bugs de prod)

> Fecha: 2026-09-29. Estado: planificado y revisado, **no ejecutado**.
> Repo: `tnsvt-app/` (Symfony 8.1 + Stimulus, este mismo directorio).
> Origen: reporte del dueño con captura — (1) botón AGREGAR ADEPTO sin
> efecto en `/sanctum/users`, (2) lista "Nuevo mensaje directo" con
> nombre+código pegados y todos en "off", (3) crear DM falla con
> `other_code requerido` (stack: `startDmWith` → `apiFetch`).

---

## 1. Diagnóstico (confirmado por código, no hace falta re-investigar)

### Bug A — modal Agregar adepto muerto (y todos los modales `apiSetupModal`)
`templates/_partials/api_helper.html.twig:528-533` ejecuta a nivel
top-level, durante el parseo del `<head>` (el partial se incluye en
`templates/shell.html.twig:70`, antes del `<body>`):

```js
if (window.MutationObserver) {
    new MutationObserver(apiA11yLoading).observe(document.body, { ... });
}
```

Ahí `document.body` es `null` → `TypeError` → **todo el script inline
aborta** → `window.apiSetupModal = apiSetupModal` (línea 757) **nunca se
ejecuta**. En `src/assets/controllers/users_admin_controller.js:32` el
guard `typeof window.apiSetupModal === 'function'` falla en silencio y
el botón queda muerto. (`apiFetch` sobrevive porque se asigna antes —
por eso la lista sí carga.) Es la única línea top-level que toca
`document.body` (verificado: el resto están dentro de funciones).

### Bug B — "no me deja chatear: `other_code requerido`"
`apiFetch` (`api_helper.html.twig:134-156`) pasa `opts` directo a
`fetch` **sin serializar el body**: un objeto plano viaja como el string
`"[object Object]"`. El widget manda ~14 POSTs con objeto plano
(`chat_widget_controller.js`: `startDmWith:655`, envío de mensajes,
emoji, typing, read, subscribe, adjuntos…). El servidor no puede leer
`other_code` → 400. El ping "anda" solo porque el auth viaja por header
`X-Game-Code`. La página de chat no sufre esto (sus 3 bodies ya usan
`JSON.stringify`); al widget nunca lo arreglaron.

### Bug C — display + presencia del widget
- Sin filtración de contraseña: los serializers exponen solo
  `code/name/is_me/is_admin/online` (verificado en `ChatController`).
  Lo que se ve pegado (`axelvaldezAXEL9927`) es nombre+código sin CSS:
  `.chat-widget-user-name/-meta` **no tienen ninguna regla**.
- El "off" generalizado es semántica real (`User::isOnline()` =
  `lastActivityAt` dentro de 2 min). Decisión del dueño: mostrar
  **"últ. vez hace X min"** cuando está off.

---

## 2. Alcance

**Entra:** fixes A–C + hardening mínimo + verificación + deploy.
**No entra:** Bug #5 del e2e (`account_size=0`, track separado), suite
e2e completa en verde, Mercure externo, backfill de datos.

---

## 3. Pasos de ejecución (en orden)

### Paso 1 — `templates/_partials/api_helper.html.twig`: guard MutationObserver
Reemplazar (líneas ~527-533):

```js
    // Re-scan when DOM mutates (new loading-pulse elements added).
    if (window.MutationObserver) {
        new MutationObserver(apiA11yLoading).observe(document.body, {
            childList: true,
            subtree: true,
        });
    }
```

por:

```js
    // Re-scan when DOM mutates (new loading-pulse elements added).
    // NOTE: this partial is included in <head>, where document.body is
    // still null. Observing null throws and aborts the whole inline
    // script, leaving window.apiSetupModal et al. undefined (bug
    // 2026-09-29: dead "Agregar adepto" button). Defer until body exists.
    if (window.MutationObserver) {
        const observeBody = () => {
            if (document.body) {
                new MutationObserver(apiA11yLoading).observe(document.body, {
                    childList: true,
                    subtree: true,
                });
            }
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', observeBody, { once: true });
        } else {
            observeBody();
        }
    }
```

### Paso 2 — `templates/_partials/api_helper.html.twig`: serializar bodies en `apiFetch`
Insertar inmediatamente después del bloque X-Game-Code (después de la
línea `}` que cierra el `if (!opts.headers ...)` y antes de
`const controller = new AbortController();`):

```js
        // Serialize plain-object bodies as JSON. Without this, fetch coerces
        // them to "[object Object]" and the backend can't read any field
        // (bug 2026-09-29: widget startDmWith → 400 'other_code requerido',
        // plus message send / emoji / typing / read / subscribe broken the
        // same way). FormData/Blob/URLSearchParams/strings pass through
        // untouched; an explicit caller Content-Type always wins.
        const _b = opts.body;
        if (
            _b &&
            typeof _b === 'object' &&
            !(typeof FormData !== 'undefined' && _b instanceof FormData) &&
            !(typeof Blob !== 'undefined' && _b instanceof Blob) &&
            !(typeof URLSearchParams !== 'undefined' && _b instanceof URLSearchParams) &&
            !(typeof ArrayBuffer !== 'undefined' && _b instanceof ArrayBuffer) &&
            !(typeof ArrayBuffer !== 'undefined' && ArrayBuffer.isView(_b))
        ) {
            opts.body = JSON.stringify(_b);
            const _h = opts.headers;
            const _hasCT =
                _h &&
                (typeof _h['Content-Type'] !== 'undefined' ||
                    typeof _h['content-type'] !== 'undefined');
            if (!_hasCT && (!_h || _h.constructor === Object)) {
                opts.headers = Object.assign({ 'Content-Type': 'application/json' }, _h || {});
            }
        }
```

Seguridad del cambio (ya auditado): ~15 callers con `JSON.stringify`
(string → intactos); 4 con `FormData` (`campus:872`,
`chat_widget:693`, `profile:149`, `sonic:873` → salteados); `null`/
`undefined` → salteados por el guard `_b &&`. Ningún endpoint podía
depender del comportamiento roto (antes llegaba `"[object Object]"` sin
Content-Type: el form-bag ya venía vacío).

### Paso 3 — `templates/sanctum/users.html.twig`: `type="button"`
En `#add-user-btn` (línea ~121) y `#refresh-btn` (línea ~118): agregar
`type="button"`. (Higiene: fuera de un `<form>` hoy, pero blinda
re-submit si el markup cambia.)

### Paso 4 — `src/assets/controllers/users_admin_controller.js`: open con toast
Reemplazar:

```js
            addBtn.addEventListener('click', () => this._addUserModal.open());
```

por:

```js
            addBtn.addEventListener('click', () => {
                try {
                    this._addUserModal.open();
                } catch (err) {
                    if (window.apiToast)
                        window.apiToast('No se pudo abrir el modal: ' + err.message, 'error');
                    else console.error(err);
                }
            });
```

### Paso 5 — `src/Controller/Api/ChatController.php`: exponer `last_activity_at`
En `listUsers()` (línea ~528), agregar al mapa `$data`:

```php
'last_activity_at' => $u->getLastActivityAt()?->format('c'),
```

 Timestamp plano, nada sensible. No toca OpenAPI (sin anotaciones
 nuevas) ni baseline de PHPStan (`?->format()` sobre
 `?\DateTimeImmutable` es válido).

### Paso 6 — `src/assets/controllers/chat_widget_controller.js`: presencia relativa
Agregar helper (cerca de `esc`/`initials`, como método de la clase o
función local — respetar `prefer-const` del ESLint):

```js
presenceLabel(u) {
    if (u.online) return '🟢 en línea';
    const ts = u.last_activity_at ? Date.parse(u.last_activity_at) : NaN;
    if (Number.isNaN(ts)) return 'off';
    const mins = Math.max(0, Math.round((Date.now() - ts) / 60000));
    if (mins < 1) return 'hace un momento';
    if (mins < 60) return `hace ${mins} min`;
    const hours = Math.round(mins / 60);
    if (hours < 24) return `hace ${hours} h`;
    return `hace ${Math.round(hours / 24)} d`;
}
```

Y en el template del item (línea ~645), cambiar el meta a dos líneas
legibles:

```js
`<button class="chat-widget-user-item" data-user-code="${this.esc(u.code)}"><span class="chat-widget-user-name">${this.esc(u.name || u.code)}</span><span class="chat-widget-user-meta">${this.esc(u.code)} · ${this.esc(this.presenceLabel(u))}</span></button>`
```

### Paso 7 — `src/assets/styles/chat-widget.css`: layout del item
Agregar al final (verificar que no colisione con reglas existentes —
`grep` no encontró ninguna para estas clases):

```css
.chat-widget-user-item {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    width: 100%;
    text-align: left;
}
.chat-widget-user-name {
    display: block;
    font-weight: 600;
}
.chat-widget-user-meta {
    display: block;
    font-size: 0.7rem;
    color: var(--outline-elev);
    margin-top: 2px;
}
```

Si el markup ya trae contenedor en columna, ajustar a `flex-direction:
column; align-items: flex-start;` según lo que se vea.

---

## 4. Verificación (antes de commitear)

```bash
php bin/console lint:twig templates
npm run lint
npm run format:check
npm run stylelint
python bin/lint-inline-js.py templates
php vendor/bin/phpstan analyse --memory-limit=1G --no-progress
php vendor/bin/phpunit
npm run test:a11y
```

Todo debe estar verde salvo warnings ESLint pre-existentes (12). El
test e2e del ping (`npm test` corre en CI con secrets ya seteados)
cubre `/api/chat/ping`; los specs de journal tienen fallos propios
conocidos (Bug #5, chips) fuera de este alcance.

---

## 5. Commit + deploy

```bash
git add -A
git commit -m "fix(chat+users): modal apiSetupModal, bodies JSON en apiFetch, presencia relativa" -m "..."
git push origin main
```

Deploy (sin bump de `APP_VERSION`: no cambian assets con hash que
afecten SW… **OJO**: sí cambian `chat-widget.css` y `api_helper`
inline — `api_helper` es inline (no hasheado), el CSS sí genera nuevo
hash. Si `app:assets:clean` reporta stale files eliminados, bumpear
`APP_VERSION` en `.env` + `.env.local` del servidor antes del warmup):

```bash
ssh -i ~/.ssh/id_tnsvt_deploy_oc -p 65002 -o StrictHostKeyChecking=accept-new \
    u310596868@185.173.111.201 \
     "cd ~/domains/tnsvt.com/public_html && \
     git fetch origin main && \
     git reset --hard origin/main && \
     rm -rf var/cache/prod var/cache/dev && \
     php bin/console cache:warmup --env=prod --no-debug && \
     php bin/console doctrine:migrations:migrate --env=prod --no-interaction && \
     php bin/console asset-map:compile --env=prod --no-interaction && \
     php bin/console app:assets:clean --env=prod --no-interaction --apply"
```

---

## 6. Prueba en prod (dueño, con tu usuario admin)

1. `/sanctum/users` → AGREGAR ADEPTO → se abre el modal → crear
   `TEST99`/`Prueba` → toast success + aparece en la lista → eliminarlo.
2. Página de mensajes → Nuevo mensaje directo → lista legible
   (nombre arriba, código + presencia abajo) → crear DM → mandar mensaje.
3. `curl -sI https://tnsvt.com/api/health/migrations` → 200.

---

## 7. Definition of done

- [ ] Modal abre/cierra/crea sin errores en consola.
- [ ] DM se crea y el mensaje llega (ida).
- [ ] Presencia muestra 🟢 / "hace X min".
- [ ] CI verde salvo `lighthouse` (403 hcdn, conocido) y `e2e` (Bug #5/chips, track separado).
- [ ] Fila Sesión 9 agregada a `docs/PLAN-CLEANUP-2026-09-28.md` §7.

## 8. Riesgos mitigados en revisión

- `document.body`: línea 529 era el **único** acceso top-level (resto en
  funciones) — el guard cubre el 100% del crash de eval.
- `body: null/undefined` → guard `_b &&` los saltea (fetch los ignora
  como hoy).
- `body: fd` (4× FormData) → salteados por `instanceof`.
- Strings ya serializados → `typeof` los deja pasar.
- Servidor: todos los endpoints relevantes leen
  `json_decode($request->getContent())` con fallbacks — JSON válido
  solo puede mejorar lo que hoy es basura.
- `Intl` no se usa (formato manual) → cero riesgo de locale.
- `last_activity_at` no expone nada sensible.
