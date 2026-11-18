<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace LibreCode\AnsibleDocker\Security;

/**
 * A deliberately narrow rule set, designed not to include sensitive values in findings.
 * Broader secret scanners belong alongside this guard, not inside it.
 */
final class SecretLiteralGuard
{
    private const TALK_ASSIGNMENT = '/^\s*(hashkey|blockkey|internalsecret|apikey)\s*=\s*(.*)$/i';
    private const DATABASE_ASSIGNMENT = '/^\s*(pgadmin_password)\s*[:=]\s*(.*)$/i';
    private const ENV_ASSIGNMENT = '/^\s*(DB_PWD|JWT_SECRET|POSTGRES_PASSWORD|MYSQL_ROOT_PASSWORD|PGADMIN_DEFAULT_PASSWORD)\s*=\s*(.*)$/i';

    /** @return list<array{line:int,field:string}> */
    public function scanFile(string $path, string $content): array
    {
        $findings = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $index => $line) {
            $assignment = $this->assignment($path, $line);
            if ($assignment === null) {
                continue;
            }

            [$field, $value, $allowEmpty] = $assignment;
            if (!$this->isExternalReference($value, $allowEmpty)) {
                $findings[] = ['line' => $index + 1, 'field' => $field];
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
     * @return array{string,string,bool}|null
     */
    private function assignment(string $path, string $line): ?array
    {
        if (str_starts_with(ltrim($line), '#')) {
            return null;
        }

        if (preg_match(self::DATABASE_ASSIGNMENT, $line, $parts) === 1) {
            return [$parts[1], $parts[2], false];
        }

        if (
            str_starts_with(str_replace('\\', '/', $path), 'roles/nextcloud-talk/')
            && preg_match(self::TALK_ASSIGNMENT, $line, $parts) === 1
        ) {
            return [$parts[1], $parts[2], false];
        }

        $isEnvironment = in_array(basename($path), ['.env', 'env.j2', '.env.j2'], true);
        if ($isEnvironment && preg_match(self::ENV_ASSIGNMENT, $line, $parts) === 1) {
            return [$parts[1], $parts[2], true];
        }

        return null;
    }
}
