<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace LibreCode\AnsibleDocker\Tests\Talk\Media;

use RuntimeException;
use Throwable;
use Webrtc\Codecs\EncodedPacket;
use Webrtc\RTP\MediaStreamTrack\MediaStreamTrack;
use Webrtc\RTP\MediaStreamTrack\RemoteStreamTrack;
use Webrtc\Webrtc\Listener\PeerConnectionTrackListener;

use function Amp\async;

final class MediaCollector implements PeerConnectionTrackListener
{
    // The smoke test observes a finite sample of verified packets from each stream.
    public const SAMPLE_FRAMES = 12;

    /** @var array{audio:int,video:int} */
    public array $counts = ['audio' => 0, 'video' => 0];

    public ?string $failure = null;

    public function recordEncodedFrame(string $kind, EncodedPacket $frame): void
    {
        if ($this->failure !== null || !array_key_exists($kind, $this->counts)) {
            return;
        }
        $expected = $kind === 'audio'
            ? "\xf8\xff\xfe" : base64_decode(SyntheticMediaTrack::VP8_KEYFRAME_BASE64, true);
        if ($frame->getData() !== $expected) {
            $this->failure = 'Received media payload differs from the synthetic fixture';
            return;
        }
        if ($this->counts[$kind] < self::SAMPLE_FRAMES) {
            ++$this->counts[$kind];
        }
    }

    public function onPeerConnectionTrack(MediaStreamTrack $track): void
    {
        if (!$track instanceof RemoteStreamTrack) {
            throw new RuntimeException('Janus returned a non-remote track');
        }
        async(function () use ($track): void {
            try {
                foreach ($track->getConsumer() as $frame) {
                    $kind = $track->getKind()->value;
                    if (!array_key_exists($kind, $this->counts) || !$frame instanceof EncodedPacket) {
                        continue;
                    }
                    $this->recordEncodedFrame($kind, $frame);
                    if ($this->failure !== null || $this->counts[$kind] >= self::SAMPLE_FRAMES) {
                        return;
                    }
                }
            } catch (Throwable) {
                // Detached async task: preserve failure state rather than discarding it.
                $this->failure ??= 'Remote media stream consumption failed';
            }
        })->ignore();
    }
}
