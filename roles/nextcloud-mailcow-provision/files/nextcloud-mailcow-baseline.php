<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Generate a one-time baseline of Nextcloud UIDs before enabling automatic
 * provisioning. Output is personal data: redirect directly to root-only
 * configuration on the host; never commit, paste or log its contents.
 */
declare(strict_types=1);
try {
    define('OC_CONSOLE', 1);
    $ncRoot = getenv('MAIL_PROVISION_NEXTCLOUD_ROOT');
    if (!is_string($ncRoot) || $ncRoot === ''
        || preg_match('~^/[a-zA-Z0-9_./-]+$~D', $ncRoot) !== 1
        || str_contains($ncRoot, '..')) {
        throw new \RuntimeException('Nextcloud root must be a validated absolute path');
    }
    require rtrim($ncRoot, '/') . '/lib/base.php';
    $users = \OCP\Server::get(\OCP\IUserManager::class);
    $uids = [];
    $users->callForAllUsers(static function (\OCP\IUser $user) use (&$uids): void {
        $uids[] = $user->getUID();
    });
    sort($uids, SORT_STRING);
    echo json_encode($uids, JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (\Throwable $e) {
    fwrite(STDERR, 'MAIL_BASELINE_FATAL category=' . get_class($e) . PHP_EOL);
    exit(3);
}
