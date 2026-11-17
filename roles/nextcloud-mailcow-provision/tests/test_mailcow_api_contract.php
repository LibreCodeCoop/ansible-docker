<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
define('MAIL_PROVISION_POLICY_ONLY', true);
require __DIR__ . '/../files/nextcloud-mailcow-reconcile.php';

$settings = new MailProvisionSettings(
    'tenant.test', 'Staff', 'https://api.tenant.test/api/v1/',
    'imap.tenant.test', 993, 'smtp.tenant.test', 587, 'Example Tenant', 2048,
);
$requests = [];
$api = new MailcowApiClient('unit-test-only', $settings, function (string $method, string $path, ?array $body) use (&$requests): mixed {
    $requests[] = [$method, $path, $body];
    if ($method === 'GET' && str_starts_with($path, 'get/mailbox/')) {
        return [];
    }
    if ($method === 'GET') {
        return [['id' => 42, 'name' => 'nextcloud-test', 'mailbox' => 'alice@tenant.test']];
    }
    return [['type' => 'success']];
});
if ($api->mailbox('alice@tenant.test') !== null) {
    throw new RuntimeException('Absent mailbox must not be adopted');
}
$objectMailbox = new MailcowApiClient('unit-test-only', $settings,
    static fn (string $method, string $path, ?array $body): mixed => [
        'username' => 'alice@tenant.test', 'active' => 1,
    ]);
if (($objectMailbox->mailbox('alice@tenant.test')['active'] ?? null) !== 1) {
    throw new RuntimeException('Single-object mailbox response was rejected');
}
$singletonMailbox = new MailcowApiClient('unit-test-only', $settings,
    static fn (string $method, string $path, ?array $body): mixed => [[
        'username' => 'alice@tenant.test', 'active' => 1,
    ]]);
if ($singletonMailbox->mailbox('alice@tenant.test') === null) {
    throw new RuntimeException('Singleton mailbox compatibility regressed');
}
$wrongMailbox = new MailcowApiClient('unit-test-only', $settings,
    static fn (string $method, string $path, ?array $body): mixed => [
        'username' => 'victim@tenant.test', 'active' => 1,
    ]);
try {
    $wrongMailbox->mailbox('alice@tenant.test');
    throw new RuntimeException('An unrelated mailbox was accepted');
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(), 'ambiguous')) {
        throw $e;
    }
}
$api->createMailbox('alice@tenant.test', 'Aa1!temporary-mailbox-password', 1024);
$api->createAppPassword('alice@tenant.test', 'nextcloud-test', 'Aa1!per-user-app-only');
$passwords = $api->appPasswords('alice@tenant.test');
if (count($passwords) !== 1 || ($passwords[0]['name'] ?? null) !== 'nextcloud-test') {
    throw new RuntimeException('Managed application credential not discoverable');
}
$api->deleteAppPassword(42);
if (($requests[1][1] ?? null) !== 'add/mailbox'
    || ($requests[1][2]['local_part'] ?? null) !== 'alice'
    || ($requests[1][2]['domain'] ?? null) !== 'tenant.test'
    || ($requests[1][2]['quota'] ?? null) !== '1024'
    || ($requests[2][1] ?? null) !== 'add/app-passwd'
    || ($requests[2][2]['protocols'] ?? null) !== ['imap_access', 'smtp_access']
    || ($requests[4][1] ?? null) !== 'delete/app-passwd') {
    throw new RuntimeException('Mailcow API request contract drifted');
}
$args = MailReconcilePolicy::setupArguments('alice', 'alice@tenant.test', 'Aa1!per-user-app-only', $settings);
if (count($args) !== 14
    || $args[6] !== 'Aa1!per-user-app-only'
    || $args[11] !== 'Aa1!per-user-app-only'
    || $args[3] !== 993 || $args[8] !== 587 || $args[2] !== 'imap.tenant.test' || $args[7] !== 'smtp.tenant.test'
    || $args[5] !== 'alice@tenant.test'
    || $args[10] !== 'alice@tenant.test'
    || $args[12] !== 'alice') {
    throw new RuntimeException('Nextcloud Mail account setup contract is unsafe');
}
try {
    $api->request('POST', 'delete/mailbox', ['bad' => true]);
    throw new RuntimeException('Unapproved Mailcow API mutation was accepted');
} catch (InvalidArgumentException) {
    // Expected.
}
try {
    MailReconcilePolicy::setupArguments('alice', 'alice@tenant.test', '', $settings);
    throw new RuntimeException('Empty credential accepted');
} catch (InvalidArgumentException) {
    // Expected.
}
echo "Mailcow API and individual Nextcloud Mail credentials contract checks passed\n";
