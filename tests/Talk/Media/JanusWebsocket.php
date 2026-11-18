<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace LibreCode\AnsibleDocker\Tests\Talk\Media;

use Amp\TimeoutCancellation;
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
        $request = ['janus' => $type, 'transaction' => $transaction];
        if ($this->sessionId !== null && $type !== 'create') {
            $request['session_id'] = $this->sessionId;
        }
        if ($this->handleId !== null && $type === 'message') {
            $request['handle_id'] = $this->handleId;
        }
        if ($body !== null) {
            $request['body'] = $body;
        }
        if ($jsep !== null) {
            $request['jsep'] = $jsep;
        }
        if ($plugin !== null) {
            $request['plugin'] = $plugin;
        }
        $this->socket->sendText(json_encode($request, JSON_THROW_ON_ERROR));
        $timeout = new TimeoutCancellation(28);
        while (true) {
            $packet = $this->socket->receive($timeout);
            if ($packet === null) {
                throw new RuntimeException('Janus websocket closed');
            }
            $response = json_decode($packet->buffer($timeout), true, flags: JSON_THROW_ON_ERROR);
            if (($response['janus'] ?? '') === 'error' || isset($response['plugindata']['data']['error_code'])) {
                throw new RuntimeException('Janus protocol or VideoRoom error');
            }
            if (($response['janus'] ?? '') === 'ack') {
                continue;
            }
            if ($event !== null) {
                if (
                    in_array($response['janus'] ?? '', ['event', 'success'], true)
                    && ($response['sender'] ?? null) === $this->handleId
                    && ($response['plugindata']['data']['videoroom'] ?? null) === $event
                ) {
                    return $response;
                }
                continue;
            }
            if (($response['transaction'] ?? null) === $transaction) {
                return $response;
            }
        }
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
