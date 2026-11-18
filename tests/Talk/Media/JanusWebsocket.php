<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace LibreCode\AnsibleDocker\Tests\Talk\Media;

use Amp\TimeoutCancellation;
use RuntimeException;
use Throwable;
use Amp\Websocket\Client\WebsocketHandshake;
use Amp\Websocket\Client\WebsocketConnection;

use function Amp\Websocket\Client\connect;

final class JanusWebsocket
{
    private ?WebsocketConnection $socket = null;
    private ?int $sessionId = null;
    private ?int $handleId = null;

    public function __construct(private readonly string $url)
    {
    }

    public function open(): void
    {
        $handshake = (new WebsocketHandshake($this->url))
            ->withHeader('Sec-WebSocket-Protocol', 'janus-protocol');
        $this->socket = connect($handshake, new TimeoutCancellation(12));
        $protocol = $this->socket->getHandshakeResponse()->getHeader('sec-websocket-protocol');
        if ($protocol !== 'janus-protocol') {
            throw new RuntimeException('Janus did not negotiate expected websocket subprotocol');
        }
        $this->sessionId = $this->request('create')['data']['id'];
        $this->handleId = $this->request('attach', plugin: 'janus.plugin.videoroom')['data']['id'];
    }

    /** @return array<string,mixed> */
    public function request(
        string $type,
        ?array $body = null,
        ?array $jsep = null,
        ?string $plugin = null,
        ?string $event = null
    ): array {
        if ($this->socket === null) {
            throw new RuntimeException('Janus websocket not connected');
        }

        $transaction = bin2hex(random_bytes(16));
        $payload = $this->makePayload($type, $transaction, $body, $jsep, $plugin);
        $this->socket->sendText(json_encode($payload, JSON_THROW_ON_ERROR));

        return $this->waitForResponse($transaction, $event);
    }

    /** @return array<string,mixed> */
    private function makePayload(
        string $type,
        string $transaction,
        ?array $body,
        ?array $jsep,
        ?string $plugin
    ): array {
        $payload = ['janus' => $type, 'transaction' => $transaction];
        if ($this->sessionId !== null && $type !== 'create') {
            $payload['session_id'] = $this->sessionId;
        }
        if ($this->handleId !== null && $type === 'message') {
            $payload['handle_id'] = $this->handleId;
        }
        foreach (['body' => $body, 'jsep' => $jsep, 'plugin' => $plugin] as $key => $value) {
            if ($value !== null) {
                $payload[$key] = $value;
            }
        }
        return $payload;
    }

    /** @return array<string,mixed> */
    private function waitForResponse(string $transaction, ?string $event): array
    {
        if ($this->socket === null) {
            throw new RuntimeException('Janus websocket not connected');
        }

        $timeout = new TimeoutCancellation(28);
        while (true) {
            $packet = $this->socket->receive($timeout);
            if ($packet === null) {
                throw new RuntimeException('Janus websocket closed');
            }
            $response = json_decode($packet->buffer($timeout), true, flags: JSON_THROW_ON_ERROR);
            $this->assertProtocolSuccess($response);

            if (($response['janus'] ?? '') === 'ack') {
                continue;
            }
            if ($event === null && ($response['transaction'] ?? null) === $transaction) {
                return $response;
            }
            if ($event !== null && $this->matchesEvent($response, $event)) {
                return $response;
            }
        }
    }

    /** @param array<string,mixed> $response */
    private function assertProtocolSuccess(array $response): void
    {
        if (($response['janus'] ?? '') === 'error' || isset($response['plugindata']['data']['error_code'])) {
            throw new RuntimeException('Janus protocol or VideoRoom error');
        }
    }

    /** @param array<string,mixed> $response */
    private function matchesEvent(array $response, string $event): bool
    {
        return in_array($response['janus'] ?? '', ['event', 'success'], true)
            && ($response['sender'] ?? null) === $this->handleId
            && ($response['plugindata']['data']['videoroom'] ?? null) === $event;
    }

    /** @return array<string,mixed> */
    public function message(array $body, ?array $jsep = null, ?string $event = null): array
    {
        return $this->request('message', $body, $jsep, event: $event);
    }

    public function close(): void
    {
        if ($this->socket === null) {
            return;
        }
        if ($this->sessionId !== null) {
            try {
                $this->socket->sendText(json_encode([
                    'janus' => 'destroy', 'session_id' => $this->sessionId,
                    'transaction' => bin2hex(random_bytes(16)),
                ], JSON_THROW_ON_ERROR));
            } catch (Throwable) {
                // Connection may already be gone.
            }
        }
        $this->socket->close();
    }
}
