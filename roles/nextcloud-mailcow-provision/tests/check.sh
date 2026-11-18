#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# Public role self-tests: no Nextcloud, no Mailcow, no network inside test containers.
set -euo pipefail
role="$(cd -- "$(dirname -- "$0")/.." && pwd)"
tmp="$(mktemp -d -t nc-mailcow-role.XXXXXXXX)"
trap 'rm -rf -- "$tmp"' EXIT
for tool in docker ansible-playbook bash; do command -v "$tool" >/dev/null || exit 2; done

for version in 8.4 8.5; do
  echo "PHP $version: offline provisioning, signature and warmup"
  docker run --rm --network none -v "$role:/repo:ro" -w /repo "php:$version-cli" sh -ec '
    for src in files/*.php tests/*.php; do php -l "$src" >/dev/null; done
    php tests/test_nextcloud_mailcow_reconcile.php
    php tests/test_mail_provision_executor.php
    php tests/test_mailcow_api_contract.php
    php tests/test_mail_signature.php
    php tests/test_mail_signature_fields.php
    php tests/test_mail_warmup_policy.php
  '
done
# Use Ansible's own Jinja2 renderer; the orchestration and assertions are PHP.
ansible-playbook -i localhost, -c local \
  "$role/tests/render-templates.yml" \
  --extra-vars "{\"mail_test_output_dir\": \"$tmp\"}"
docker run --rm --network none -v "$tmp:/fixtures" -v "$role:/repo:ro" \
  php:8.4-cli php /repo/tests/verify_rendered_templates.php /fixtures
for file in "$tmp"/*.sh; do bash -n "$file"; done
docker run --rm --network none -v "$tmp:/fixtures:ro" -v "$role:/repo:ro" \
  php:8.4-cli bash /repo/tests/test_mail_warmup_runner.sh

# The consuming playbook references the role by its directory name.
if command -v ansible-playbook >/dev/null; then
  ANSIBLE_ROLES_PATH="$(dirname "$role")" \
    ansible-playbook -i localhost, -c local \
    "$role/tests/syntax.yml" --syntax-check
fi

echo "PUBLIC_MAILCOW_ROLE_TESTS_PASS"
