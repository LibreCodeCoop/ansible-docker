#!/usr/bin/env python3
"""Reject known application credential literals without printing their values.

This checks the tracked working tree only. It does not sanitize historical Git
objects or replace GitHub secret scanning.
"""

from __future__ import annotations

import re
import subprocess
import sys
from pathlib import Path


TALK_ASSIGNMENT = re.compile(
    r"^\s*(hashkey|blockkey|internalsecret|apikey)\s*=\s*(.*)$",
    re.IGNORECASE,
)
PGADMIN_ASSIGNMENT = re.compile(
    r"^\s*(pgadmin_password)\s*[:=]\s*(.*)$",
    re.IGNORECASE,
)
ENV_ASSIGNMENT = re.compile(
    r"^\s*(DB_PWD|JWT_SECRET|POSTGRES_PASSWORD|MYSQL_ROOT_PASSWORD|"
    r"PGADMIN_DEFAULT_PASSWORD)\s*=\s*(.*)$",
    re.IGNORECASE,
)
JINJA_VARIABLE = re.compile(
    r"""^(['"]?)\{\{\s*[A-Za-z_][A-Za-z0-9_.]*\s*\}\}\1(?:\s+#.*)?$"""
)
ENV_VARIABLE = re.compile(r"^\$\{[A-Za-z_][A-Za-z0-9_]*\}$")
VAULT_TAG = re.compile(r"^!vault(?:\s+\|)?(?:\s+#.*)?$", re.IGNORECASE)


def is_external_reference(value: str, *, allow_empty: bool = False) -> bool:
    """Accept only simple variable references or an encrypted Vault YAML tag."""
    value = value.strip()
    if allow_empty and value in ("", "''", '""'):
        return True
    return bool(
        JINJA_VARIABLE.fullmatch(value)
        or ENV_VARIABLE.fullmatch(value)
        or VAULT_TAG.fullmatch(value)
    )


def scan_file(path: str, content: str) -> list[tuple[int, str]]:
    """Return (line number, field name) only; never return credential values."""
    normalized = path.replace("\\", "/")
    talk = normalized.startswith("roles/nextcloud-talk/")
    env = normalized.rsplit("/", 1)[-1] == ".env"
    matches: list[tuple[int, str]] = []
    for number, line in enumerate(content.splitlines(), start=1):
        if line.lstrip().startswith("#"):
            continue
        hit = PGADMIN_ASSIGNMENT.match(line)
        allow_empty = False
        if hit is None and talk:
            hit = TALK_ASSIGNMENT.match(line)
        if hit is None and env:
            hit = ENV_ASSIGNMENT.match(line)
            allow_empty = True
        if hit is not None and not is_external_reference(
            hit.group(2), allow_empty=allow_empty
        ):
            matches.append((number, hit.group(1)))
    return matches


def main() -> int:
    root = Path(__file__).resolve().parent.parent
    files = subprocess.run(
        ["git", "-C", str(root), "ls-files", "-z"],
        check=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
    ).stdout.split(b"\0")
    violations = 0
    for item in files:
        if not item:
            continue
        name = item.decode("utf-8", errors="surrogateescape")
        path = root / name
        if not path.is_file() or path.is_symlink():
            continue
        if path.suffix not in (".j2", ".yml", ".yaml", ".conf", ".ini", ".env") and path.name != ".env":
            continue
        text = path.read_text(encoding="utf-8", errors="replace")
        for line, field in scan_file(name, text):
            print(
                f"{name}:{line}: static credential forbidden in {field}; "
                "use Vault or an external variable",
                file=sys.stderr,
            )
            violations += 1
    if violations:
        print(f"Secret-literal guard failed: {violations} finding(s).", file=sys.stderr)
        return 1
    print("Secret-literal guard passed (tracked working tree only).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
