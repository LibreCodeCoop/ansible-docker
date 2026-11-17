<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Contract/integration tests with in-memory Mailcow + Nextcloud substitutes.
 * The same MailProvisionExecutor methods execute in production, without
 * mocking away the orchestration logic.
 */
declare(strict_types=1);
define('MAIL_PROVISION_POLICY_ONLY', true);
require __DIR__ . '/../files/nextcloud-mailcow-reconcile.php';

final class FakeProvisionUser {
    public function __construct(private string $email, private bool $enabled = true) {}
    public function isEnabled(): bool { return $this->enabled; }
    public function getEMailAddress(): string { return $this->email; }
}
final class FakeProvisionUsers {
    public array $users = [];
    public function get(string $uid): ?object { return $this->users[$uid] ?? null; }
}
final class FakeProvisionGroups {
    public array $members = [];
    public function isInGroup(string $uid, string $group): bool { return $group === 'Employees' && ($this->members[$uid] ?? false); }
}
final class FakeProvisionApp {
    public function isEnabledForUser(string $app, object $user): bool { return $app === 'mail'; }
}
final class FakeProvisionAccount {
    public function __construct(private int $id, private string $uid, private string $email) {}
    public function getId(): int { return $this->id; }
    public function getUserId(): string { return $this->uid; }
    public function getEmail(): string { return $this->email; }
}
final class FakeProvisionAccounts {
    public array $items = [];
    public function add(string $uid, string $mailbox): void {
        $this->items[] = new FakeProvisionAccount(count($this->items)+100, $uid, $mailbox);
    }
    public function findByUserId(string $uid): array {
        return array_values(array_filter($this->items, static fn (object $a) => $a->getUserId() === $uid));
    }
    public function getAllAccounts(): array { return $this->items; }
}
final class FakeProvisionDefinition {
    public function __construct(private string $key) {}
    public function getId(): string { return $this->key; }
}
final class FakeProvisionStored {
    public function __construct(private string $uid) {}
    public function getUserUid(): string { return $this->uid; }
}
final class FakeProvisionValues {
    public array $rows = [];
    public function get(string $uid, string $key): mixed { return $this->rows[$uid][$key] ?? null; }
    public function upsert(object $field, string $uid, mixed $value, string $actor, string $visibility): void {
        if ($actor !== 'mail-provision-worker' || $visibility !== 'private') {
            throw new RuntimeException('Profile audit or visibility contract violated');
        }
        $this->rows[$uid][$field->getId()] = $value;
    }
    public function findByDefinitionAndRawValue(object $field, mixed $value): array {
        $matches = [];
        foreach ($this->rows as $uid => $row) {
            if (($row[$field->getId()] ?? null) === $value) {
                $matches[] = new FakeProvisionStored($uid);
            }
        }
        return $matches;
    }
}
function insist(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}

$settings = new MailProvisionSettings(
    'example.test', 'Employees', 'https://mail.example.test/api/v1/',
    'imap.example.test', 993, 'smtp.example.test', 587, 'Example', 1024,
);
$users = new FakeProvisionUsers();
$groups = new FakeProvisionGroups();
$app = new FakeProvisionApp();
$accounts = new FakeProvisionAccounts();
$values = new FakeProvisionValues();
$keys = [
    'mailcow_mailbox', 'mail_mailbox_kind', 'mailcow_login_enabled',
    'mail_match_unique', 'mail_mapping_evidence_ref',
    'mail_connection_state', 'mail_existing_account_id',
];
$fields = [];
foreach ($keys as $key) { $fields[$key] = new FakeProvisionDefinition($key); }
$boxes = [];
$appPasswords = [];
$events = [];
$masterSecrets = [];
$transport = function (string $method, string $path, ?array $payload) use (&$boxes, &$appPasswords, &$events, &$masterSecrets): mixed {
    $events[] = $method.' '.$path;
    if ($path === 'add/mailbox') {
        $email = $payload['local_part'].'@'.$payload['domain'];
        if (isset($boxes[$email])) { return [['type' => 'error']]; }
        $boxes[$email] = ['username' => $email, 'active' => '1'];
        $masterSecrets[$email] = $payload['password'];
        return [['type' => 'success']];
    }
    if ($path === 'add/app-passwd') {
        $email = $payload['username'];
        $appPasswords[$email][] = [
            'id' => count($events) + 1000,
            'name' => $payload['app_name'],
            'secret' => $payload['app_passwd'],
            'protocols' => $payload['protocols'],
        ];
        return [['type' => 'success']];
    }
    if ($path === 'delete/app-passwd') {
        $wanted = (int)$payload[0];
        foreach ($appPasswords as $email => $items) {
            $appPasswords[$email] = array_values(array_filter(
                $items, static fn (array $item): bool => $item['id'] !== $wanted,
            ));
        }
        return [['type' => 'success']];
    }
    if (str_starts_with($path, 'get/mailbox/')) {
        $email = substr($path, strlen('get/mailbox/'));
        return isset($boxes[$email]) ? [$boxes[$email]] : [];
    }
    if (str_starts_with($path, 'get/app-passwd/all/')) {
        $email = substr($path, strlen('get/app-passwd/all/'));
        return array_map(static function (array $item): array {
            unset($item['secret']);
            return $item;
        }, $appPasswords[$email] ?? []);
    }
    throw new RuntimeException('Unexpected Mailcow call');
};
$gateway = new MailcowApiClient('test-only', $settings, $transport);
$failSetupFor = '';
$seenConnections = [];
$setup = function (...$args) use ($accounts, &$appPasswords, &$masterSecrets, &$seenConnections, &$failSetupFor): void {
    $uid = $args[12];
    $mailbox = $args[1];
    insist(count($args) === 14 && $args[3] === 993 && $args[8] === 587, 'Unexpected Nextcloud Mail settings');
    insist($args[5] === $mailbox && $args[10] === $mailbox, 'Wrong per-box login');
    insist($args[6] === $args[11], 'Different IMAP/SMTP app passwords');
    insist($args[6] !== ($masterSecrets[$mailbox] ?? null), 'Mailbox/master password leaked to Nextcloud');
    insist($args[6] === ($appPasswords[$mailbox][0]['secret'] ?? null), 'Not using individual app password');
    insist(($appPasswords[$mailbox][0]['protocols'] ?? null) === ['imap_access','smtp_access'], 'Protocol permissions too broad');
    $seenConnections[] = $uid;
    if ($uid === $failSetupFor) {
        throw new RuntimeException('Synthetic Nextcloud setup error');
    }
    $accounts->add($uid, $mailbox);
};
$executor = new MailProvisionExecutor(
    $settings, $gateway, $users, $groups, $app, $accounts, $values, $fields,
    static fn (string $uid, string $key): mixed => $values->get($uid, $key),
    $setup,
);

// New user creates exactly one mailbox and one individual app password.
$users->users['alice'] = new FakeProvisionUser('alice@example.test');
$groups->members['alice'] = true;
$outcomes = ['alice' => 'candidate'];
$executor->apply(['alice' => ['mailbox' => 'alice@example.test', 'mode' => 'auto']], $outcomes);
insist($outcomes['alice'] === 'created', 'New user onboarding failed');
insist(count($boxes) === 1 && count($appPasswords['alice@example.test']) === 1, 'Wrong mailbox/password counts');
insist(count($accounts->items) === 1, 'Nextcloud Mail account not created');
insist($values->get('alice','mail_connection_state') === 'managed', 'Missing managed metadata');
insist($values->get('alice','mail_existing_account_id') === 100, 'Wrong account metadata');
insist(count($seenConnections) === 1, 'SetupService not called exactly once');

// A stale candidate must never duplicate an already-configured user.
$oldEvents = count($events);
$executor->apply(['alice' => ['mailbox' => 'alice@example.test', 'mode' => 'auto']], $outcomes);
insist($outcomes['alice'] === 'review_state_changed' && count($events) === $oldEvents, 'Idempotence broken');

// Approved existing mailbox: connect without changing mailbox itself.
$boxes['bob@example.test'] = ['username' => 'bob@example.test', 'active' => '1'];
$users->users['bob'] = new FakeProvisionUser('bob@example.test');
$groups->members['bob'] = true;
foreach (['mailcow_mailbox' => 'bob@example.test', 'mail_mailbox_kind' => 'personal_primary',
          'mailcow_login_enabled' => true, 'mail_match_unique' => true,
          'mail_mapping_evidence_ref' => 'admin-approval-456'] as $key => $value) {
    $values->upsert($fields[$key], 'bob', $value, 'mail-provision-worker', 'private');
}
$outcomes['bob'] = 'candidate';
$executor->apply(['bob' => ['mailbox' => 'bob@example.test', 'mode' => 'mapped']], $outcomes);
insist($outcomes['bob'] === 'created' && count($accounts->items) === 2, 'Existing approved mailbox not connected');
insist(!isset($masterSecrets['bob@example.test']), 'Existing mailbox was recreated');

// An unrelated preexisting mailbox can never be claimed by UID similarity.
$boxes['eve@example.test'] = ['username' => 'eve@example.test', 'active' => '1'];
$users->users['eve'] = new FakeProvisionUser('eve@example.test');
$groups->members['eve'] = true;
$outcomes['eve'] = 'candidate';
$executor->apply(['eve' => ['mailbox' => 'eve@example.test', 'mode' => 'auto']], $outcomes);
insist($outcomes['eve'] === 'review_existing_mailbox_unverified', 'Mailbox hijacking detected');
insist(!isset($appPasswords['eve@example.test']), 'Generated app password for unverified mailbox');

// A failing setup revokes only the disposable app password; leaves the box
// for explicit provenance review instead of taking it over on retry.
$users->users['dave'] = new FakeProvisionUser('dave@example.test');
$groups->members['dave'] = true;
$failSetupFor = 'dave';
$outcomes['dave'] = 'candidate';
$executor->apply(['dave' => ['mailbox' => 'dave@example.test', 'mode' => 'auto']], $outcomes);
insist($outcomes['dave'] === 'error_connection_or_creation', 'Partial failure was not signaled');
insist(count($appPasswords['dave@example.test']) === 0, 'Orphan app password not revoked');
insist(isset($boxes['dave@example.test']), 'Partial mailbox incorrectly deleted');
insist($values->get('dave','mail_mapping_evidence_ref') === MailReconcilePolicy::intent('dave','dave@example.test'), 'No durable intent marker');
$outcomes['dave'] = 'candidate';
$executor->apply(['dave' => ['mailbox' => 'dave@example.test', 'mode' => 'staged']], $outcomes);
insist($outcomes['dave'] === 'review_staged_mailbox_provenance', 'Interrupted provisioning wrongly resumed');


// With signature opt-in, the *existing* account creation path must persist
// a private pending intent before calling Nextcloud Mail SetupService.
// No preexisting or manually connected account is retroactively enrolled.
$signatureSettings = new MailProvisionSettings(
    'example.test', 'Employees', 'https://mail.example.test/api/v1/',
    'imap.example.test', 993, 'smtp.example.test', 587, 'Example', 1024,
    true, "{name}\n{role}\n{email}", 'Member', 'Example Organization',
);
$fields['mail_signature_role'] = new FakeProvisionDefinition('mail_signature_role');
$fields['mail_signature_state'] = new FakeProvisionDefinition('mail_signature_state');
$users->users['newmember'] = new FakeProvisionUser('newmember@example.test');
$groups->members['newmember'] = true;
$signatureExecutor = new MailProvisionExecutor(
    $signatureSettings, $gateway, $users, $groups, $app, $accounts,
    $values, $fields,
    static fn (string $uid, string $key): mixed => $values->get($uid, $key),
    $setup,
);
$outcomes['newmember'] = 'candidate';
$signatureExecutor->apply(['newmember' => [
    'mailbox' => 'newmember@example.test', 'mode' => 'auto',
]], $outcomes);
insist($outcomes['newmember'] === 'created', 'Signature onboarding must not break connection setup');
insist($values->get('newmember', 'mail_signature_state') === 'pending', 'Missing durable signature intent');
insist($values->get('alice', 'mail_signature_state') === null, 'Existing account improperly enrolled');
$signatureExecutor->apply(['newmember' => [
    'mailbox' => 'newmember@example.test', 'mode' => 'auto',
]], $outcomes);
insist($outcomes['newmember'] === 'review_state_changed', 'Signature retry duplicated account creation');
insist($values->get('newmember', 'mail_signature_state') === 'pending', 'Retry lost the signature intent');

echo "Executor contract checks passed: new, existing, idempotence, ownership and partial failure\n";
