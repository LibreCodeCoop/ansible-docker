<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace LibreCode\AnsibleDocker\Mail\Tests;

use RuntimeException;

/** Tests generated templates; no network, Mailcow or Nextcloud installation required. */
final class RenderedTemplateVerifier
{
    public function verify(string $directory): void
    {
        foreach (['docker', 'native'] as $backend) {
            foreach (['reconciler', 'warmup'] as $runner) {
                $this->check($directory, $backend, $runner);
            }
        }
        if (!copy($directory . '/docker-warmup.sh', $directory . '/primary-warmup.sh')) {
            throw new RuntimeException('Failed to prepare isolated runner fixture');
        }
    }

    private function check(string $directory, string $backend, string $runner): void
    {
        $path = $directory . '/' . $backend . '-' . $runner . '.sh';
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('A rendered test template is missing or unreadable');
        }
        if (str_contains($contents, '{{')) {
            throw new RuntimeException('Ansible left an unresolved Jinja expression');
        }

        // Verify actual runner behavior, not just that Jinja emitted valid shell.
        $required = $backend === 'native' ? 'runuser -u' : 'docker exec';
        $forbidden = $backend === 'native' ? 'docker exec' : 'runuser -u';
        if (!str_contains($contents, $required) || str_contains($contents, $forbidden)) {
            throw new RuntimeException('Unexpected runtime backend in rendered runner');
        }
        if ($runner === 'reconciler') {
            $this->checkSignature($contents);
        }
    }

    private function checkSignature(string $contents): void
    {
        if (
            !str_contains($contents, 'MAIL_PROVISION_SIGNATURE_ENABLED=1')
            || !str_contains($contents, 'Example Cooperative')
        ) {
            throw new RuntimeException('Synthetic signature parameters were not rendered');
        }
    }
}
