<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

if ($argc !== 2) {
    fwrite(STDERR, "Usage: verify_rendered_templates.php OUTPUT_DIR\n");
    exit(2);
}
$directory = $argv[1];
foreach (['docker', 'native'] as $backend) {
    foreach (['reconciler', 'warmup'] as $runner) {
        $path = $directory . '/' . $backend . '-' . $runner . '.sh';
        if (!is_file($path)) {
            throw new RuntimeException("Template was not rendered: $backend/$runner");
        }
        $contents = file_get_contents($path);
        if (!is_string($contents) || str_contains($contents, '{{')) {
            throw new RuntimeException("Unresolved or missing template: $backend/$runner");
        }
        if ($runner === 'reconciler') {
            if (!str_contains($contents, 'MAIL_PROVISION_SIGNATURE_ENABLED=1')
                || !str_contains($contents, 'Example Cooperative')) {
                throw new RuntimeException("Signature parameters missing: $backend");
            }
        }
    }
}
copy($directory . '/docker-warmup.sh', $directory . '/primary-warmup.sh');
echo "TEMPLATE_RENDER_PASS: two execution backends, signature enabled\n";
