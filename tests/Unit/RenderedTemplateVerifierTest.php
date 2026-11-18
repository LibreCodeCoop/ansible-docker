<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace LibreCode\AnsibleDocker\Tests\Unit;

use LibreCode\AnsibleDocker\Mail\Tests\RenderedTemplateVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RenderedTemplateVerifierTest extends TestCase
{
    /** @return iterable<string,array{string,string}> */
    public static function badTemplates(): iterable
    {
        yield 'unresolved Jinja' => ['docker-reconciler.sh', 'MAIL_PROVISION_SIGNATURE_ENABLED={{ broken }}'];
        yield 'missing enabled flag' => ['native-reconciler.sh', 'Example Cooperative'];
        yield 'missing signature organization' => ['docker-reconciler.sh', 'MAIL_PROVISION_SIGNATURE_ENABLED=1'];
        yield 'unresolved warmup' => ['native-warmup.sh', 'not rendered {{ broken }}'];
    }

    #[DataProvider('badTemplates')]
    public function testIncorrectTemplateIsRejected(string $file, string $contents): void
    {
        $temp = $this->fixtureDirectory();
        try {
            file_put_contents($temp . '/' . $file, $contents);
            $this->expectException(RuntimeException::class);
            (new RenderedTemplateVerifier())->verify($temp);
        } finally {
            $this->removeFixtureDirectory($temp);
        }
    }

    public function testValidTemplatesAreAcceptedAndWarmupIsAvailable(): void
    {
        $temp = $this->fixtureDirectory();
        try {
            (new RenderedTemplateVerifier())->verify($temp);
            self::assertFileExists($temp . '/primary-warmup.sh');
            self::assertSame('SYNTHETIC-WARMUP', file_get_contents($temp . '/primary-warmup.sh'));
        } finally {
            $this->removeFixtureDirectory($temp);
        }
    }

    private function fixtureDirectory(): string
    {
        $temp = sys_get_temp_dir() . '/mail-template-' . bin2hex(random_bytes(8));
        mkdir($temp, 0700);
        foreach (['docker', 'native'] as $backend) {
            file_put_contents(
                $temp . '/' . $backend . '-reconciler.sh',
                "MAIL_PROVISION_SIGNATURE_ENABLED=1\nExample Cooperative"
            );
            file_put_contents($temp . '/' . $backend . '-warmup.sh', 'SYNTHETIC-WARMUP');
        }
        return $temp;
    }

    private function removeFixtureDirectory(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}
