<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace LibreCode\AnsibleDocker\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class GenericMailConfigurationTest extends TestCase
{
    public function testReusableRoleDoesNotEmbedCustomerSpecificRuntimeValues(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [
            'files/nextcloud-mailcow-reconcile.php',
            'files/nextcloud-mailcow-fields.php',
            'files/nextcloud-mailcow-baseline.php',
            'templates/mail-provision-run.sh.j2',
        ];
        foreach ($files as $file) {
            $path = $root . '/roles/nextcloud-mailcow-provision/' . $file;
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            self::assertNotFalse($lines, $path);
            foreach ($lines as $index => $line) {
                if (str_contains($line, 'SPDX-FileCopyrightText:')) {
                    continue;
                }
                $context = basename($file) . ':' . ($index + 1);
                self::assertDoesNotMatchRegularExpression(
                    '/\b(?:librecode\.coop|cloud\.librecode\.coop|mail\.librecode\.coop|portabilis\.libresign\.coop|ada\.librecode\.coop|keller\.librecode\.coop)\b/i',
                    $line,
                    $context
                );
                self::assertDoesNotMatchRegularExpression(
                    '/[\'"](?:LibreCode|Cooperados)[\'"]/i',
                    $line,
                    $context
                );
            }
        }
    }
}
