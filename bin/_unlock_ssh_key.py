"""
Remove passphrase from the deploy SSH key — one-shot helper.
Reads passphrase via getpass (no echo, no logs) and writes the
key back without encryption. Idempotent: if the key is already
unencrypted, no-op.
"""

import os
import sys
import paramiko
import getpass

KEYFILE = os.path.expanduser(r"~\.ssh\id_hostinger_v2")


def main() -> int:
    if not os.path.exists(KEYFILE):
        print(f"[ERROR] No existe {KEYFILE}")
        return 1

    # Try loading without passphrase first — if it works, key is already unlocked.
    try:
        paramiko.Ed25519Key.from_private_key_file(KEYFILE)
        print(f"[OK] {KEYFILE} ya está sin passphrase — nada que hacer.")
        return 0
    except paramiko.ssh_exception.PasswordRequiredException:
        pass  # expected: key has passphrase
    except Exception as e:
        print(f"[ERROR] No se pudo leer el key: {e}")
        return 1

    # Read passphrase securely (no echo, no terminal capture)
    pw = getpass.getpass(f"Passphrase actual de {KEYFILE}: ")
    if not pw:
        print("[ABORT] Passphrase vacía, saliendo.")
        return 2

    # Load with passphrase
    try:
        key = paramiko.Ed25519Key.from_private_key_file(KEYFILE, password=pw)
    except paramiko.ssh_exception.SSHException as e:
        print(f"[ERROR] Passphrase incorrecta: {e}")
        return 1

    # Write back unencrypted (overwrite same file)
    try:
        key.write_private_key_file(KEYFILE)
    except Exception as e:
        print(f"[ERROR] No se pudo reescribir el key: {e}")
        return 1

    # Verify
    try:
        paramiko.Ed25519Key.from_private_key_file(KEYFILE)
        print(f"[OK] Passphrase removida de {KEYFILE}.")
        print(f"     Backup recomendado: cp {KEYFILE} {KEYFILE}.bak.encrypted")
        return 0
    except Exception as e:
        print(f"[ERROR] Verificación post-write falló: {e}")
        return 1


if __name__ == "__main__":
    sys.exit(main())
