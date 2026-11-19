<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace LibreCode\AnsibleDocker\Tests\Unit;

use LibreCode\AnsibleDocker\Tests\Talk\TalkTestSupport;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TalkTestSupportWaitForTest extends TestCase
{
    public function testSatisfiedConditionReturnsImmediately(): void
    {
        $calls = 0;
        TalkTestSupport::waitFor(static function () use (&$calls): bool {
            ++$calls;
            return true;
        }, 1);
        self::assertSame(1, $calls);
    }

    public function testTimeoutReportsServiceContextWithoutIncludingRawLogs(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Janus startup timed out (timeout 0s)');
        TalkTestSupport::waitFor(static fn (): bool => false, 0, 'Janus startup timed out');
    }
}
