<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace LibreCode\AnsibleDocker\Tests\Talk;

use RuntimeException;
use Symfony\Component\Process\Process;

final class TalkTestSupport
{
    public const JANUS_IMAGE = 'ghcr.io/librecodecoop/janus:1.4.3-r1@sha256:7d9c1bd28bdbc7702de924ed8a676f5c1f11afa3abc888654d8d4fc8b78a00d2';
    public const COTURN_IMAGE = 'coturn/coturn:4.6.3-alpine@sha256:e2bca2f79a4269d7240de5872ab60a9305013ad37296d2acf14f9510874346be';

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function temp(string $prefix = 'talk-role-'): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(8));
        if (!mkdir($path, 0700)) {
            throw new RuntimeException('Cannot create isolated test directory');
        }
        return $path;
    }

    /** @return array<string,mixed> */
    public static function variables(string $project, array $overrides = []): array
    {
        $owner = posix_getpwuid(posix_geteuid())['name'];
        $args = [
            'talk_deploy_enabled' => true,
            'talk_compose_up' => false,
            'talk_project_dir' => $project,
            'talk_nextcloud_url' => 'https://cloud.example.invalid',
            'talk_signaling_hostname' => 'talk.example.invalid',
            'talk_letsencrypt_email' => 'contact@example.invalid',
            'talk_reverse_proxy_network' => '',
            'talk_public_ipv4' => '',
            'talk_signaling_config_owner' => $owner,
            'talk_signaling_config_group' => (string) posix_getegid(),
            'talk_coturn_config_owner' => $owner,
            'talk_coturn_config_group' => (string) posix_getegid(),
            'talk_session_hashkey' => bin2hex(random_bytes(32)),
            'talk_session_blockkey' => bin2hex(random_bytes(16)),
            'talk_internal_secret' => bin2hex(random_bytes(32)),
            'talk_backend_secret' => bin2hex(random_bytes(32)),
            'janus_rtp_port_start' => 22600,
            'janus_rtp_port_end' => 22650,
        ];
        return array_replace($args, $overrides);
    }

    /** @param list<string> $args @param array<string,string> $env */
    public static function command(array $args, bool $check = true, array $env = [], int $timeout = 120): Process
    {
        $process = new Process($args, self::root(), $env);
        $process->setTimeout($timeout);
        $process->run();
        if ($check && !$process->isSuccessful()) {
            // Never include sensitive Ansible values in exception messages.
            throw new RuntimeException('Isolated command failed: ' . $args[0] . ' (exit ' . $process->getExitCode() . ')');
        }
        return $process;
    }

    /** @param array<string,mixed> $config @param array<string,string> $env */
    public static function render(string $parent, array $config, bool $success = true, array $env = []): Process
    {
        $path = $parent . '/vars-' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));
        chmod($path, 0600);
        try {
            $env['ANSIBLE_ROLES_PATH'] = self::root() . '/roles';
            $result = self::command([
                'ansible-playbook', '-i', 'localhost,', '-c', 'local',
                self::root() . '/tests/nextcloud-talk/render.yml',
                '--extra-vars', '@' . $path,
            ], false, $env);
        } finally {
            unlink($path);
        }
        if ($success !== $result->isSuccessful()) {
            throw new RuntimeException('Unexpected Ansible result (exit ' . $result->getExitCode() . ')');
        }
        if (!$success) {
            foreach (['talk_session_hashkey', 'talk_internal_secret', 'talk_backend_secret'] as $name) {
                $secret = $config[$name] ?? '';
                if (
                    is_string($secret) && $secret !== '' &&
                    str_contains($result->getOutput() . $result->getErrorOutput(), $secret)
                ) {
                    throw new RuntimeException('Ansible validation leaked a synthetic secret');
                }
            }
        }
        return $result;
    }

    /** @return array<string,mixed> */
    public static function config(string $project): array
    {
        $process = self::command([
            'docker', 'compose', '--project-directory', $project,
            '-f', $project . '/docker-compose.yml', 'config', '--format', 'json',
        ]);
        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function clean(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (new \FilesystemIterator($directory) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                self::clean($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }

    public static function waitFor(callable $condition, int $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }
            usleep(300_000);
        }
        throw new RuntimeException('Isolated test condition was not met');
    }
}
