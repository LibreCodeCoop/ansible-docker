<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace LibreCode\AnsibleDocker\Tests\Unit;

use LibreCode\AnsibleDocker\Tests\Talk\Media\MediaCollector;
use LibreCode\AnsibleDocker\Tests\Talk\Media\SyntheticMediaTrack;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webrtc\Codecs\EncodedPacket;

final class MediaCollectorTest extends TestCase
{
    /** @return iterable<string, array{string,string}> */
    public static function validMedia(): iterable
    {
        yield 'audio' => ['audio', "\xf8\xff\xfe"];
        yield 'video' => ['video', base64_decode(SyntheticMediaTrack::VP8_KEYFRAME_BASE64, true)];
    }

    #[DataProvider('validMedia')]
    public function testPayloadMismatchAfterFiveFramesFailsWithoutCountingBadPackets(string $kind, string $valid): void
    {
        $collector = new MediaCollector();
        for ($index = 0; $index < 6; ++$index) {
            $collector->recordEncodedFrame($kind, new EncodedPacket($valid, $index));
        }
        self::assertSame(6, $collector->counts[$kind]);
        $collector->recordEncodedFrame($kind, new EncodedPacket('deliberately-invalid-fixture', 7));
        self::assertSame('Received media payload differs from the synthetic fixture', $collector->failure);
        self::assertSame(6, $collector->counts[$kind]);

        $collector->recordEncodedFrame($kind, new EncodedPacket($valid, 8));
        self::assertSame(6, $collector->counts[$kind]);
    }

    #[DataProvider('validMedia')]
    public function testValidEncodedPacketsCompleteFiniteSample(string $kind, string $valid): void
    {
        $collector = new MediaCollector();
        for ($index = 0; $index < MediaCollector::SAMPLE_FRAMES; ++$index) {
            $collector->recordEncodedFrame($kind, new EncodedPacket($valid, $index));
        }
        self::assertSame(MediaCollector::SAMPLE_FRAMES, $collector->counts[$kind]);
        self::assertNull($collector->failure);
    }
}
