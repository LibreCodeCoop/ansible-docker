<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace LibreCode\AnsibleDocker\Tests\Talk\Media;

use Webrtc\Codecs\EncodedPacket;
use Webrtc\RTP\MediaStreamTrack\MediaStreamTrack;
use Webrtc\RTP\MediaStreamTrack\RemoteStreamTrack;
use Webrtc\Webrtc\Listener\PeerConnectionTrackListener;

use function Amp\async;

final class MediaCollector implements PeerConnectionTrackListener
{
    /** @var array{audio:int,video:int} */
    public array $counts = ['audio' => 0, 'video' => 0];

    public function onPeerConnectionTrack(MediaStreamTrack $track): void
    {
        if (!$track instanceof RemoteStreamTrack) {
            throw new RuntimeException('Janus returned a non-remote track');
        }
        async(function () use ($track): void {
            foreach ($track->getConsumer() as $frame) {
                $kind = $track->getKind()->value;
                if (!array_key_exists($kind, $this->counts) || !$frame instanceof EncodedPacket) {
                    continue;
                }
                $expected = $kind === 'audio'
                    ? "\xf8\xff\xfe" : base64_decode(SyntheticMediaTrack::VP8_KEYFRAME_BASE64, true);
                if ($frame->getData() !== $expected) {
                    throw new RuntimeException('Received media payload differs from the synthetic fixture');
                }
                ++$this->counts[$kind];
                if ($this->counts[$kind] >= 12) {
                    return;
                }
            }
        })->ignore();
    }
}
