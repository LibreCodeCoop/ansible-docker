<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace LibreCode\AnsibleDocker\Tests\Talk\Media;

use RuntimeException;
use Webrtc\RTP\Enum\MediaKind;
use Webrtc\SDP\RTCSessionDescription;
use Webrtc\Webrtc\Enum\ConnectionState;
use Webrtc\Webrtc\RTCPeerConnection;

use function Amp\delay;

/** Runs in a disposable test-only container, not in Nextcloud or production. */
final class JanusMediaSmoke
{
    private readonly RTCPeerConnection $publisher;
    private readonly RTCPeerConnection $subscriber;
    private readonly JanusWebsocket $pub;
    private readonly JanusWebsocket $sub;
    private readonly MediaCollector $collector;
    private readonly MediaReceiptPolicy $receipt;

    public function __construct(string $url)
    {
        $this->publisher = new RTCPeerConnection();
        $this->subscriber = new RTCPeerConnection();
        $this->pub = new JanusWebsocket($url);
        $this->sub = new JanusWebsocket($url);
        $this->collector = new MediaCollector();
        $this->receipt = new MediaReceiptPolicy();
        $this->subscriber->addTrackListener($this->collector);
    }

    public function run(): void
    {
        try {
            $this->pub->open();
            echo "JANUS_SESSION_OK\n";
            [$room, $publisherId] = $this->createRoomAndJoin();
            $this->publishMedia();
            $this->waitForPublisher();
            $this->subscribeToMedia($room, $publisherId);
            $this->verifyRealMedia();
            echo "WEBRTC_AUDIO_VIDEO_MEDIA_PASS\n";
        } finally {
            $this->publisher->close();
            $this->subscriber->close();
            $this->pub->close();
            $this->sub->close();
        }
    }

    /** @return array{int,int} */
    private function createRoomAndJoin(): array
    {
        $room = random_int(600000000, 900000000);
        $created = $this->pub->message([
            'request' => 'create',
            'room' => $room,
            'description' => 'PHP synthetic media smoke',
            'publishers' => 3,
            'is_private' => true,
            'audiocodec' => 'opus',
            'videocodec' => 'vp8',
            'permanent' => false,
        ]);
        if (($created['plugindata']['data']['videoroom'] ?? '') !== 'created') {
            throw new RuntimeException('Janus room creation failed');
        }
        $joined = $this->pub->message([
            'request' => 'join',
            'ptype' => 'publisher',
            'room' => $room,
            'display' => 'php-synthetic-publisher',
        ], event: 'joined');
        $publisherId = $joined['plugindata']['data']['id'] ?? null;
        if (!is_int($publisherId)) {
            throw new RuntimeException('Publisher did not join');
        }
        echo "VIDEOROOM_CREATE_AND_JOIN_OK\n";

        return [$room, $publisherId];
    }

    private function publishMedia(): void
    {
        $this->publisher->addTrack(new SyntheticMediaTrack(MediaKind::Audio));
        $this->publisher->addTrack(new SyntheticMediaTrack(MediaKind::Video));
        $this->publisher->setLocalDescription($this->publisher->createOffer());
        $answer = $this->pub->message(
            ['request' => 'publish', 'audio' => true, 'video' => true],
            ['type' => 'offer', 'sdp' => $this->publisher->getLocalDescription()->getSdp(), 'trickle' => false],
            event: 'event'
        );
        if (($answer['jsep']['type'] ?? '') !== 'answer') {
            throw new RuntimeException('Publisher SDP answer missing');
        }
        $this->publisher->setRemoteDescription(new RTCSessionDescription($answer['jsep']['sdp'], 'answer'));
        echo "PUBLISH_OFFER_ANSWER_OK\n";
    }

    private function waitForPublisher(): void
    {
        $deadline = microtime(true) + 20;
        while ($this->publisher->getConnectionState() !== ConnectionState::connected && microtime(true) < $deadline) {
            delay(0.25);
        }
        if ($this->publisher->getConnectionState() !== ConnectionState::connected) {
            throw new RuntimeException('Publisher ICE/DTLS failed to connect');
        }
    }

    private function subscribeToMedia(int $room, int $publisherId): void
    {
        $this->sub->open();
        $attached = $this->sub->message([
            'request' => 'join',
            'ptype' => 'subscriber',
            'room' => $room,
            'streams' => [['feed' => $publisherId]],
            'use_msid' => false,
        ], event: 'attached');
        if (($attached['jsep']['type'] ?? '') !== 'offer') {
            throw new RuntimeException('Subscriber SDP offer missing');
        }
        $this->subscriber->setRemoteDescription(new RTCSessionDescription($attached['jsep']['sdp'], 'offer'));
        foreach ($this->subscriber->getReceivers() as $receiver) {
            $receiver->setRawMode(true);
        }
        $this->subscriber->setLocalDescription($this->subscriber->createAnswer());
        $this->sub->message(
            ['request' => 'start', 'room' => $room],
            ['type' => 'answer', 'sdp' => $this->subscriber->getLocalDescription()->getSdp(), 'trickle' => false],
            event: 'event'
        );
        echo "SUBSCRIBER_OFFER_ANSWER_OK\n";
    }

    private function verifyRealMedia(): void
    {
        $deadline = microtime(true) + 22;
        while (microtime(true) < $deadline && !$this->mediaComplete()) {
            delay(0.25);
        }
        echo "WEBRTC_RESULT " . json_encode([
            'publisher_connection' => $this->publisher->getConnectionState()->name,
            'subscriber_connection' => $this->subscriber->getConnectionState()->name,
            'received_frames' => $this->collector->counts,
        ], JSON_THROW_ON_ERROR) . "\n";

        if (!$this->mediaComplete()) {
            throw new RuntimeException('Audio/video receipt or ICE connection validation failed');
        }
    }

    private function mediaComplete(): bool
    {
        return $this->receipt->hasMinimum($this->collector->counts)
            && $this->publisher->getConnectionState() === ConnectionState::connected
            && $this->subscriber->getConnectionState() === ConnectionState::connected;
    }
}
