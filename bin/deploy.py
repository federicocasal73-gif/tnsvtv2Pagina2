"""
Deploy TNSVT Reino v2 (Phase 0) a Hostinger compartido.
Versión 2 — usa paths absolutos con /home/ prefix para evitar permisos.

v3 — soporta modo surgical con --files para subir solo archivos puntuales
y evitar composer install / JWT bootstrap. Útil para F1.x y fases pequeñas.
"""

import argparse
import os
import sys
import time
import paramiko
import fnmatch

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")

from pathlib import Path

# ===== Config =====
HOST = "185.173.111.201"
PORT = 65002
USER = "u310596868"
# id_tnsvt_deploy es el par autorizado en este server; id_hostinger_v2 fue
# probado y el server rechaza su pública (no está en authorized_keys).
KEYFILE = os.path.expanduser(r"~\.ssh\id_tnsvt_deploy")
REMOTE_DIR = "/home/u310596868/domains/tnsvt.com/public_html"
LOCAL_ROOT = Path(__file__).resolve().parent.parent
SSH_PASSPHRASE = os.environ.get("SSH_PASSPHRASE", None)

def connect():
    pwd = SSH_PASSPHRASE if SSH_PASSPHRASE else ""
    # paramiko tiene un quirk con keys OpenSSH-format sin cipher: aún con ciphername="none"
    # tira PasswordRequiredException al usar password=None. Pasamos password="" (string vacío)
    # para que efectivamente trate el key como unencrypted y permita cargar.
    try:
        key = paramiko.Ed25519Key.from_private_key_file(KEYFILE, password=pwd or None)
    except paramiko.ssh_exception.PasswordRequiredException:
        key = paramiko.Ed25519Key.from_private_key_file(KEYFILE, password="")
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    # look_for_keys=False evita que paramiko intente ~/.ssh/id_ed25519, id_rsa, id_ecdsa
    # por defecto — si esos keys tienen passphrase y caen en este flujo, fallaría.
    # allow_agent=False同理 para no consultar ssh-agent.
    client.connect(
        HOST,
        port=PORT,
        username=USER,
        pkey=key,
        look_for_keys=False,
        allow_agent=False,
        timeout=30,
    )
    return client

def run_ssh(client, cmd, check=True):
    print(f"[ssh] $ {cmd}")
    stdin, stdout, stderr = client.exec_command(cmd, timeout=300)
    out = stdout.read().decode("utf-8", errors="replace").strip()
    err = stderr.read().decode("utf-8", errors="replace").strip()
    if out:
        for line in out.split("\n")[:30]:
            print(f"  {line}")
    if err and check and "warning" not in err.lower():
        for line in err.split("\n")[:10]:
            print(f"  [err] {line}")
    return out, err

def sftp_exists(sftp, path):
    """Returns True if path exists, False otherwise."""
    try:
        sftp.stat(path)
        return True
    except IOError:
        return False

def sftp_mkdir_p(sftp, path):
    """mkdir -p via SFTP, idempotent."""
    if not path or path == "/":
        return
    parts = path.strip("/").split("/")
    current = ""
    for part in parts:
        current += "/" + part
        if not sftp_exists(sftp, current):
            try:
                sftp.mkdir(current)
            except IOError as e:
                # If it raced with another process and exists now, that's fine
                if not sftp_exists(sftp, current):
                    raise
    return current

def upload_dir(sftp, local_dir, remote_subdir, excludes=None):
    """Upload local_dir to REMOTE_DIR/remote_subdir."""
    excludes = excludes or []
    local_dir = Path(local_dir)
    if not local_dir.exists():
        print(f"[sftp] (skip) {local_dir} no existe")
        return 0
    remote_base = REMOTE_DIR + "/" + remote_subdir if remote_subdir else REMOTE_DIR
    files_uploaded = 0
    total_files = sum(1 for _ in local_dir.rglob("*") if _.is_file() and not any(fnmatch.fnmatch(str(_.relative_to(local_dir)).replace("\\","/"), pat) for pat in excludes))
    print(f"  → uploading {total_files} files to {remote_base}")
    for local_path in local_dir.rglob("*"):
        if not local_path.is_file():
            continue
        rel = local_path.relative_to(local_dir)
        rel_str = str(rel).replace("\\", "/")
        if any(fnmatch.fnmatch(rel_str, pat) for pat in excludes):
            continue
        remote_path = remote_base + "/" + rel_str
        sftp_mkdir_p(sftp, "/".join(remote_path.split("/")[:-1]))
        try:
            sftp.put(str(local_path), remote_path)
            files_uploaded += 1
            if files_uploaded % 25 == 0:
                print(f"    {files_uploaded}/{total_files}...")
        except Exception as e:
            print(f"    ERROR uploading {rel_str}: {e}")
    return files_uploaded

# ===== Plan =====
EXCLUDES = [
    "var/cache/**",
    "var/log/**",
    ".env.local",
    ".env.*.local",
    "node_modules/**",
    "*.swp",
    "*.bak",
    "*.log",
    "vendor/**",  # composer install on server, not upload
]

# Patrones prohibidos para el modo surgical — nunca sobreescribir sin querer.
SURGICAL_FORBIDDEN = [
    "vendor/",
    "node_modules/",
    "var/",
    ".git/",
    "composer.lock",
    ".env",
    ".env.local",
    ".env.prod",
    ".env.prod.local",
    "config/jwt/",
]

UPLOAD_PLAN = [
    ("bin", "bin"),
    ("config", "config"),
    # NOTE: "public" is intentionally NOT in UPLOAD_PLAN.
    # The Hostinger doc root IS /public_html/ (index.php + .htaccess at that level).
    # The "public" subdir is a symlink to the project root so asset-map:compile
    # writes to /public_html/assets/ (the correct serving path).
    # Uploading local/public/ to remote/public_html/public/ would overwrite the symlink
    # and re-introduce the bug where compile output went to public_html/public/assets/.
    # NOTE: "assets/" is NOT uploaded as a source anymore. Asset sources now live in
    # "src/assets/" (uploaded above via "src") so the served /assets/ directory holds
    # ONLY compiled output. Compile runs on the server and regenerates it from src/assets.
    ("src", "src"),
    ("templates", "templates"),
]

# Single-file uploads (root files)
ROOT_FILES = [
    "composer.json",
    "composer.lock",
    "symfony.lock",
    "importmap.php",
    ".env",
    ".env.dev",
    ".env.test",
    # .env.local is NEVER uploaded (sensitive)
]

# Public-root files: live at /favicon.* and /manifest.json (Hostinger doc root is /public_html/)
# These are uploaded to the doc root level (not /public/ subdir) so they're served at /<name>
PUBLIC_ROOT_FILES = [
    "favicon.ico",
    "favicon.svg",
    "manifest.json",
]


# ===== Surgical mode helpers =====
def is_forbidden_surgical(rel_path: str) -> str | None:
    """Return first forbidden pattern matched, or None."""
    rel_path = rel_path.replace("\\", "/")
    for pat in SURGICAL_FORBIDDEN:
        if pat in rel_path:
            return pat
    return None


def upload_single_file(sftp, rel_path: str) -> bool:
    """Upload one file by relative path. Returns True on success.

    Refuses absolute paths, '..' traversal, and forbidden patterns.
    Creates parent directories on the remote as needed.
    """
    rel_path = rel_path.strip().replace("\\", "/")
    if not rel_path:
        return False

    # Reject absolute / traversal
    parts = Path(rel_path).parts
    if Path(rel_path).is_absolute() or ".." in parts or parts and parts[0].startswith("/"):
        print(f"    ✗ {rel_path}: rechazado (absoluto o contiene '..')")
        return False

    # Reject forbidden patterns
    forbidden = is_forbidden_surgical(rel_path)
    if forbidden:
        print(f"    ✗ {rel_path}: rechazado (patrón prohibido '{forbidden}')")
        return False

    local_path = LOCAL_ROOT / rel_path
    if not local_path.exists() or not local_path.is_file():
        print(f"    ✗ {rel_path}: no existe localmente o no es archivo")
        return False

    remote_path = REMOTE_DIR + "/" + rel_path
    parent_remote = "/".join(remote_path.split("/")[:-1])
    sftp_mkdir_p(sftp, parent_remote)

    sftp.put(str(local_path), remote_path)
    print(f"    ✓ {rel_path} → {remote_path[len(REMOTE_DIR):]}")
    return True


def parse_args():
    parser = argparse.ArgumentParser(
        description="TNSVT v2 deploy a Hostinger. Modo surgical o full repo sync.",
    )
    parser.add_argument(
        "--files",
        help=(
            "Lista separada por comas de archivos (relativos a la raíz del repo) "
            "a subir. Si está vacío, hace sync completo."
        ),
    )
    parser.add_argument(
        "--skip-composer",
        action="store_true",
        help="Omite 'composer install' incluso en modo full.",
    )
    parser.add_argument(
        "--skip-jwt",
        action="store_true",
        help="Omite chequeo/generación de JWT keys.",
    )
    parser.add_argument(
        "--no-cache-clear",
        action="store_true",
        help="Omite cache:clear + cache:warmup + asset-map:compile al final.",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Conecta, valida, lista el plan, pero NO sube ni ejecuta nada destructivo.",
    )
    return parser.parse_args()

def main():
    args = parse_args()
    file_mode = bool(args.files and args.files.strip())
    file_list = (
        [f.strip() for f in args.files.split(",") if f.strip()]
        if file_mode else []
    )

    print("=" * 60)
    if file_mode:
        print(f"T.N.S.V.T v2 — Surgical deploy ({len(file_list)} files)")
    else:
        print("T.N.S.V.T Reino v2 — Phase 0 Deploy v2 (full repo)")
    print(f"Target: {USER}@{HOST}:{PORT}{REMOTE_DIR}")
    if file_mode:
        print(f"Files:")
        for f in file_list:
            print(f"  · {f}")
    if args.dry_run:
        print("⚠ DRY-RUN — no se subirá ni ejecutará nada destructivo")
    print("=" * 60)

    if args.dry_run:
        # In dry-run, connect just to validate reachability and exit cleanly
        print("\n[dry-run] Conectando para validar reachability...")
        client = connect()
        client.close()
        print("✓ Conexión OK. Saliendo sin cambios.")
        return

    print("\n[1/7] Conectando a Hostinger...")
    client = connect()
    sftp = client.open_sftp()
    print(f"  ✓ Conectado como {USER}@{HOST}")

    if not file_mode:
        print(f"\n[2/7] Verificando directorio remoto...")
        run_ssh(client, f"ls -la {REMOTE_DIR}/ | head -10")

        # Delete default.php (Hostinger placeholder)
        print(f"\n[3/7] Limpiando default.php de Hostinger...")
        try:
            sftp.remove(REMOTE_DIR + "/default.php")
            print("  ✓ default.php removido")
        except IOError:
            print("  - default.php no existe")
    else:
        print(f"\n[2/7] (skip — modo surgical)")
        print(f"\n[3/7] (skip — modo surgical: no tocamos default.php)")

    # Upload
    print(f"\n[4/7] Subiendo archivos...")
    total_uploaded = 0
    if file_mode:
        for rel in file_list:
            ok = upload_single_file(sftp, rel)
            if not ok:
                print(f"    ✗ Abortando por archivo rechazado ({rel})")
                sftp.close()
                client.close()
                sys.exit(2)
            total_uploaded += 1
    else:
        for local_subdir, remote_subdir in UPLOAD_PLAN:
            local_path = LOCAL_ROOT / local_subdir
            if local_path.exists():
                print(f"\n  ▸ {local_subdir}/ → {remote_subdir}/")
                n = upload_dir(sftp, local_path, remote_subdir, EXCLUDES)
                print(f"  ✓ {n} files uploaded")
                total_uploaded += n
            else:
                print(f"  - (skip) {local_subdir}/ no existe")

        # Upload root files
        print(f"\n  ▸ root files...")
        for root_file in ROOT_FILES:
            local_path = LOCAL_ROOT / root_file
            if local_path.exists():
                remote_path = REMOTE_DIR + "/" + root_file
                try:
                    sftp.put(str(local_path), remote_path)
                    print(f"    ✓ {root_file}")
                    total_uploaded += 1
                except Exception as e:
                    print(f"    ✗ {root_file}: {e}")
            else:
                print(f"    - (skip) {root_file} no existe")

        # Upload public-root files (live at /favicon.*, /manifest.json)
        # These are in local public/ but uploaded to the doc-root level (not /public/ subdir)
        # so that the browser URL /favicon.ico resolves to public_html/favicon.ico
        if PUBLIC_ROOT_FILES:
            print(f"\n  ▸ public-root files (favicon, manifest)...")
            for pf in PUBLIC_ROOT_FILES:
                # Use public/<filename> as source but upload to REMOTE_DIR/<filename> (not REMOTE_DIR/public/<filename>)
                local_pf = LOCAL_ROOT / "public" / pf
                if local_pf.exists():
                    remote_pf = REMOTE_DIR + "/" + pf
                    try:
                        sftp.put(str(local_pf), remote_pf)
                        print(f"    ✓ {pf}")
                        total_uploaded += 1
                    except Exception as e:
                        print(f"    ✗ {pf}: {e}")
                else:
                    print(f"    - (skip) public/{pf} no existe")

    print(f"\n  TOTAL: {total_uploaded} files uploaded")

    # composer install — solo modo full y salvo --skip-composer
    if not file_mode and not args.skip_composer:
        print(f"\n[5/7] composer install --no-dev en el server...")
        run_ssh(client, f"cd {REMOTE_DIR} && composer install --no-dev --optimize-autoloader --no-interaction 2>&1 | tail -25", check=False)
    else:
        print(f"\n[5/7] (skip — composer install no requerido en modo surgical o por flag)")

    # cache:clear + warmup + asset-map:compile (siempre salvo --no-cache-clear)
    if not args.no_cache_clear:
        print(f"\n[6/7] Limpiando cache prod + rebuildeando assets...")
        run_ssh(client, f"cd {REMOTE_DIR} && php bin/console cache:clear --env=prod 2>&1 | tail -5", check=False)
        run_ssh(client, f"cd {REMOTE_DIR} && php bin/console cache:warmup --env=prod 2>&1 | tail -5", check=False)
        # 'assets/' on the server is ONLY the compile output (sources live in src/assets).
        # Wipe it before compiling: it may still hold stale source copies + old hashed
        # files from before the source/output separation, and compile regenerates it.
        run_ssh(client, f"cd {REMOTE_DIR} && rm -rf assets && php bin/console asset-map:compile --env=prod 2>&1 | tail -5", check=False)
    else:
        print(f"\n[6/7] (skip — --no-cache-clear)")

    # JWT — solo modo full y salvo --skip-jwt
    if not file_mode and not args.skip_jwt:
        print(f"\n[7/7] Verificando JWT keys...")
        out, _ = run_ssh(client, f"ls -la {REMOTE_DIR}/config/jwt/ 2>/dev/null", check=False)
        if "private.pem" in out:
            print("  ✓ JWT keys ya existen")
        else:
            print("  Generando JWT keys...")
            run_ssh(client, f"mkdir -p {REMOTE_DIR}/config/jwt")
            out, err = run_ssh(client, f"cd {REMOTE_DIR} && php bin/console lexik:jwt:generate-keypair --no-interaction 2>&1 | tail -10", check=False)
            if "private" in out or ".pem" in out:
                print("  ✓ JWT keys generadas")
    else:
        print(f"\n[7/7] (skip — JWT no requerido en modo surgical o por flag)")

    sftp.close()
    client.close()

    print("\n" + "=" * 60)
    print("✓ Deploy completo")
    print(f"  URL: https://tnsvt.com")
    print("=" * 60)

if __name__ == "__main__":
    main()