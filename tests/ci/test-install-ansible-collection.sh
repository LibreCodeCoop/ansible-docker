#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
tmp="$(mktemp -d -t ansible-galaxy-test.XXXXXXXX)"
trap 'rm -rf -- "$tmp"' EXIT
mkdir -p "$tmp/bin" "$tmp/collections"
cat > "$tmp/bin/ansible-galaxy" <<'STUB'
#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail
n=0
test ! -f "$CI_GALAXY_ATTEMPT_FILE" || n="$(cat "$CI_GALAXY_ATTEMPT_FILE")"
n="$((n+1))"
printf '%s\n' "$n" > "$CI_GALAXY_ATTEMPT_FILE"
if [ "$n" -lt "$CI_GALAXY_SUCCEED_AT" ]; then exit 42; fi
exit 0
STUB
chmod 755 "$tmp/bin/ansible-galaxy"
export CI_GALAXY_ATTEMPT_FILE="$tmp/count"
export CI_COLLECTION_RETRY_DELAY=0
export PATH="$tmp/bin:$PATH"
CI_GALAXY_SUCCEED_AT=3 bash "$root/ci/install-ansible-collection.sh" community.docker:5.0.5 "$tmp/collections"
test "$(cat "$CI_GALAXY_ATTEMPT_FILE")" = 3
rm "$CI_GALAXY_ATTEMPT_FILE"
if CI_GALAXY_SUCCEED_AT=4 bash "$root/ci/install-ansible-collection.sh" community.docker:5.0.5 "$tmp/collections" >/dev/null 2>&1; then
    echo 'Exhausted retries incorrectly returned success' >&2
    exit 1
fi
test "$(cat "$CI_GALAXY_ATTEMPT_FILE")" = 3
rm "$CI_GALAXY_ATTEMPT_FILE"
if CI_GALAXY_SUCCEED_AT=1 bash "$root/ci/install-ansible-collection.sh" invalid "$tmp/collections" >/dev/null 2>&1; then
    echo 'Unpinned collection incorrectly accepted' >&2
    exit 1
fi
test ! -e "$CI_GALAXY_ATTEMPT_FILE"
echo 'GALAXY_RETRY_CONTRACT_PASS: transient, permanent failure, version pin'
