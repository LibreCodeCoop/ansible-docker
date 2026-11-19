#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# Bounded retries for transient remote Galaxy errors; never silence final failure.
set -euo pipefail

if [ "$#" -ne 2 ] || [[ ! "$1" =~ ^[a-zA-Z0-9_.]+:[0-9]+\.[0-9]+\.[0-9]+$ ]] || [ -z "$2" ]; then
    echo 'Usage: install-ansible-collection.sh namespace.name:version destination' >&2
    exit 2
fi

collection="$1"
destination="$2"
for attempt in 1 2 3; do
    if ansible-galaxy collection install "$collection" -p "$destination"; then
        exit 0
    fi
    if [ "$attempt" -eq 3 ]; then
        echo 'Ansible Galaxy installation failed after three attempts' >&2
        exit 1
    fi
    sleep "${CI_COLLECTION_RETRY_DELAY:-3}"
done
