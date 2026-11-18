<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace LibreCode\AnsibleDocker\Security;

/**
 * Narrow, fail-closed check for known application credential assignments.
 * Results contain file/line/field only, never sensitive values.
 */
final class SecretLiteralGuard
{
    /** @return list<array{line:int,field:string}> */
    public function scanFile(string $path, string $content): array
    {
        $path = str_replace('\\', '/', $path);
        $talk = str_starts_with($path, 'roles/nextcloud-talk/');
        $filename = basename($path);
        $env = in_array($filename, ['.env', 'env.j2', '.env.j2'], true);
        $findings = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $index => $line) {
            if (str_starts_with(ltrim($line), '#')) {
                continue;
            }
            $found = null;
            $allowEmpty = false;
            if (preg_match('/^\s*(pgadmin_password)\s*[:=]\s*(.*)$/i', $line, $matches) === 1) {
                $found = $matches;
            } elseif ($talk && preg_match('/^\s*(hashkey|blockkey|internalsecret|apikey)\s*=\s*(.*)$/i', $line, $matches) === 1) {
                $found = $matches;
            } elseif ($env && preg_match('/^\s*(DB_PWD|JWT_SECRET|POSTGRES_PASSWORD|MYSQL_ROOT_PASSWORD|PGADMIN_DEFAULT_PASSWORD)\s*=\s*(.*)$/i', $line, $matches) === 1) {
                $found = $matches;
                $allowEmpty = true;
            }
            if ($found !== null && !$this->isExternalReference($found[2], $allowEmpty)) {
                $findings[] = ['line' => $index + 1, 'field' => $found[1]];
            }
        }
        return $findings;
    }

    public function isScannable(string $name): bool
    {
        return basename($name) === '.env'
            || in_array(pathinfo($name, PATHINFO_EXTENSION), ['j2', 'yml', 'yaml', 'conf', 'ini', 'env'], true);
    }

    public function isExternalReference(string $value, bool $allowEmpty = false): bool
    {
        $value = trim($value);
        if ($allowEmpty && in_array($value, ['', "''", '""'], true)) {
            return true;
        }
        return preg_match('/^([\'"]?)\{\{\s*[A-Za-z_][A-Za-z0-9_.]*\s*\}\}\1(?:\s+#.*)?$/D', $value) === 1
            || preg_match('/^\$\{[A-Za-z_][A-Za-z0-9_]*\}$/D', $value) === 1
            || preg_match('/^!vault(?:\s+\|)?(?:\s+#.*)?$/Di', $value) === 1;
    }

    /**
     * @return list<array{commit:string,path:string,line:int,field:string}>
     */
    public function scanAddedCommitBlobs(string $root, string $baseSha): array
    {
        if (preg_match('/^[a-fA-F0-9]{40}$/D', $baseSha) !== 1) {
            throw new \InvalidArgumentException('Invalid baseline SHA');
        }
        $this->git($root, ['merge-base', '--is-ancestor', $baseSha, 'HEAD']);
        $commits = array_filter(explode("\n", trim($this->git($root, ['rev-list', $baseSha . '..HEAD']))));
        $findings = [];
        foreach ($commits as $sha) {
            $raw = $this->git($root, ['diff-tree', '--root', '-m', '-r', '--no-commit-id', '--name-only', '--diff-filter=ACMRT', '-z', $sha]);
            $names = array_unique(array_filter(explode("\0", $raw)));
            sort($names);
            foreach ($names as $name) {
                if (!$this->isScannable($name)) {
                    continue;
                }
                $contents = $this->git($root, ['show', $sha . ':' . $name]);
                foreach ($this->scanFile($name, $contents) as $finding) {
                    $findings[] = [
                        'commit' => substr($sha, 0, 12),
                        'path' => $name,
                        'line' => $finding['line'],
                        'field' => $finding['field'],
                    ];
                }
            }
        }
        return $findings;
    }

    /** @param list<string> $args */
    private function git(string $root, array $args): string
    {
        $command = 'git -C ' . escapeshellarg($root);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to invoke Git');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0 || $output === false) {
            throw new \RuntimeException('Git inspection failed');
        }
        return $output;
    }
}
