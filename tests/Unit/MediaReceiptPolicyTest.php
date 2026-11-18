<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace LibreCode\AnsibleDocker\Tests\Unit;

use LibreCode\AnsibleDocker\Tests\Talk\Media\MediaReceiptPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MediaReceiptPolicyTest extends TestCase
{
    /** @return iterable<string,array{array{audio:int,video:int},bool}> */
    public static function counts(): iterable
    {
        yield 'no RTP packets' => [['audio' => 0, 'video' => 0], false];
        yield 'audio only' => [['audio' => 8, 'video' => 0], false];
        yield 'video only' => [['audio' => 0, 'video' => 12], false];
        yield 'insufficient video' => [['audio' => 8, 'video' => 4], false];
        yield 'both at threshold' => [['audio' => 5, 'video' => 5], true];
        yield 'both above threshold' => [['audio' => 8, 'video' => 12], true];
    }

    #[DataProvider('counts')]
    public function testMinimumAudioAndVideoReceipt(array $counts, bool $expected): void
    {
        self::assertSame($expected, (new MediaReceiptPolicy())->hasMinimum($counts));
    }
}
