# SSH Deploy to Hostinger

This document is the single source of truth for deploying the T.N.S.V.T
app to Hostinger shared hosting via SSH. It replaces the broken hPanel
Git auto-deploy (which always fails because Hostinger's `proc_open`
is in `disable_functions` — see [HOSTINGER-SUPPORT-TICKET.md](./HOSTINGER-SUPPORT-TICKET.md)
for the full history and why that workaround no longer applies).

## Architecture

```
developer → git push origin main
              ↓
         (no auto-deploy: hPanel auto-deploy disabled)
              ↓
         OpenCode agent → SSH into Hostinger
              ↓
         git reset --hard origin/main + cache rebuild
              ↓
         tnsvt.com live ✝
```

## Connection

| Field    | Value                  |
|----------|------------------------|
| Host     | `185.173.111.201`      |
| Port     | `65002` (not default 22 — Hostinger shared) |
| User     | `u310596868`           |
| Docroot  | `~/domains/tnsvt.com/public_html` |
| Key      | `~/.ssh/id_tnsvt_deploy_oc` (ed25519, no passphrase) |
| Comment  | `tnsvt-v2-deploy-2026` |

To add the public key on the Hostinger side:
1. hPanel → **Avanzado** → **Acceso SSH** → **Administrar claves SSH**
2. **Add** → paste the contents of `id_tnsvt_deploy_oc.pub`

## Deploy procedure

After each push to `main`, run this single command from any host that
has the deploy key in its `~/.ssh/`:

```bash
SSH_KEY=~/.ssh/id_tnsvt_deploy_oc
SSH_HOST=u310596868@185.173.111.201
SSH_PORT=65002
DOCROOT=~/domains/tnsvt.com/public_html

ssh -i "$SSH_KEY" -p "$SSH_PORT" -o StrictHostKeyChecking=accept-new \
    "$SSH_HOST" \
    "cd $DOCROOT && \
     git fetch origin main && \
     git reset --hard origin/main && \
     rm -rf var/cache/prod var/cache/dev && \
     php bin/console cache:warmup --env=prod --no-debug"
```

## What it does

1. **`cd $DOCROOT`** — navigate to the Symfony docroot
2. **`git fetch origin main`** — refresh refs from GitHub
3. **`git reset --hard origin/main`** — discard server-side changes,
   align working tree with `origin/main`. **WARNING**: this destroys any
   uncommitted changes on the server. If the server has local-only files
   you want to preserve, make a backup first.
4. **`rm -rf var/cache/{prod,dev}`** — wipe Symfony's compiled template
   cache. Necessary because Twig's `var/cache/prod/twig/` serves stale
   compiled PHP when source `.twig` files change.
5. **`php bin/console cache:warmup --env=prod --no-debug`** — rebuild
   the prod cache (container, routes, services, compiled Twig).

## What it does NOT do

- **`composer install`** — Hostinger shared has `proc_open` in
  `disable_functions`. Composer cannot run. `vendor/` is already
  populated and any new dependencies would require a manual upload
  via Hostinger File Manager OR a plan upgrade (VPS or Business
  shared). See `AGENTS.md` → "Hostinger proc_open limitation".
- **`php bin/console doctrine:migrations:migrate`** — only run if you
  added a new migration. Run it manually before the deploy if needed:
  ```bash
  ssh -i ~/.ssh/id_tnsvt_deploy_oc -p 65002 \
      u310596868@185.173.111.201 \
      "cd ~/domains/tnsvt.com/public_html && \
       php bin/console doctrine:migrations:migrate --no-interaction"
  ```

## Preserved files

The root `.htaccess` file in `public_html/` is **untracked** (Hostinger
needs a custom routing rule to map `/public/asset.css` →
`public/assets/styles/asset.css`). It survives `git reset --hard` because
it's not in the repo. The Symfony-side `public/.htaccess` IS tracked
and gets replaced on every deploy (which is correct — it should match
the Symfony version).

## Verification

```bash
# Page renders
curl -I https://tnsvt.com/login

# Sanctum fix is live (401 with proper message, not 500)
curl -sS https://tnsvt.com/sanctum/api/users

# API health
curl -sS https://tnsvt.com/api/auth/check
```

## Backup & rollback

If something goes wrong, the previous `public_html/` snapshot lives in
`public_html.backup-pre-ssh-deploy-YYYYMMDD-HHMMSS/` next to the
current `public_html/`. Restore with:

```bash
ssh -i ~/.ssh/id_tnsvt_deploy_oc -p 65002 u310596868@185.173.111.201 \
    "mv ~/domains/tnsvt.com/public_html ~/domains/tnsvt.com/public_html.broken && \
     cp -a ~/domains/tnsvt.com/public_html.backup-pre-ssh-deploy-* ~/domains/tnsvt.com/public_html && \
     cd ~/domains/tnsvt.com/public_html && \
     php bin/console cache:warmup --env=prod --no-debug"
```

## Disabling hPanel auto-deploy

After the SSH pipeline is verified, disable the Git auto-deploy in
hPanel so it stops trying to run `composer install` on every push:

1. hPanel → **Avanzado** → **Git**
2. On the `tnsvtv2Pagina2` repo card → **Disconnect** (or **Remove
   connection**, label depends on Hostinger version)
3. Confirm — auto-deploy will stop running

The `Falló la compilación` status will not appear in new deploys after
this.

## Key rotation

The deploy key (`id_tnsvt_deploy_oc`) was generated on the agent's
machine and uploaded to Hostinger by the operator. To rotate:

1. Generate a new key with the same script:
   ```bash
   ssh-keygen -t ed25519 -f ~/.ssh/id_tnsvt_deploy_oc -N "" \
       -C "tnsvt-v2-deploy-$(date +%Y)"
   ```
2. Add the new public key to Hostinger SSH keys
3. Remove the old public key from Hostinger SSH keys
4. Update this document with the new fingerprint

## Fingerprint (for audit)

```
SHA256:tLE73RqSVDp7BEtGom7Ge6zjY0B8WIp2WFqFsjVynEs tnsvt-v2-deploy-2026
```

If this fingerprint ever stops matching the key on the server,
investigate before deploying — the server may have been compromised.
