<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace LibreCode\AnsibleDocker\Security;

use RuntimeException;

final class GitRepository
{
    public function __construct(private readonly string $root)
    {
    }

    public function root(): string
    {
        return $this->root;
    }

    /** @return list<string> */
    public function trackedFiles(): array
    {
        return $this->nullSeparated(['ls-files', '-z']);
    }

    /** @return list<string> */
    public function commitsSince(string $baseSha): array
    {
        if (preg_match('/^[a-f0-9]{40}$/Di', $baseSha) !== 1) {
            throw new \InvalidArgumentException('The baseline must be a full Git SHA-1');
        }
        $this->command(['merge-base', '--is-ancestor', $baseSha, 'HEAD']);

        return array_values(array_filter(explode("\n", trim($this->command(['rev-list', $baseSha . '..HEAD'])))));
    }

    /** @return list<string> */
    public function changedFiles(string $commit): array
    {
        $files = $this->nullSeparated([
            'diff-tree', '--root', '-m', '-r', '--no-commit-id', '--name-only', '--diff-filter=ACMRT', '-z', $commit,
        ]);
        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    public function readBlob(string $commit, string $path): string
    {
        return $this->command(['show', $commit . ':' . $path]);
    }

    /**
     * @param list<string> $args
     * @return list<string>
     */
    private function nullSeparated(array $args): array
    {
        return array_values(array_filter(explode("\0", $this->command($args)), static fn ($path) => $path !== ''));
    }

    /** @param list<string> $args */
    private function command(array $args): string
    {
        $cmd = 'git -C ' . escapeshellarg($this->root);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        // Git output can contain credential literals. Never include it in exceptions.
        $pipes = [];
        $process = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to execute Git');
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);

        if ($status !== 0 || $output === false) {
            throw new RuntimeException('Git repository inspection failed');
        }

        return $output;
    }
}
