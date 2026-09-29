#!/usr/bin/env python3
"""Lint inline <script> bodies inside Twig templates with node --check.

Extracts <script>...</script> bodies WITHOUT Twig tags ({{ ... }} or
{% ... %}) from templates/**/*.twig and runs `node --check` on each.
Catches the class of bug where an edit leaves template JS unparseable
(lint:twig only validates Twig syntax, not the JS inside).

Usage: python3 bin/lint-inline-js.py [templates-dir]
Exit code: 0 when clean, 1 on any syntax error.
"""

import re
import subprocess
import sys
import tempfile
from pathlib import Path


def main() -> int:
    templates_dir = Path(sys.argv[1]) if len(sys.argv) > 1 else Path("templates")
    failures = 0
    checked = 0

    for path in sorted(templates_dir.rglob("*.twig")):
        html = path.read_text(encoding="utf-8", errors="replace")
        if "<script>" not in html:
            continue
        # NOTE: matches <script> with or without attributes (nonce, src...).
        # Bodies containing Twig tags are skipped (can't parse statically).
        blocks = re.findall(r"<script\b[^>]*>(.*?)</script>", html, re.S | re.I)
        for i, body in enumerate(blocks):
            if "{{" in body or "{%" in body:
                continue
            if not body.strip():
                continue
            checked += 1
            with tempfile.NamedTemporaryFile(
                "w", suffix=".js", delete=False, encoding="utf-8"
            ) as tf:
                tf.write(body)
                name = tf.name
            try:
                r = subprocess.run(["node", "--check", name])
                if r.returncode != 0:
                    print(f"INLINE JS SYNTAX ERROR in {path} block {i}")
                    failures += 1
            finally:
                Path(name).unlink(missing_ok=True)

    print(f"Checked {checked} inline <script> block(s), {failures} failure(s).")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
