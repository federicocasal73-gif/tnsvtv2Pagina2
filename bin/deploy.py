"""
Deploy TNSVT Reino v2 (Phase 0) a Hostinger compartido.
Versión 2 — usa paths absolutos con /home/ prefix para evitar permisos.
"""

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
KEYFILE = os.path.expanduser(r"~\.ssh\id_hostinger_v2")
REMOTE_DIR = "/home/u310596868/domains/lightskyblue-turtle-221397.hostingersite.com/public_html"
LOCAL_ROOT = Path(__file__).resolve().parent.parent
SSH_PASSPHRASE = os.environ.get("SSH_PASSPHRASE", "")

def connect():
    if not SSH_PASSPHRASE:
        print("[deploy] ERROR: SSH_PASSPHRASE no está en env vars.")
        sys.exit(1)
    key = paramiko.Ed25519Key.from_private_key_file(KEYFILE, password=SSH_PASSPHRASE)
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(HOST, port=PORT, username=USER, pkey=key, timeout=30)
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

UPLOAD_PLAN = [
    ("bin", "bin"),
    ("config", "config"),
    ("public", "public"),
    ("src", "src"),
    ("templates", "templates"),
    ("assets", "assets"),
]

# Single-file uploads (root files)
ROOT_FILES = [
    "composer.json",
    "composer.lock",
    "symfony.lock",
    ".env",
    ".env.dev",
    ".env.test",
    # .env.local is NEVER uploaded (sensitive)
]

def main():
    print("=" * 60)
    print("T.N.S.V.T Reino v2 — Phase 0 Deploy v2")
    print(f"Target: {USER}@{HOST}:{PORT}{REMOTE_DIR}")
    print("=" * 60)

    if not SSH_PASSPHRASE:
        print("\n[deploy] SSH passphrase es REQUERIDA.")
        print("Usage: $env:SSH_PASSPHRASE='tu_passphrase'; python bin/deploy.py")
        sys.exit(1)

    print("\n[1/7] Conectando a Hostinger...")
    client = connect()
    sftp = client.open_sftp()
    print(f"  ✓ Conectado como {USER}@{HOST}")

    print(f"\n[2/7] Verificando directorio remoto...")
    run_ssh(client, f"ls -la {REMOTE_DIR}/ | head -10")

    # Delete default.php (Hostinger placeholder)
    print(f"\n[3/7] Limpiando default.php de Hostinger...")
    try:
        sftp.remove(REMOTE_DIR + "/default.php")
        print("  ✓ default.php removido")
    except IOError:
        print("  - default.php no existe")

    # Upload
    print(f"\n[4/7] Subiendo archivos ({len(UPLOAD_PLAN)} directorios + {len(ROOT_FILES)} root files)...")
    total_uploaded = 0
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

    print(f"\n  TOTAL: {total_uploaded} files uploaded")

    # composer install
    print(f"\n[5/7] composer install --no-dev en el server...")
    out, _ = run_ssh(client, f"cd {REMOTE_DIR} && composer install --no-dev --optimize-autoloader --no-interaction 2>&1 | tail -25", check=False)

    # cache clear
    print(f"\n[6/7] Limpiando cache prod...")
    run_ssh(client, f"cd {REMOTE_DIR} && php bin/console cache:clear --env=prod 2>&1 | tail -5", check=False)
    run_ssh(client, f"cd {REMOTE_DIR} && php bin/console cache:warmup --env=prod 2>&1 | tail -5", check=False)

    # JWT keys
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

    sftp.close()
    client.close()

    print("\n" + "=" * 60)
    print("✓ Deploy completo")
    print(f"  URL: https://lightskyblue-turtle-221397.hostingersite.com")
    print("=" * 60)

if __name__ == "__main__":
    main()