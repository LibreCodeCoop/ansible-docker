#!/usr/bin/env php
<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Security/SecretLiteralGuard.php';
use LibreCode\AnsibleDocker\Security\SecretLiteralGuard;

$root = dirname(__DIR__);
$guard = new SecretLiteralGuard();
try {
    $pipes = [];
    $process = proc_open('git -C ' . escapeshellarg($root) . ' ls-files -z', [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot invoke git');
    }
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0 || $raw === false) {
        throw new RuntimeException('Cannot inspect tracked files');
    }
    $count = 0;
    foreach (array_filter(explode("\0", $raw)) as $name) {
        $path = $root . '/' . $name;
        if (!$guard->isScannable($name) || !is_file($path) || is_link($path)) {
            continue;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read tracked file');
        }
        foreach ($guard->scanFile($name, $contents) as $found) {
            fwrite(STDERR, "$name:{$found['line']}: static credential forbidden in {$found['field']}; use Vault or an external variable\n");
            ++$count;
        }
    }
    $base = getenv('SECRET_GUARD_BASE_SHA');
    if (is_string($base) && $base !== '') {
        foreach ($guard->scanAddedCommitBlobs($root, $base) as $found) {
            fwrite(STDERR, "{$found['commit']} {$found['path']}:{$found['line']}: static credential forbidden in {$found['field']}; use Vault or an external variable\n");
            ++$count;
        }
    }
    if ($count > 0) {
        fwrite(STDERR, "Secret-literal guard failed: $count finding(s).\n");
        exit(1);
    }
    echo "Secret-literal guard passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot inspect repository or new commit history; failing closed.\n");
    exit(2);
}
