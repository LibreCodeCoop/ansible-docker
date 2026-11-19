#!/usr/bin/env php
<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use LibreCode\AnsibleDocker\Security\CredentialGuardReport;
use LibreCode\AnsibleDocker\Security\GitRepository;
use LibreCode\AnsibleDocker\Security\RepositoryCredentialScanner;
use LibreCode\AnsibleDocker\Security\SecretLiteralGuard;

$scanner = new RepositoryCredentialScanner(
    new SecretLiteralGuard(),
    new GitRepository(dirname(__DIR__)),
);

try {
    $findings = $scanner->scan(getenv('SECRET_GUARD_BASE_SHA') ?: null);
} catch (Throwable) {
    // Fail closed while suppressing Git output, file contents and exception details.
    fwrite(STDERR, "Credential inspection could not finish; failing closed.\n");
    exit(2);
}

$report = new CredentialGuardReport();
foreach ($findings as $finding) {
    fwrite(STDERR, $report->formatFinding($finding) . "\n");
}
if ($findings !== []) {
    fwrite(STDERR, "Secret-literal guard failed: " . count($findings) . " finding(s).\n");
    exit(1);
}
echo "Secret-literal guard passed.\n";
