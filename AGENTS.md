<!--
SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Development instructions for agents

## Scope and ownership

This is a **public library of reusable Ansible roles**, not an inventory or a deployment record.
Keep customer-specific secrets, domains, inventory and credentials out of this repository.
Check role consumers in the private infrastructure repository before changing shared behavior.
Do not SSH, apply Ansible remotely, deploy, or alter customer hosts as part of local PR work.

## Implementation

- Prefer YAML/Jinja2 for roles and playbooks. Use PHP 8.3+ for our own CLI helpers and tests; Python remains required for Ansible internals. Document other language exceptions based on concrete requirements, not dogma.
- Keep executable scripts thin. Put reusable validation and business rules in small, typed classes with unit tests; isolate subprocess and file-system adapters.
- Make tests check public contracts, security policies, rendered configurations and observed functionality. Do not copy a role's image version/digest into the test as a second source of truth.
- Use pinned test fixtures for external containers only where integration requires an actual image. Test-only fixtures are not production defaults.
- Preserve idempotence and avoid automatic deployment, mutable container images, credential logging or hidden fallbacks.

## Tests and quality gates

- PHP quality tools use separate workflows patterned on LibreCodeCoop/.github, not one aggregate QA job. Run PHPUnit, PHPStan, Psalm, PHPCS, PHPMD and REUSE independently.
- Composer development tools use isolated vendor-bin/<tool> dependencies and locks. Do not merge their dependency graphs into the root Composer application.
- Use PHPUnit data providers for independent input cases; test error paths and negative configuration cases. Keep cyclomatic complexity bounded and prevent exception handling from swallowing failures.
- Choose the minimum PHP version from the currently supported Nextcloud release. Apply the compatibility matrix to the Mailcow/Nextcloud runtime contract; do not repeat static analysis across PHP versions without evidence of benefit.
- The optional Talk Docker integration must validate actual audio and video via Janus, including reconnection; do not replace it with an HTTP-only or signaling-only test.
- The role self-tests may invoke Ansible's Jinja2 renderer; do not write a second, approximate Jinja implementation in PHP.

## Licenses, security and delivery

- Add SPDX copyright and license headers to every commentable new file. Use the sorted and indented REUSE.toml path annotations for JSON, lock files, license texts and other files that cannot host comments. Run reuse lint.
- Keep secrets out of commits, test fixtures and logs. The secret guard must detect literals in intermediate commits and fail closed when Git cannot be inspected.
- Work in isolated branches with GPG and DCO; open reviewable PRs. Report which tests actually ran. Green CI does not authorize merge or deployment.
