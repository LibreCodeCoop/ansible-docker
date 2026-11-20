<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace LibreCode\AnsibleDocker\Tests\Talk;

use PHPUnit\Framework\TestCase;

final class TalkContainerIntegrationTest extends TestCase
{
    private static string $parent;
    private static string $project;
    private static string $projectName;

    public static function setUpBeforeClass(): void
    {
        if (getenv('RUN_HPB_INTEGRATION') !== '1') {
            self::markTestSkipped('Container integration is explicitly opt-in');
        }
        self::$parent = TalkTestSupport::temp('talk-role-media-');
        self::$project = self::$parent . '/rendered';
        self::$projectName = 'talkci' . bin2hex(random_bytes(5));
        TalkTestSupport::render(self::$parent, TalkTestSupport::variables(self::$project));
        try {
            TalkTestSupport::command([
                'docker', 'run', '--rm', '--user', '0:0', '--entrypoint', 'chgrp',
                '-v', self::$project . ':/hpb', TalkTestSupport::helperImage(), '850', '/hpb/server.conf',
            ], timeout: 100);
            TalkTestSupport::command(array_merge(
                self::compose(),
                ['up', '-d', '--no-build', '--pull', 'missing', 'janus', 'nats', 'spreedbackend']
            ), timeout: 300);
        } catch (\Throwable $exception) {
            self::stopStack();
            TalkTestSupport::clean(self::$parent);
            throw $exception;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!isset(self::$parent)) {
            return;
        }
        self::stopStack();
        TalkTestSupport::clean(self::$parent);
    }

    /** @return list<string> */
    private static function compose(): array
    {
        return [
            'docker', 'compose', '-p', self::$projectName,
            '--project-directory', self::$project, '-f', self::$project . '/docker-compose.yml',
        ];
    }

    private static function stopStack(): void
    {
        if (isset(self::$project)) {
            TalkTestSupport::command(
                array_merge(self::compose(), ['down', '--volumes', '--remove-orphans']),
                check: false
            );
        }
    }

    private static function logs(string $service): string
    {
        return TalkTestSupport::command(
            array_merge(self::compose(), ['logs', '--no-color', $service])
        )->getOutput();
    }

    private static function waitFor(string $service, array $markers): void
    {
        TalkTestSupport::waitFor(
            static function () use ($service, $markers): bool {
                $logs = self::logs($service);
                foreach ($markers as $marker) {
                    if (!str_contains($logs, $marker)) {
                        return false;
                    }
                }
                return true;
            },
            95,
            'Expected ' . $service . ' readiness markers were not observed'
        );
    }

    public function testCoturnReadsProtectedConfigurationAndStartsUnprivileged(): void
    {
        $parent = TalkTestSupport::temp('talk-role-turn-');
        $project = $parent . '/turn';
        $name = 'talkturn' . bin2hex(random_bytes(5));
        $container = $name . '-coturn';
        $config = TalkTestSupport::variables($project, [
            'talk_public_ipv4' => '192.0.2.10',
            'talk_manage_coturn' => true,
            'talk_coturn_image' => TalkTestSupport::coturnImage(),
            'talk_turn_realm' => 'turn.example.invalid',
            'talk_turn_servers' => ['turn:turn.example.invalid:3478?transport=udp'],
            'talk_turn_api_key' => bin2hex(random_bytes(16)),
            'talk_turn_shared_secret' => bin2hex(random_bytes(32)),
        ]);
        try {
            TalkTestSupport::render($parent, $config);
            TalkTestSupport::command([
                'docker', 'run', '--rm', '--user', '0:0', '--entrypoint', 'chgrp',
                '-v', $project . ':/hpb', TalkTestSupport::helperImage(), '65533', '/hpb/turnserver.conf',
            ], timeout: 100);
            $compose = ['docker', 'compose', '-p', $name, '-f', $project . '/docker-compose.yml'];
            try {
                $proof = TalkTestSupport::command(array_merge(
                    $compose,
                    [
                        'run', '--no-deps', '--rm', '--entrypoint', '/bin/sh',
                        'coturn', '-c',
                        'test -r /etc/turnserver.conf && echo COTURN_CONFIG_READABLE',
                    ]
                ), timeout: 150);
                self::assertStringContainsString('COTURN_CONFIG_READABLE', $proof->getOutput());
                TalkTestSupport::command(
                    array_merge($compose, ['run', '--no-deps', '-d', '--name', $container, 'coturn']),
                    timeout: 180
                );
                sleep(3);
                $status = TalkTestSupport::command([
                    'docker', 'inspect', '--format', '{{.State.Running}}', $container,
                ])->getOutput();
                self::assertSame('true', trim($status));
            } finally {
                TalkTestSupport::command(['docker', 'rm', '-f', $container], check: false);
                TalkTestSupport::command(
                    array_merge($compose, ['down', '--volumes', '--remove-orphans']),
                    check: false
                );
            }
        } finally {
            TalkTestSupport::clean($parent);
        }
    }

    public function testJanusEventsAndRealAudioVideoMediaRecoverAfterSignalingRestart(): void
    {
        // Observe the signaling-to-Janus connection. Real media below proves
        // Janus VideoRoom readiness without relying on incidental startup logs.
        self::waitFor('spreedbackend', ['Connected to Janus WebRTC Server']);
        $this->runMedia();
        $previousJanus = substr_count(self::logs('janus'), 'WebSocketsEventHandler connected');
        $previousSignaling = substr_count(
            self::logs('spreedbackend'),
            'Connected to Janus WebRTC Server'
        );
        TalkTestSupport::command(
            array_merge(self::compose(), ['restart', 'spreedbackend']),
            timeout: 40
        );
        TalkTestSupport::waitFor(
            static fn () => substr_count(self::logs('janus'), 'WebSocketsEventHandler connected') > $previousJanus
                && substr_count(
                    self::logs('spreedbackend'),
                    'Connected to Janus WebRTC Server'
                ) > $previousSignaling,
            55,
            'Signaling restart did not re-establish the Janus event subscription'
        );
        $this->runMedia();
    }

    private function runMedia(): void
    {
        $root = TalkTestSupport::root();
        $process = TalkTestSupport::command([
            'docker', 'run', '--rm', '--network', self::$projectName . '_hpb',
            '--security-opt', 'no-new-privileges',
            '--read-only', '--tmpfs', '/tmp',
            '-v', $root . ':/repo:ro', '-w', '/repo',
            '-e', 'JANUS_WS_URL=ws://janus:8188/',
            'php:8.4-cli', 'php', 'tests/nextcloud-talk/media_smoke.php',
        ], timeout: 125);
        self::assertStringContainsString('WEBRTC_AUDIO_VIDEO_MEDIA_PASS', $process->getOutput());
    }
}
