<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Offline, no secret, no Nextcloud or Mailcow dependency.
 */
declare(strict_types=1);
define('MAIL_PROVISION_POLICY_ONLY', true);
require __DIR__ . '/../files/nextcloud-mailcow-reconcile.php';

function verifySignature(bool $valid, string $case): void {
    if (!$valid) {
        throw new RuntimeException('Signature check failed: ' . $case);
    }
}
$settings = new MailProvisionSettings(
    'example.test', 'Members', 'https://mail.example.test/api/v1/',
    'imap.example.test', 993, 'smtp.example.test', 587, 'Example Mail', 512,
    true,
    "{name}\n{role} | {organization}\n{email}\n{website}",
    'Member', 'Example Cooperative', 'https://example.test',
);
verifySignature($settings->signatureEnabled, 'enabled from config');
verifySignature(
    MailSignaturePolicy::render($settings, 'Alice Example', 'alice@example.test', null)
        === "Alice Example\nMember | Example Cooperative\nalice@example.test\nhttps://example.test",
    'default role is configurable',
);
verifySignature(
    str_contains(MailSignaturePolicy::render($settings, 'Alice', 'alice@example.test', 'CTO'), 'CTO | Example Cooperative'),
    'user-specific role',
);
verifySignature(
    str_contains(MailSignaturePolicy::render($settings, 'Daiane', 'd@example.test', 'CEO'), 'CEO | Example Cooperative'),
    'second role without code change',
);
verifySignature(
    str_contains(MailSignaturePolicy::render($settings, 'Alice', 'alice@example.test', "CTO\r\nInjected header"), 'CTO Injected header'),
    'profile line breaks are sanitized',
);
verifySignature(
    !str_contains(MailSignaturePolicy::render($settings, 'A', 'a@example.test', str_repeat('x', 200)), str_repeat('x', 121)),
    'profile role is length bounded',
);
try {
    MailSignaturePolicy::render(new MailProvisionSettings(
        'example.test', 'Members', 'https://mail.example.test/api/v1/',
        'imap.example.test', 993, 'smtp.example.test', 587, 'Example Mail', 512,
        true, '{name}{unknown}', 'Member', 'Org',
    ), 'Alice', 'alice@example.test', null);
    throw new RuntimeException('Unknown placeholder was accepted');
} catch (InvalidArgumentException $e) {
    verifySignature($e->getMessage() !== 'Unknown placeholder was accepted', 'reject unknown placeholder');
}
$rendered = MailSignaturePolicy::render($settings, 'Alice', 'alice@example.test', null);
verifySignature(MailSignaturePolicy::action(null, '', $rendered) === 'skip', 'never backfill manually linked accounts');
verifySignature(MailSignaturePolicy::action('pending', '', $rendered) === 'write', 'pending new connection');
verifySignature(MailSignaturePolicy::action('pending', $rendered, $rendered) === 'ack', 'crash after signature write');
verifySignature(MailSignaturePolicy::action('pending', 'Custom', $rendered) === 'custom', 'preexisting custom signature wins');
verifySignature(
    MailSignaturePolicy::action(MailSignaturePolicy::managedState($rendered), $rendered, $rendered) === 'skip',
    'idempotent signature',
);
verifySignature(
    MailSignaturePolicy::action(MailSignaturePolicy::managedState($rendered), $rendered, $rendered . ' changed') === 'write',
    'template/profile update while still managed',
);
verifySignature(
    MailSignaturePolicy::action(MailSignaturePolicy::managedState($rendered), 'Custom', $rendered) === 'custom',
    'user customization detaches management',
);
verifySignature(MailSignaturePolicy::action('custom', '', $rendered) === 'skip', 'intentional deletion preserved');
verifySignature(MailSignaturePolicy::action('unknown-state', '', $rendered) === 'skip', 'unknown state fails closed');

final class SignatureFakeUser {
    public function __construct(public string $name, public bool $active = true) {}
    public function isEnabled(): bool { return $this->active; }
    public function getDisplayName(): string { return $this->name; }
}
final class SignatureFakeUsers {
    /** @var array<string, SignatureFakeUser> */
    public array $people = [];
    public function get(string $uid): ?SignatureFakeUser { return $this->people[$uid] ?? null; }
}
final class SignatureFakeGroup {
    public function isInGroup(string $uid, string $group): bool { return $group === 'Members'; }
}
final class SignatureFakeApp {
    public function isEnabledForUser(string $app, object $user): bool { return $app === 'mail'; }
}
final class SignatureFakeAccount {
    public function __construct(
        public int $id, public string $uid, public string $email, public ?string $signature = null,
    ) {}
    public function getId(): int { return $this->id; }
    public function getUserId(): string { return $this->uid; }
    public function getEmail(): string { return $this->email; }
    public function getSignature(): ?string { return $this->signature; }
}
final class SignatureFakeAccounts {
    /** @var SignatureFakeAccount[] */
    public array $accounts = [];
    public function getAllAccounts(): array { return $this->accounts; }
}
final class SignatureFakeField {
    public function __construct(public string $key) {}
}
final class SignatureFakeValues {
    /** @var array<string,array<string,mixed>> */
    public array $data = [];
    public function upsert(object $def, string $uid, mixed $value, string $actor, string $visibility): void {
        verifySignature($def->key === 'mail_signature_state', 'only managed field writable');
        verifySignature($actor === 'mail-provision-worker' && $visibility === 'private', 'private provenance');
        $this->data[$uid][$def->key] = $value;
    }
    public function get(string $uid, string $key): mixed { return $this->data[$uid][$key] ?? null; }
}
$users = new SignatureFakeUsers();
$accounts = new SignatureFakeAccounts();
$values = new SignatureFakeValues();
$users->people['alice'] = new SignatureFakeUser('Alice Example');
$users->people['bob'] = new SignatureFakeUser('Bob Worker');
$users->people['manual'] = new SignatureFakeUser('Manual Account');
$accounts->accounts = [
    new SignatureFakeAccount(101, 'alice', 'alice@example.test'),
    new SignatureFakeAccount(102, 'bob', 'bob@example.test'),
    new SignatureFakeAccount(103, 'manual', 'manual@example.test'),
];
$values->data = [
    'alice' => ['mail_signature_state' => 'pending', 'mail_connection_state' => 'managed', 'mail_existing_account_id' => 101],
    'bob' => ['mail_signature_state' => 'pending', 'mail_connection_state' => 'managed', 'mail_existing_account_id' => 102,
        'mail_signature_role' => 'Engineer'],
    'manual' => ['mail_connection_state' => 'manual'],
];
$updates = [];
$failFor = null;
$runner = new MailSignatureReconciler(
    $settings, $users, new SignatureFakeGroup(), new SignatureFakeApp(),
    $accounts, $values, ['mail_signature_state' => new SignatureFakeField('mail_signature_state')],
    static fn (string $uid, string $key): mixed => $values->get($uid, $key),
    static function (int $id, string $uid, string $sig) use (&$updates, &$failFor, $accounts): void {
        if ($failFor === $uid) {
            throw new RuntimeException('Synthetic backend failure');
        }
        foreach ($accounts->accounts as $account) {
            if ($account->id === $id && $account->uid === $uid) {
                $account->signature = $sig;
                $updates[] = [$uid, $id];
                return;
            }
        }
        throw new RuntimeException('Account ownership violated');
    },
);
$first = $runner->apply();
verifySignature($first['updated'] === 2 && $first['failed'] === 0 && count($updates) === 2, 'new managed signatures only');
verifySignature($accounts->accounts[2]->signature === null, 'manual account never touched');
verifySignature($values->get('alice', 'mail_signature_state') === MailSignaturePolicy::managedState(
    $accounts->accounts[0]->signature ?? '',
), 'new signature hash tracked');
$again = $runner->apply();
verifySignature($again['updated'] === 0 && count($updates) === 2, 'no duplicate write');

// User may change their role; only untouched managed signatures follow it.
$values->data['alice']['mail_signature_role'] = 'CTO';
$changed = $runner->apply();
verifySignature($changed['updated'] === 1, 'role triggers signature refresh');
verifySignature(str_contains($accounts->accounts[0]->signature ?? '', 'CTO |'), 'CTO reflected in signature');
verifySignature($values->get('alice', 'mail_signature_role') === 'CTO', 'role remains user-owned');

// User modifies a signature: never overwrite or reinstate it.
$accounts->accounts[0]->signature = 'Independent customized footer';
$values->data['alice']['mail_signature_role'] = 'President';
$custom = $runner->apply();
verifySignature($custom['customized'] === 1, 'manual edit detected');
verifySignature($values->get('alice', 'mail_signature_state') === 'custom', 'custom edit persistently detached');
$runner->apply();
verifySignature($accounts->accounts[0]->signature === 'Independent customized footer', 'custom footer survives later cycles');

// Failure must leave pending/managed state to retry; no false success.
$accounts->accounts[] = new SignatureFakeAccount(104, 'failed', 'failed@example.test');
$users->people['failed'] = new SignatureFakeUser('Failed User');
$values->data['failed'] = ['mail_signature_state' => 'pending',
    'mail_connection_state' => 'managed', 'mail_existing_account_id' => 104];
$failFor = 'failed';
$failed = $runner->apply();
verifySignature($failed['failed'] === 1, 'update error is reported');
verifySignature($values->get('failed', 'mail_signature_state') === 'pending', 'retry marker retained');
$failFor = null;
$retry = $runner->apply();
verifySignature($retry['updated'] === 1 && $retry['failed'] === 0, 'eventual retry');
// Pending marker without completed ownership bookkeeping does not attach
// to a manually configured mailbox after an interrupted setup.
$accounts->accounts[] = new SignatureFakeAccount(105, 'unclaimed', 'unclaimed@example.test');
$users->people['unclaimed'] = new SignatureFakeUser('Unclaimed');
$values->data['unclaimed'] = ['mail_signature_state' => 'pending'];
$priorUpdates = count($updates);
$runner->apply();
verifySignature(count($updates) === $priorUpdates, 'cannot adopt a manual mailbox via stale pending intent');
verifySignature($accounts->accounts[4]->signature === null, 'unclaimed mailbox preserved');
$accounts->accounts[3]->signature = 'Cleared intentionally';
$users->people['failed']->active = false;
$runner->apply();
verifySignature($accounts->accounts[3]->signature === 'Cleared intentionally', 'disabled user stays unchanged');

echo "SIGNATURE_POLICY_AND_INTEGRATION_PASS: defaults, roles, identity, new-only, custom, retry, idempotence, disabled\n";
