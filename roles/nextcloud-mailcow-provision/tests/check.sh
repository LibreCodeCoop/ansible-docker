#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# Public role self-tests: no Nextcloud, no Mailcow, no network inside test containers.
set -euo pipefail
role="$(cd -- "$(dirname -- "$0")/.." && pwd)"
tmp="$(mktemp -d -t nc-mailcow-role.XXXXXXXX)"
trap 'rm -rf -- "$tmp"' EXIT
for tool in docker python3 bash; do command -v "$tool" >/dev/null || exit 2; done

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
python3 "$role/tests/test_mail_generic_configuration.py"

python3 - "$role" "$tmp" <<'PY'
import sys,shlex,yaml
from pathlib import Path
from jinja2 import Environment,StrictUndefined
role,dest=map(Path,sys.argv[1:])
fixture=yaml.safe_load((role/'tests/fixtures/mail-provision-instance.yml').read_text())
env=Environment(undefined=StrictUndefined)
env.filters['quote']=shlex.quote
for runtime in ('docker','native'):
 data=dict(fixture)
 data['mail_provision_runtime']=runtime
 data['mail_provision_signature_enabled']=True
 data['mail_provision_signature_template']='{name}\n{role} | {organization}\n{email}'
 data['mail_provision_signature_default_role']='Member'
 data['mail_provision_signature_organization']='Example Cooperative'
 data['mail_provision_signature_website']='https://example.test'
 for source,out in [('mail-provision-run.sh.j2','reconciler'),('mail-warmup-run.sh.j2','warmup')]:
  rendered=env.from_string((role/'templates'/source).read_text()).render(**data)
  if '{{' in rendered:
   raise SystemExit('Unrendered Jinja expression')
  (dest/f'{runtime}-{out}.sh').write_text(rendered)
  if runtime=='docker' and out=='warmup':
   (dest/'primary-warmup.sh').write_text(rendered)
  if out=='reconciler':
   assert 'MAIL_PROVISION_SIGNATURE_ENABLED=1' in rendered
   assert 'Example Cooperative' in rendered
print('TEMPLATE_RENDER_PASS: two execution backends, signature enabled')
PY
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
