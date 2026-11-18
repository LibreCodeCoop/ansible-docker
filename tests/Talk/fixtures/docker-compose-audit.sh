#!/bin/sh
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

# Intentionally intercept only Compose "up" in an isolated unit test.
for arg in "$@"; do
    if [ "$arg" = up ]; then
        printf '%s\n' "$*" >> "$TALK_COMPOSE_AUDIT_LOG"
        printf 'Container test Recreated\n' >&2
        exit 0
    fi
done
exec __DOCKER__ "$@"
