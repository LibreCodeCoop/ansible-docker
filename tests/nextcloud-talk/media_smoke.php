<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Synthetic WebRTC publisher/subscriber against Janus VideoRoom. Test-only.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use LibreCode\AnsibleDocker\Tests\Talk\Media\SyntheticMediaTrack;
use LibreCode\AnsibleDocker\Tests\Talk\Media\MediaCollector;
use LibreCode\AnsibleDocker\Tests\Talk\Media\JanusWebsocket;
use Webrtc\RTP\Enum\MediaKind;
use Webrtc\SDP\RTCSessionDescription;
use Webrtc\Webrtc\Enum\ConnectionState;
use Webrtc\Webrtc\RTCPeerConnection;
use function Amp\delay;

$publisher = new RTCPeerConnection();
$subscriber = new RTCPeerConnection();
$pub = new JanusWebsocket(getenv('JANUS_WS_URL') ?: 'ws://janus:8188/');
$sub = new JanusWebsocket(getenv('JANUS_WS_URL') ?: 'ws://janus:8188/');
$collector = new MediaCollector();
$subscriber->addTrackListener($collector);
$room = random_int(600000000, 900000000);
try {
    $pub->open();
    echo "JANUS_SESSION_OK\n";
    $created = $pub->message([
        'request' => 'create', 'room' => $room, 'description' => 'PHP synthetic media smoke',
        'publishers' => 3, 'is_private' => true, 'audiocodec' => 'opus',
        'videocodec' => 'vp8', 'permanent' => false,
    ]);
    if (($created['plugindata']['data']['videoroom'] ?? '') !== 'created') {
        throw new RuntimeException('Janus room creation failed');
    }
    $joined = $pub->message([
        'request' => 'join', 'ptype' => 'publisher', 'room' => $room,
        'display' => 'php-synthetic-publisher',
    ], event: 'joined');
    $publisherId = $joined['plugindata']['data']['id'] ?? null;
    if (!is_int($publisherId)) {
        throw new RuntimeException('Publisher did not join');
    }
    echo "VIDEOROOM_CREATE_AND_JOIN_OK\n";

    $audio = new SyntheticMediaTrack(MediaKind::Audio);
    $video = new SyntheticMediaTrack(MediaKind::Video);
    $publisher->addTrack($audio);
    $publisher->addTrack($video);
    $offer = $publisher->createOffer();
    $publisher->setLocalDescription($offer);
    $answer = $pub->message(
        ['request' => 'publish', 'audio' => true, 'video' => true],
        ['type' => 'offer', 'sdp' => $publisher->getLocalDescription()->getSdp(), 'trickle' => false],
        event: 'event'
    );
    if (($answer['jsep']['type'] ?? '') !== 'answer') {
        throw new RuntimeException('Publisher SDP answer missing');
    }
    $publisher->setRemoteDescription(new RTCSessionDescription($answer['jsep']['sdp'], 'answer'));
    echo "PUBLISH_OFFER_ANSWER_OK\n";

    $deadline = microtime(true) + 20;
    while ($publisher->getConnectionState() !== ConnectionState::connected && microtime(true) < $deadline) {
        delay(0.25);
    }
    if ($publisher->getConnectionState() !== ConnectionState::connected) {
        throw new RuntimeException('Publisher ICE/DTLS failed to connect');
    }
    $sub->open();
    $attached = $sub->message([
        'request' => 'join', 'ptype' => 'subscriber', 'room' => $room,
        'streams' => [['feed' => $publisherId]], 'use_msid' => false,
    ], event: 'attached');
    if (($attached['jsep']['type'] ?? '') !== 'offer') {
        throw new RuntimeException('Subscriber SDP offer missing');
    }
    $subscriber->setRemoteDescription(new RTCSessionDescription($attached['jsep']['sdp'], 'offer'));
    foreach ($subscriber->getReceivers() as $receiver) {
        // Consume encoded media directly; do not require FFmpeg or codec FFI in CI.
        $receiver->setRawMode(true);
    }
    $response = $subscriber->createAnswer();
    $subscriber->setLocalDescription($response);
    $sub->message(
        ['request' => 'start', 'room' => $room],
        ['type' => 'answer', 'sdp' => $subscriber->getLocalDescription()->getSdp(), 'trickle' => false],
        event: 'event'
    );
    echo "SUBSCRIBER_OFFER_ANSWER_OK\n";

    $deadline = microtime(true) + 22;
    while (microtime(true) < $deadline) {
        if (
            $collector->counts['audio'] >= 5
            && $collector->counts['video'] >= 5
            && $publisher->getConnectionState() === ConnectionState::connected
            && $subscriber->getConnectionState() === ConnectionState::connected
        ) {
            break;
        }
        delay(0.25);
    }
    echo "WEBRTC_RESULT " . json_encode([
        'publisher_connection' => $publisher->getConnectionState()->name,
        'subscriber_connection' => $subscriber->getConnectionState()->name,
        'received_frames' => $collector->counts,
    ], JSON_THROW_ON_ERROR) . "\n";
    if ($collector->counts['audio'] < 5 || $collector->counts['video'] < 5) {
        throw new RuntimeException('Insufficient audio/video received frames');
    }
    echo "WEBRTC_AUDIO_VIDEO_MEDIA_PASS\n";
} finally {
    $publisher->close();
    $subscriber->close();
    $pub->close();
    $sub->close();
}
