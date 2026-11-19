<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace LibreCode\AnsibleDocker\Tests\Integration;

use LibreCode\AnsibleDocker\Security\GitRepository;
use LibreCode\AnsibleDocker\Security\RepositoryCredentialScanner;
use LibreCode\AnsibleDocker\Security\SecretLiteralGuard;
use PHPUnit\Framework\TestCase;

final class SecretLiteralGitHistoryTest extends TestCase
{
    public function testIntermediateCommitLeakIsStillDetected(): void
    {
        $temp = sys_get_temp_dir() . '/secret-guard-' . bin2hex(random_bytes(8));
        $target = $temp . '/roles/nextcloud-talk/templates/server.conf.j2';
        mkdir(dirname($target), 0700, true);
        try {
            $this->git($temp, 'init -q -b main');
            $this->git($temp, 'config user.name "Synthetic Test"');
            $this->git($temp, 'config user.email "synthetic@example.invalid"');
            $this->git($temp, 'config commit.gpgsign false');
            file_put_contents($target, "hashkey = {{ talk_hashkey }}\n");
            $this->git($temp, 'add .');
            $this->git($temp, 'commit -qm baseline');
            $sha = trim($this->git($temp, 'rev-parse HEAD'));
            file_put_contents($target, "hashkey = SYNTHETIC-CREDENTIAL\n");
            $this->git($temp, 'add .');
            $this->git($temp, 'commit -qm intermediate');
            file_put_contents($target, "hashkey = {{ talk_hashkey }}\n");
            $this->git($temp, 'add .');
            $this->git($temp, 'commit -qm restored');
            $findings = (new RepositoryCredentialScanner(new SecretLiteralGuard(), new GitRepository($temp)))->scan($sha);
            self::assertCount(1, $findings);
            self::assertSame('hashkey', $findings[0]['field']);
            self::assertSame('roles/nextcloud-talk/templates/server.conf.j2', $findings[0]['path']);
            self::assertStringNotContainsString('SYNTHETIC-CREDENTIAL', json_encode($findings, JSON_THROW_ON_ERROR));
        } finally {
            $this->git($temp, 'status --porcelain', false);
            $this->removeTree($temp);
        }
    }

    public function testTrackedButDeletedLocalFileIsSkipped(): void
    {
        [$root, $file] = $this->createTrackedEnvironmentFixture();
        try {
            unlink($file);
            $findings = (new RepositoryCredentialScanner(new SecretLiteralGuard(), new GitRepository($root)))->scan();
            self::assertSame([], $findings);
        } finally {
            $this->removeTree($root);
        }
    }

    public function testTrackedFileReplacedWithBrokenSymlinkFailsClosed(): void
    {
        [$root, $file] = $this->createTrackedEnvironmentFixture();
        try {
            unlink($file);
            self::assertTrue(symlink('synthetic-missing-target', $file));
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Tracked security-sensitive file is a symbolic link');
            (new RepositoryCredentialScanner(new SecretLiteralGuard(), new GitRepository($root)))->scan();
        } finally {
            $this->removeTree($root);
        }
    }

    /** @return array{string,string} */
    private function createTrackedEnvironmentFixture(): array
    {
        $root = sys_get_temp_dir() . '/secret-worktree-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $this->git($root, 'init -q -b main');
        $this->git($root, 'config user.name "Synthetic Test"');
        $this->git($root, 'config user.email "synthetic@example.invalid"');
        $this->git($root, 'config commit.gpgsign false');
        $file = $root . '/.env';
        file_put_contents($file, 'DB_PWD={{ synthetic_password }}' . "\n");
        $this->git($root, 'add .env');
        $this->git($root, 'commit -qm baseline');
        return [$root, $file];
    }

    private function git(string $root, string $args, bool $check = true): string
    {
        $output = [];
        exec('git -C ' . escapeshellarg($root) . ' ' . $args . ' 2>/dev/null', $output, $status);
        if ($check && $status !== 0) {
            throw new \RuntimeException('Synthetic Git operation failed');
        }
        return implode("\n", $output);
    }

    private function removeTree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        foreach (new \FilesystemIterator($root) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                $this->removeTree($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($root);
    }
}
