<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace LibreCode\AnsibleDocker\Tests\Unit;

use LibreCode\AnsibleDocker\Security\SecretLiteralGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretLiteralGuardTest extends TestCase
{
    /** @return iterable<string, array{string,string,list<array{line:int,field:string}>}> */
    public static function checks(): iterable
    {
        $talk = 'roles/nextcloud-talk/templates/server.conf.j2';
        $env = 'roles/onlyoffice/files/.env';
        yield 'talk literal' => [$talk, 'hashkey = synthetic-value', [['line' => 1, 'field' => 'hashkey']]];
        yield 'talk variable' => [$talk, 'hashkey = {{ talk_hashkey }}', []];
        yield 'talk quoted Jinja' => [$talk, 'hashkey = "{{ talk_hashkey }}" # comment', []];
        yield 'reject injected Jinja fallback' => [$talk, "hashkey = {{ var | default('unsafe') }}", [['line' => 1, 'field' => 'hashkey']]];
        yield 'reject Jinja literal' => [$talk, "hashkey = {{ 'unsafe' }}", [['line' => 1, 'field' => 'hashkey']]];
        yield 'ignore comments' => [$talk, '# hashkey = placeholder', []];
        yield 'exclude unrelated key names' => [$talk, 'pkey=/tmp/synthetic.pem', []];
        yield 'reject yaml block literal' => ['roles/pgadmin/defaults/main.yml', "pgadmin_password: |\n  secret", [['line' => 1, 'field' => 'pgadmin_password']]];
        yield 'allow vault tag' => ['roles/pgadmin/defaults/main.yml', "pgadmin_password: !vault |\n  synthetic", []];
        yield 'empty env placeholder' => [$env, "DB_PWD=\nJWT_SECRET=", []];
        yield 'reject env literal' => [$env, 'DB_PWD=synthetic-value', [['line' => 1, 'field' => 'DB_PWD']]];
        yield 'allow external env' => [$env, 'JWT_SECRET=${JWT_SECRET}', []];
        yield 'template env literal' => ['roles/postgres/templates/env.j2', 'PGADMIN_DEFAULT_PASSWORD=synthetic', [['line' => 1, 'field' => 'PGADMIN_DEFAULT_PASSWORD']]];
    }

    #[DataProvider('checks')]
    public function testCredentialPatterns(string $path, string $content, array $expected): void
    {
        self::assertSame($expected, (new SecretLiteralGuard())->scanFile($path, $content));
    }

    public function testFindingsNeverIncludeSecretValues(): void
    {
        $result = (new SecretLiteralGuard())->scanFile('roles/nextcloud-talk/x.conf', "apikey = SECRET-EXAMPLE\n");
        self::assertSame([['line' => 1, 'field' => 'apikey']], $result);
        self::assertStringNotContainsString('SECRET-EXAMPLE', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testInvalidBaselineFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SecretLiteralGuard())->scanAddedCommitBlobs('.', 'invalid');
    }
}
