#!/usr/bin/env php
<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/RenderedTemplateVerifier.php';

use LibreCode\AnsibleDocker\Mail\Tests\RenderedTemplateVerifier;

if ($argc !== 2) {
    fwrite(STDERR, "Usage: verify_rendered_templates.php OUTPUT_DIR\n");
    exit(2);
}

(new RenderedTemplateVerifier())->verify($argv[1]);
echo "TEMPLATE_RENDER_PASS: two execution backends, signature enabled\n";
