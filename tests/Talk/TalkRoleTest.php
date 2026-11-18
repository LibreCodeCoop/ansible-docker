<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace LibreCode\AnsibleDocker\Tests\Talk;

use PHPUnit\Framework\TestCase;

final class TalkRoleTest extends TestCase
{
    private static string $parent;
    private static string $project;
    /** @var array<string,mixed> */
    private static array $args;

    public static function setUpBeforeClass(): void
    {
        self::$parent = TalkTestSupport::temp();
        self::$project = self::$parent . '/rendered';
        self::$args = TalkTestSupport::variables(self::$project);
        TalkTestSupport::render(self::$parent, self::$args);
    }

    public static function tearDownAfterClass(): void
    {
        TalkTestSupport::clean(self::$parent);
    }

    public function testImagesNetworkExposureAndReadOnlyConfiguration(): void
    {
        $config = TalkTestSupport::config(self::$project);
        self::assertEqualsCanonicalizing(['janus', 'nats', 'spreedbackend'], array_keys($config['services']));
        $janus = $config['services']['janus'];
        self::assertSame(TalkTestSupport::JANUS_IMAGE, $janus['image']);
        self::assertSame(
            'ghcr.io/strukturag/nextcloud-spreed-signaling:24e899d@sha256:a1924bbdae832f596568f11965a3a28695867d2557c1793d0919077d0c803c54',
            $config['services']['spreedbackend']['image']
        );
        self::assertSame(
            'nats:2-alpine@sha256:7ae34c25dfd6fe2e01c6483eabef20d796690fdcac6c4d94ca780a6cf9877fde',
            $config['services']['nats']['image']
        );
        self::assertSame(['--full-trickle'], $janus['command']);
        self::assertTrue($janus['read_only']);
        self::assertArrayNotHasKey('network_mode', $janus);
        self::assertSame(['hpb'], array_keys($janus['networks']));
        self::assertCount(51, $janus['ports']);
        self::assertSame([], array_filter($janus['ports'], static fn ($p) => $p['protocol'] !== 'udp'));
        self::assertSame([], array_filter($janus['ports'], static fn ($p) => $p['target'] === 8188));
        $names = array_map(static fn ($volume) => basename($volume['target']), $janus['volumes']);
        self::assertEqualsCanonicalizing(
            ['janus.jcfg', 'janus.transport.websockets.jcfg', 'janus.eventhandler.wsevh.jcfg'],
            $names
        );
        foreach ($janus['volumes'] as $volume) {
            self::assertTrue($volume['read_only']);
            self::assertFalse($volume['bind']['create_host_path'] ?? false);
        }
        self::assertSame(4, substr_count(file_get_contents(self::$project . '/docker-compose.yml'), 'create_host_path: false'));
        self::assertSame(['hpb'], array_keys($config['services']['spreedbackend']['networks']));
        self::assertEmpty($config['services']['spreedbackend']['ports'] ?? null);
        self::assertSame('/config/server.conf', $config['services']['spreedbackend']['environment']['CONFIG']);
        foreach (['talk_backend_secret', 'talk_session_hashkey'] as $key) {
            self::assertStringNotContainsString(self::$args[$key], json_encode($config, JSON_THROW_ON_ERROR));
        }
    }

    public function testRenderedJanusEventsAndProtectedSignalingConfiguration(): void
    {
        $janus = self::$project . '/volumes/janus/config';
        $main = file_get_contents($janus . '/janus.jcfg');
        self::assertStringContainsString('broadcast = true', $main);
        self::assertStringContainsString('rtp_port_range = "22600-22650"', $main);
        self::assertStringContainsString('ws_port = 8188', file_get_contents($janus . '/janus.transport.websockets.jcfg'));
        $events = file_get_contents($janus . '/janus.eventhandler.wsevh.jcfg');
        foreach (
            [
            'enabled = true', 'events = "handles,media,webrtc"',
            'backend = "ws://spreedbackend:8080/spreed"',
            'subprotocol = "janus-events"',
            'events_cap_on_reconnect = 500', 'events_max_queue = 2000',
            ] as $fragment
        ) {
            self::assertStringContainsString($fragment, $events);
        }
        self::assertSame(0640, fileperms(self::$project . '/server.conf') & 0777);
        $signaling = file_get_contents(self::$project . '/server.conf');
        self::assertSame(1, substr_count($signaling, "[backend]\n"));
        foreach (
            [
            "[nextcloud]\n", 'allowall = false',
            'url = nats://nats:4222', 'url = ws://janus:8188/',
            self::$args['talk_backend_secret'],
            ] as $fragment
        ) {
            self::assertStringContainsString($fragment, $signaling);
        }
        self::assertStringNotContainsString('[turn]', $signaling);
    }

    public function testIdempotentSecondRender(): void
    {
        $result = TalkTestSupport::render(self::$parent, self::$args);
        self::assertMatchesRegularExpression('/changed=0\s+unreachable=0\s+failed=0/', $result->getOutput());
    }

    public function testExternalNatMapping(): void
    {
        $project = self::$parent . '/nat';
        TalkTestSupport::render(self::$parent, TalkTestSupport::variables($project, [
            'talk_public_ipv4' => '192.0.2.10',
        ]));
        self::assertSame(
            ['--full-trickle', '--nat-1-1=192.0.2.10'],
            TalkTestSupport::config($project)['services']['janus']['command']
        );
    }

    public function testOptionalManagedTurn(): void
    {
        $project = self::$parent . '/turn';
        $args = TalkTestSupport::variables($project, self::turn());
        TalkTestSupport::render(self::$parent, $args);
        self::assertArrayHasKey('coturn', TalkTestSupport::config($project)['services']);
        self::assertStringContainsString("[turn]\n", file_get_contents($project . '/server.conf'));
        self::assertSame(0640, fileperms($project . '/turnserver.conf') & 0777);
    }

    public function testDisabledRoleDoesNotCreateProject(): void
    {
        $project = self::$parent . '/disabled';
        $result = TalkTestSupport::render(self::$parent, [
            'talk_deploy_enabled' => false, 'talk_project_dir' => $project,
        ]);
        self::assertTrue($result->isSuccessful());
        self::assertDirectoryDoesNotExist($project);
    }

    public function testHostileIniSectionInjectionIsRejectedWithoutSecretLeaks(): void
    {
        $payload = "\n[backend]\nallowall = true";
        $cases = [
            ['talk_nats_url' => 'nats://nats:4222' . $payload],
            ['talk_max_stream_bitrate' => '1048576' . $payload],
            ['talk_max_screen_bitrate' => '2097152' . $payload],
            ['talk_turn_api_key' => str_repeat('A', 32) . $payload,
                'talk_turn_servers' => ['turn:example.com:3478?transport=udp'],
                'talk_turn_shared_secret' => bin2hex(random_bytes(32))],
            ['talk_turn_shared_secret' => str_repeat('B', 40) . $payload,
                'talk_turn_servers' => ['turn:example.com:3478?transport=udp'],
                'talk_turn_api_key' => bin2hex(random_bytes(16))],
            ['talk_turn_servers' => ['turn:example.com:3478?transport=udp' . $payload],
                'talk_turn_api_key' => bin2hex(random_bytes(16)),
                'talk_turn_shared_secret' => bin2hex(random_bytes(32))],
        ];
        foreach ($cases as $index => $overrides) {
            TalkTestSupport::render(
                self::$parent,
                TalkTestSupport::variables(self::$parent . '/injection-' . $index, $overrides),
                success: false
            );
        }
        self::assertCount(6, $cases);
    }

    public function testInvalidNetworkAndSecuritySettingsAreRejected(): void
    {
        $cases = [
            ['talk_project_dir' => '/tmp/../etc/talk'],
            ['talk_signaling_port' => 70000],
            ['talk_nats_url' => 'nats://nats:99999'],
            self::turn(['talk_turn_realm' => "example.invalid\nno-auth"]),
            ['spreed_image' => 'ghcr.io/strukturag/nextcloud-spreed-signaling:24e899d'],
            ['nats_image' => 'nats:2-alpine'],
            ['talk_public_ipv4' => '999.999.999.999'],
            ['talk_backend_secret' => ''],
            ['janus_image' => 'ghcr.io/librecodecoop/janus:latest'],
            ['janus_rtp_port_end' => 66000],
            ['talk_manage_coturn' => true],
        ];
        foreach ($cases as $index => $overrides) {
            TalkTestSupport::render(
                self::$parent,
                TalkTestSupport::variables(self::$parent . '/invalid-' . $index, $overrides),
                success: false
            );
        }
        self::assertCount(11, $cases);
    }

    public function testJanusEntrypointIsNotRepeated(): void
    {
        $janus = TalkTestSupport::config(self::$project)['services']['janus'];
        self::assertNull($janus['entrypoint'] ?? null);
        self::assertStringNotContainsString('/usr/local/bin/janus', implode(' ', $janus['command']));
    }

    public function testComposeReconciliationRecreatesOnlyOnConfigChanges(): void
    {
        $project = self::$parent . '/reconcile';
        $bin = self::$parent . '/mock-bin';
        mkdir($bin, 0700);
        $log = self::$parent . '/compose-commands.log';
        $docker = trim(TalkTestSupport::command(['which', 'docker'])->getOutput());
        $wrapper = <<<'SH'
#!/bin/sh
for arg in "$@"; do
  if [ "$arg" = up ]; then
    printf '%s\n' "$*" >> "$TALK_COMPOSE_AUDIT_LOG"
    printf 'Container test Recreated\n' >&2
    exit 0
  fi
done
exec __DOCKER__ "$@"
SH;
        file_put_contents($bin . '/docker', str_replace('__DOCKER__', escapeshellarg($docker), $wrapper));
        chmod($bin . '/docker', 0755);
        $env = [
            'PATH' => $bin . PATH_SEPARATOR . getenv('PATH'),
            'TALK_COMPOSE_AUDIT_LOG' => $log,
        ];
        $args = TalkTestSupport::variables($project, ['talk_compose_up' => true]);
        TalkTestSupport::render(self::$parent, $args, env: $env);
        TalkTestSupport::render(self::$parent, $args, env: $env);
        $args['janus_event_queue_limit'] = 3000;
        TalkTestSupport::render(self::$parent, $args, env: $env);
        TalkTestSupport::render(self::$parent, $args, env: $env);
        $calls = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertCount(4, $calls);
        self::assertSame(
            [true, false, true, false],
            array_map(static fn ($call) => str_contains($call, '--force-recreate'), $calls)
        );
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private static function turn(array $overrides = []): array
    {
        return array_replace([
            'talk_public_ipv4' => '192.0.2.10',
            'talk_manage_coturn' => true,
            'talk_coturn_image' => TalkTestSupport::COTURN_IMAGE,
            'talk_turn_realm' => 'turn.example.invalid',
            'talk_turn_servers' => ['turn:turn.example.invalid:3478?transport=udp'],
            'talk_turn_shared_secret' => bin2hex(random_bytes(32)),
            'talk_turn_api_key' => bin2hex(random_bytes(16)),
        ], $overrides);
    }
}
