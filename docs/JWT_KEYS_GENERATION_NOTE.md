# JWT Keys Generation Issue

## Status
The local PHP environment (Windows winget install of PHP 8.4.22) has an OpenSSL bug
that prevents RSA key generation. The error is consistently:
```
error:80000003:system library::No such process
error:10000080:BIO routines::no such file
error:07000072:configuration file routines::no such file
```

Both:
- `php bin/console lexik:jwt:generate-keypair`
- `php -r "openssl_pkey_new([...])"` with various openssl.cnf files
- Failed with the same error.

## Root Cause (likely)
Missing DLL or OpenSSL configuration file in the Windows PHP install.
The OpenSSL library is loaded (we see "OpenSSL 3.0.20 7 Apr 2026") but
cannot find its required config file or random number generator source.

## Workaround
Generate keys directly on the production server (Hostinger) after first deploy:

```bash
# SSH into Hostinger
ssh -i ~/.ssh/id_hostinger_ed25519 -p 65002 u310596868@185.173.111.201

# Once the Symfony project is deployed:
cd domains/lightskyblue-turtle-221397.hostingersite.com/public_html
php bin/console lexik:jwt:generate-keypair --skip-if-exists

# OR with a custom passphrase:
php bin/console lexik:jwt:generate-keypair --passphrase="<strong-passphrase>"
```

The keys are then in `config/jwt/private.pem` and `config/jwt/public.pem`.

## Local Development (until keys are generated)
For local dev only, you can use the Lexik dev tokens:
- Use any test JWT (set `JWT_SECRET_KEY` env to dummy)
- Or generate keys via SSH onto a Linux machine

## .env configuration (no change needed)
The defaults are already set in `.env`:
```
JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
JWT_PASSPHRASE=change-me-in-env-local
```

In `.env.local` (production), override with the actual Hostinger-generated passphrase.