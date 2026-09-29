# JWT Keys

This directory holds the RSA keypair used by Lexik JWT to sign and
verify access tokens.

**The `.pem` files are gitignored** (see `.gitignore` lines 12-14).
Each environment must generate its own keypair on first deploy.

## First-time setup

On a fresh checkout (e.g. right after `git pull` on a new server):

```bash
php bin/generate-jwt-keys.php
```

The script reads `JWT_SECRET_KEY`, `JWT_PUBLIC_KEY` and `JWT_PASSPHRASE`
from the environment (loaded via Dotenv from `.env` / `.env.local`).
The default `.env` ships with `JWT_PASSPHRASE=!placeholder-rotate-in-env-local!`
— make sure `.env.local` overrides it with the production passphrase.

If you skip this step the first call to `/api/auth/login` will throw
`JWTEncodeFailureException: File .../private.pem does not exist`.

## Rotation

Rotate the keypair any time a private key is suspected of being
exposed (e.g. accidentally committed to a public repo, leaked through
a backup, or shared with a contractor that no longer needs access).

```bash
# 1. Generate a fresh keypair (the script refuses to overwrite unless
#    you pass --force)
php bin/generate-jwt-keys.php --force

# 2. Clear the cache so Symfony reloads the new keys
php bin/console cache:clear --env=prod --no-debug

# 3. Notify users that all existing sessions are invalidated —
#    everyone has to log in again. There is no way to keep old
#    refresh tokens valid across a key rotation, by design.
```

## Deploy scripts

`bin/deploy.sh` is documented in AGENTS.md §Deploy to Hostinger. If
you adapt it for a different target, make sure the deploy step that
runs `php bin/console cache:warmup` is preceded by a step that calls
`php bin/generate-jwt-keys.php` if `config/jwt/private.pem` is missing.

## Why we don't commit the keys

If the private key is in the repo, anyone with read access can sign
tokens that the server will accept as valid — full impersonation.
The previous workflow committed the keys (see git history pre-commit
`0cc357d`). The current `.gitignore` ensures the PEM files are never
committed again, but historical keys must be considered burned.

If you find a `private.pem` tracked by git, follow the rotation
procedure above AND audit the access log for token usage that
predates the rotation.
