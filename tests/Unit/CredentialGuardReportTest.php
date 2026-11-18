<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace LibreCode\AnsibleDocker\Tests\Unit;

use LibreCode\AnsibleDocker\Security\CredentialGuardReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CredentialGuardReportTest extends TestCase
{
    /** @return iterable<string,array{array{path:string,line:int,field:string,commit?:string},string}> */
    public static function findings(): iterable
    {
        yield 'working file' => [
            ['path' => 'roles/nextcloud-talk/secrets.ini', 'line' => 3, 'field' => 'hashkey'],
            'roles/nextcloud-talk/secrets.ini:3: static credential forbidden in hashkey; use Vault or an external variable',
        ];
        yield 'historical blob' => [
            ['commit' => 'abc123456789', 'path' => 'roles/nextcloud-talk/secrets.ini', 'line' => 2, 'field' => 'apikey'],
            'abc123456789 roles/nextcloud-talk/secrets.ini:2: static credential forbidden in apikey; use Vault or an external variable',
        ];
    }

    #[DataProvider('findings')]
    public function testSensitiveValuesAreNeverFormatted(array $finding, string $expected): void
    {
        self::assertSame($expected, (new CredentialGuardReport())->formatFinding($finding));
    }
}
