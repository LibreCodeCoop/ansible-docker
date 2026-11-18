<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace LibreCode\AnsibleDocker\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class MailProvisionPolicyTest extends TestCase
{
    /** @return iterable<string,array{string}> */
    public static function policySuites(): iterable
    {
        yield 'mailbox reconciliation' => ['test_nextcloud_mailcow_reconcile.php'];
        yield 'provisioning executor' => ['test_mail_provision_executor.php'];
        yield 'Mailcow API contract' => ['test_mailcow_api_contract.php'];
        yield 'signature and customization policy' => ['test_mail_signature.php'];
        yield 'signature Profile Fields policy' => ['test_mail_signature_fields.php'];
        yield 'initial mail warmup policy' => ['test_mail_warmup_policy.php'];
    }

    #[DataProvider('policySuites')]
    public function testExistingPolicyContracts(string $script): void
    {
        $path = dirname(__DIR__, 2) . '/roles/nextcloud-mailcow-provision/tests/' . $script;
        $process = new Process([PHP_BINARY, $path]);
        $process->setTimeout(45);
        $process->run();
        self::assertTrue(
            $process->isSuccessful(),
            'Offline mail policy contract failed: ' . $script . ' (exit ' . $process->getExitCode() . ')'
        );
    }
}
