<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
define('MAIL_PROVISION_POLICY_ONLY', true);
require __DIR__ . '/../files/nextcloud-mailcow-reconcile.php';

$settings = new MailProvisionSettings(
    'tenant.test', 'Staff', 'https://mail.tenant.test/api/v1/',
    'imap.tenant.test', 993, 'smtp.tenant.test', 587, 'Example Tenant', 1024,
);
$count = 0;
function check(string $expected, array $user, array $existing = [], array $owners = [], ?array $claims = null): void {
    global $count, $settings;
    $box = MailReconcilePolicy::claimedMailbox($user['mapped_mailbox'], $user['primary_email'], $settings->domain, $user['uid']);
    $claimants = $claims ?? ($box === null ? [] : [$box => [$user['uid']]]);
    $actual = MailReconcilePolicy::decide($user, $existing, $owners, $claimants, $settings->domain)['action'];
    if ($actual !== $expected) {
        throw new RuntimeException("Expected {$expected}, got {$actual}");
    }
    $count++;
}

$user = [
    'uid' => 'alice', 'active' => true, 'group_member' => true,
    'mail_access' => true, 'primary_email' => 'alice@example.com',
    'mapped_mailbox' => 'alice@tenant.test', 'mapping_valid' => true,
    'historical_account' => false,
];
check('candidate', $user);
check('preserve_existing', $user, [['id' => 91001]]);
check('review_stale_account_metadata', array_replace($user, ['historical_account' => true]));
check('preserve_existing', array_replace($user, ['historical_account' => true]), [['id' => 91001]]);
check('review_mailbox_already_used', $user, [], ['alice@tenant.test' => ['another']]);
check('review_ambiguous_identity', $user, [], [], ['alice@tenant.test' => ['alice', 'bob']]);
check('review_ambiguous_identity', $user, [], [], ['alice@tenant.test' => ['bob']]);
check('review_ambiguous_identity', $user, [], [], []);
check('review_invalid_mapping', array_replace($user, ['mapping_valid' => false]));
check('skip_disabled', array_replace($user, ['active' => false]));
check('skip_not_eligible', array_replace($user, ['group_member' => false]));
check('skip_not_eligible', array_replace($user, ['mail_access' => false]));
check('review_noncorporate_mailbox', array_replace($user, ['mapped_mailbox' => 'alice@evil.example']));
check('review_invalid_mapping', array_replace($user, ['mapped_mailbox' => "alice@tenant.test\r\n"]));
check('candidate', array_replace($user, ['mapped_mailbox' => null]));
check('candidate', array_replace($user, ['mapped_mailbox' => null, 'primary_email' => 'alice@tenant.test']));
check('candidate', array_replace($user, ['mapped_mailbox' => null, 'primary_email' => 'ALICE@TENANT.TEST']));
check('preserve_existing', array_replace($user, ['mapped_mailbox' => null]), [['id' => 91002]]);
check('skip_disabled', array_replace($user, ['active' => false]), [['id' => 91003]]);
check('review_noncorporate_mailbox', array_replace($user, ['mapped_mailbox' => 'foo+bar@tenant.test,evil@example.com']));
check('candidate', array_replace($user, ['mapped_mailbox' => 'Alice@Tenant.Test']));
// A successful first pass must make the second pass a preservation, never
// another creation. Exercise the decision with changed current-state input.
$state = [];
check('candidate', $user, $state);
$state[] = ['id' => 91004, 'email' => 'alice@tenant.test'];
check('preserve_existing', $user, $state);
if (MailReconcilePolicy::claimedMailbox(null, 'bob@gmail.com', $settings->domain) !== null) {
    throw new RuntimeException('Gmail must not be assumed to be a corporate mailbox');
}
$count++;
// Automatic creation is limited to canonical UIDs and must not adopt
// an unrelated mailbox through a mutable profile email.
$auto = array_replace($user, ['mapped_mailbox' => null]);
check('candidate', $auto);
check('review_legacy_requires_mapping', array_replace($auto, ['legacy_user' => true]));
check('review_missing_mapping', array_replace($auto, ['uid' => 'ALICE']));
check('review_missing_mapping', array_replace($auto, ['uid' => 'admin']));
check('review_missing_mapping', array_replace($auto, ['uid' => 'postmaster']));
check('review_missing_mapping', array_replace($auto, ['primary_email' => 'other@tenant.test']));
check('review_ambiguous_identity', $auto, [], [], ['alice@tenant.test' => ['alice', 'bob']]);
if (MailReconcilePolicy::autoMailbox('alice', '', $settings->domain) !== 'alice@tenant.test') {
    throw new RuntimeException('Deterministic corporate address failed');
}
$count++;
if (MailReconcilePolicy::autoMailbox('ALICE', '', $settings->domain) !== null) {
    throw new RuntimeException('Unsafe uppercase UID accepted');
}
$count++;
$staged = array_replace($user, ['auto_staged' => true]);
if (MailReconcilePolicy::decide($staged, [], [], ['alice@tenant.test' => ['alice']], $settings->domain)['mode'] !== 'staged') {
    throw new RuntimeException('Staged mailbox was adopted as an approved mapping');
}
$count++;

$people = ['alice' => true, 'bob' => true];
$candidates = ['alice' => 'alice@tenant.test', 'bob' => 'bob@tenant.test'];
foreach ([false, ''] as $unscoped) {
    if (MailReconcilePolicy::limitCandidates($candidates, $people, $unscoped) !== $candidates) {
        throw new RuntimeException('Unscoped invocation changed candidates');
    }
    $count++;
}
if (MailReconcilePolicy::limitCandidates($candidates, $people, 'alice') !== ['alice' => 'alice@tenant.test']) {
    throw new RuntimeException('Test scope allowed another UID to be provisioned');
}
$count++;
if (MailReconcilePolicy::limitCandidates($candidates, $people, 'bob') !== ['bob' => 'bob@tenant.test']) {
    throw new RuntimeException('Test scope missed requested UID');
}
$count++;
foreach (['missing', '../alice', 'alice\n', str_repeat('x', 65), 'ALICE'] as $invalid) {
    try {
        MailReconcilePolicy::limitCandidates($candidates, $people, $invalid);
        throw new RuntimeException('Invalid test scope accepted');
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Invalid test scope accepted') {
            throw $e;
        }
    }
    $count++;
}
echo "{$count} policy checks passed\n";
