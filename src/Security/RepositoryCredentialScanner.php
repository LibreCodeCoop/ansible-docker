<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace LibreCode\AnsibleDocker\Security;

use RuntimeException;

final class RepositoryCredentialScanner
{
    public function __construct(
        private readonly SecretLiteralGuard $guard,
        private readonly GitRepository $repository,
    ) {
    }

    /**
     * @return list<array{path:string,line:int,field:string,commit?:string}>
     */
    public function scan(?string $baseSha = null): array
    {
        return [
            ...$this->scanTrackedFiles(),
            ...($baseSha === null || $baseSha === '' ? [] : $this->scanHistory($baseSha)),
        ];
    }

    /** @return list<array{path:string,line:int,field:string}> */
    private function scanTrackedFiles(): array
    {
        $findings = [];
        foreach ($this->repository->trackedFiles() as $name) {
            if (!$this->guard->isScannable($name)) {
                continue;
            }
            $path = $this->repository->root() . '/' . $name;
            if (!file_exists($path) && !is_link($path)) {
                // Deleted but not yet staged files are still returned by git ls-files.
                continue;
            }
            if (is_link($path)) {
                throw new RuntimeException('Tracked security-sensitive file is a symbolic link');
            }
            $contents = @file_get_contents($path);
            if (!is_string($contents)) {
                throw new RuntimeException('Unable to read tracked file');
            }
            foreach ($this->guard->scanFile($name, $contents) as $finding) {
                $findings[] = ['path' => $name, ...$finding];
            }
        }

        return $findings;
    }

    /** @return list<array{path:string,line:int,field:string,commit:string}> */
    private function scanHistory(string $baseSha): array
    {
        $findings = [];
        foreach ($this->repository->commitsSince($baseSha) as $sha) {
            foreach ($this->repository->changedFiles($sha) as $name) {
                if (!$this->guard->isScannable($name)) {
                    continue;
                }
                foreach ($this->guard->scanFile($name, $this->repository->readBlob($sha, $name)) as $finding) {
                    $findings[] = ['commit' => substr($sha, 0, 12), 'path' => $name, ...$finding];
                }
            }
        }

        return $findings;
    }
}
