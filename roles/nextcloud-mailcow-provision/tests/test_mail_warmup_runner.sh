#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# Execute inside a disposable PHP image as root; mock all Docker/IMAP commands.
set -euo pipefail

mkdir -p /usr/local/libexec/mail-provision /etc/mail-provision/warmup-state /tmp/mail-mock-bin
chmod 0700 /etc/mail-provision /etc/mail-provision/warmup-state
cp /fixtures/primary-warmup.sh /usr/local/libexec/mail-provision/mail-warmup-run
cp /repo/files/nextcloud-mail-warmup.php /usr/local/libexec/mail-provision/nextcloud-mail-warmup.php
chmod 0750 /usr/local/libexec/mail-provision/mail-warmup-run
cat > /tmp/mail-mock-bin/docker <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
case " $* " in
  *" mail:account:sync "*)
    echo sync >> /tmp/mail-warmup-events
    if [ -f /tmp/mail-warmup-fail-sync ]; then exit 20; fi
    exit 0
    ;;
  *" background-job:execute "*)
    echo preview >> /tmp/mail-warmup-events
    touch /tmp/mail-warmup-previews-ready
    exit 0
    ;;
esac
case "$MAIL_WARMUP_MODE" in
  scan|inspect)
    if [ -f /tmp/mail-warmup-invalid-output ]; then printf 'not-a-record\n';exit 0;fi
    fp=$(printf '%064d' 0)
    if [ -f /tmp/mail-warmup-busy ]; then
      printf '42|%s|1|0|0|55|56|1|1|0|1|0\n' "$fp"
      exit 0
    fi
    if [ -f /tmp/mail-warmup-previews-ready ]; then
      printf '42|%s|0|0|0|55|56|1|1|0|1|1\n' "$fp"
    else
      printf '42|%s|0|0|0|55|56|1|1|2|1|0\n' "$fp"
    fi
    ;;
  ensure-jobs)
    echo ensure_jobs >> /tmp/mail-warmup-events
    ;;
  privacy)
    echo privacy >> /tmp/mail-warmup-events
    ;;
  *)
    exit 22
    ;;
esac
MOCK
chmod 0755 /tmp/mail-mock-bin/docker
export PATH=/tmp/mail-mock-bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
runner=/usr/local/libexec/mail-provision/mail-warmup-run
state=/etc/mail-provision/warmup-state

# No work is executed by --status, and the pending task is visible.
out=$("$runner" --status)
[[ "$out" == *"eligible=1 complete=0 pending=1"* ]] || { echo "bad initial status: $out";exit 1; }
test ! -f /tmp/mail-warmup-events

# One serial execution does IMAP sync, previews and privacy, then stamps readiness.
out=$("$runner" --run)
[[ "$out" == "status=complete" ]] || { echo "unexpected success output: $out";exit 1; }
test "$(grep -c '^sync$' /tmp/mail-warmup-events)" = 1
test "$(grep -c '^preview$' /tmp/mail-warmup-events)" = 1
test "$(grep -c '^privacy$' /tmp/mail-warmup-events)" = 1
test "$(find "$state" -maxdepth 1 -type f -name 'done-*' | wc -l)" = 1
done_path=$(find "$state" -maxdepth 1 -type f -name 'done-*' | head -n 1)
test "$(stat -Lc '%u:%a' "$done_path")" = 0:600

# Idempotent: ready account is never synced a second time.
"$runner" --run
test "$(grep -c '^sync$' /tmp/mail-warmup-events)" = 1
out=$("$runner" --status)
[[ "$out" == *"eligible=1 complete=1 pending=0"* ]] || { echo "wrong completed status: $out";exit 1; }

# Fail then retry later; do not stamp a false success.
rm -f "$done_path" /tmp/mail-warmup-previews-ready
touch /tmp/mail-warmup-fail-sync
if "$runner" --run; then echo 'expected sync failure';exit 1;fi
test "$(find "$state" -maxdepth 1 -type f -name 'done-*' | wc -l)" = 0
test "$(find "$state" -maxdepth 1 -type f -name 'retry-*' | wc -l)" = 1
out=$("$runner" --status)
[[ "$out" == *"deferred=1"* ]] || { echo "missing backoff status: $out";exit 1; }

# Let backoff expire and verify that the account eventually completes.
rm -f /tmp/mail-warmup-fail-sync
retry_path=$(find "$state" -maxdepth 1 -type f -name 'retry-*' | head -n 1)
printf '1 0\n' > "$retry_path"
"$runner" --run | grep -q 'status=complete'
test ! -e "$retry_path"

# A real lock never triggers --force or a cache deletion.
rm -f "$done_path" /tmp/mail-warmup-previews-ready
touch /tmp/mail-warmup-busy
before=$(wc -l < /tmp/mail-warmup-events)
"$runner" --run
test "$(wc -l < /tmp/mail-warmup-events)" = "$before"
rm -f /tmp/mail-warmup-busy

# Unexpected PHP output always fails closed.
touch /tmp/mail-warmup-invalid-output
if "$runner" --status > /dev/null 2>&1;then echo 'accepted malformed inspector output';exit 1;fi

echo "MAIL_WARMUP_RUNNER_E2E_PASS: serial, preview, privacy, idempotence, retry, locks, fail-closed"
