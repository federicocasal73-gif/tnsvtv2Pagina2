# Hostinger Support Ticket — Drop-in Template

Use this when opening a ticket with Hostinger support to disable the
`composer install` post-deploy hook on the `tnsvtv2Pagina2` repo.

> **You don't need to send this yourself if you prefer:** Hostinger hPanel
> has a live chat (login → hPanel → help widget). The same text below
> works for chat or the email contact form.

---

## English (recommended — Hostinger support is global)

**Subject:** Disable composer install post-deploy hook on git auto-deploy

**Body:**

> Hi,
>
> My project (`tnsvt.com`, repo `federicocasal73-gif/tnsvtv2Pagina2`) is
> connected to Hostinger's Git auto-deploy feature (hPanel → Advanced →
> Git → Connect with GitHub). After every `git pull`, your system
> automatically runs `composer install --prefer-dist --quiet
> --no-interaction`, and that step always fails with:
>
> ```
> install: In Process.php line 147:
>   The Process class relies on proc_open, which is not available
>   on your PHP installation.
> ```
>
> This is because Hostinger shared hosting has `proc_open` in
> `disable_functions` for security. Composer's runtime uses Symfony's
> Process class which requires `proc_open`, so Composer cannot run at
> all on shared hosting. There is no flag that bypasses this — even
> `--no-scripts` fails because the Process class is invoked by Composer
> itself during bootstrap.
>
> The actual application is fine — my `vendor/` directory is already
> populated in `public_html/` from a previous deploy when Composer was
> available, and the runtime PHP works correctly. The "Falló la
> compilación" status is purely cosmetic.
>
> **What I need:** Please remove the `composer install` post-deploy hook
> on my account, or change the auto-deploy preset for this repository
> to "PHP/HTML" (per Hostinger's own docs, the PHP/HTML Git preset
> serves files as-is without a build step).
>
> If composer install cannot be disabled, please enable `proc_open` for
> my PHP runtime (it's listed in `disable_functions` and only Hostinger
> staff can change it).
>
> Server: tnsvt.com (Hostinger shared hosting)
> Repo: federicocasal73-gif/tnsvtv2Pagina2
> Branch: main
> Affected deploys: every push since the proc_open restriction was set.
>
> Thank you.

---

## Spanish (alternative — if you prefer Spanish support)

**Asunto:** Desactivar composer install en auto-deploy de git

**Cuerpo:**

> Hola,
>
> Mi proyecto (`tnsvt.com`, repo `federicocasal73-gif/tnsvtv2Pagina2`) está
> conectado al auto-deploy de Git de Hostinger (hPanel → Avanzado → Git
> → Conectar con GitHub). Después de cada `git pull`, su sistema ejecuta
> automáticamente `composer install --prefer-dist --quiet --no-interaction`
> y siempre falla con:
>
> ```
> install: In Process.php line 147:
>   The Process class relies on proc_open, which is not available
>   on your PHP installation.
> ```
>
> El motivo es que el hosting compartido tiene `proc_open` deshabilitado
> en `disable_functions` por seguridad. Composer usa internamente la clase
> Process de Symfony, que requiere `proc_open`. Ningún flag lo evita (ni
> `--no-scripts` ni `--no-plugins`) porque la clase Process se invoca al
> arrancar Composer.
>
> El sitio funciona bien en realidad — mi `vendor/` ya está poblado en
> `public_html/` de un deploy previo y el runtime PHP responde
> correctamente. El estado "Falló la compilación" es puramente cosmético.
>
> **Lo que necesito:** Por favor, quiten el hook post-deploy de
> `composer install` en mi cuenta, o cambien el preset del auto-deploy a
> "PHP/HTML" (que según la documentación de Hostinger sirve los archivos
> sin ejecutar build).
>
> Si no se puede desactivar composer install, por favor habiliten
> `proc_open` en mi runtime PHP.
>
> Servidor: tnsvt.com (Hostinger hosting compartido)
> Repo: federicocasal73-gif/tnsvtv2Pagina2
> Branch: main
>
> Gracias.

---

## Self-service alternative (if Hostinger has a UI toggle)

1. hPanel → **Avanzado** → **Git**
2. Click on the `tnsvtv2Pagina2` repo card
3. Look for a project-type selector (commonly a dropdown / pill with
   options like "PHP/HTML", "Node.js", "Custom build")
4. Switch it from anything with build steps to **"PHP/HTML"** (or the
   equivalent "no build" option)
5. Save. The next auto-deploy will skip composer install entirely.

If the option isn't visible, ask Hostinger support to remove the
post-deploy hook on your account.

---

## What happens after the fix

- `git pull` to `public_html/` runs in a few seconds
- No more `composer install` step → no more "Falló la compilación"
- New commits in `main` show up live on `tnsvt.com` within minutes
- ⚠️ Caveat: until `composer.lock` no longer changes, no new PHP
  dependencies can be installed. If you ever need to add a new
  Composer package, you will need to upgrade to a Hostinger VPS or
  Business shared plan first (where `proc_open` is enabled).

See `AGENTS.md` for the full workaround ladder.
