#!/usr/bin/env python3
"""Refuse organization/customer-specific values in reusable Mail provisioning sources.

Instance settings may live only in inventory/host_vars, not in the generic PHP
or shell template. SPDX copyright lines are metadata, not runtime configuration.
"""
from pathlib import Path
import re

root = Path(__file__).resolve().parents[1]
sources = [
    root / "files/nextcloud-mailcow-reconcile.php",
    root / "files/nextcloud-mailcow-fields.php",
    root / "files/nextcloud-mailcow-baseline.php",
    root / "templates/mail-provision-run.sh.j2",
]
for source in sources:
    lines = source.read_text(encoding="utf-8").splitlines()
    for line_number, line in enumerate(lines, start=1):
        if "SPDX-FileCopyrightText:" in line:
            continue
        if re.search(r"\b(?:librecode\.coop|cloud\.librecode\.coop|mail\.librecode\.coop|portabilis\.libresign\.coop|ada\.librecode\.coop|keller\.librecode\.coop)\b", line, re.I):
            raise AssertionError(f"Tenant-specific domain in reusable source: {source.name}:{line_number}")
        if re.search(r"['\"](?:LibreCode|Cooperados)['\"]", line, re.I):
            raise AssertionError(f"Tenant-specific label in reusable source: {source.name}:{line_number}")
print("SIGNATURE_TENANT_ISOLATION_PASS: reusable sources contain no organization domains or labels")
