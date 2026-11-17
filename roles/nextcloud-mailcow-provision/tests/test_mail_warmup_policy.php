<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure policy tests; no Nextcloud, IMAP, user data or network dependency.
 */
declare(strict_types=1);
define('MAIL_WARMUP_POLICY_ONLY', true);
require __DIR__ . '/../files/nextcloud-mail-warmup.php';

function warmupExpect(bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

warmupExpect(MailWarmupPolicy::folderRole('INBOX', '') === 'inbox', 'Inbox role');
warmupExpect(MailWarmupPolicy::folderRole('inbox', '\\Junk') === 'inbox', 'Inbox name wins');
warmupExpect(MailWarmupPolicy::folderRole('Sent Items', '\\Sent') === 'sent', 'Sent role');
warmupExpect(MailWarmupPolicy::folderRole('archive', '\\Archive') === null, 'No unwanted folder');
warmupExpect(MailWarmupPolicy::folderRole('Sent', '') === null, 'Unflagged Sent is not trusted');
warmupExpect(MailWarmupPolicy::eligible(true, true, true), 'Eligible user');
foreach ([[false, true, true], [true, false, true], [true, true, false]] as $case) {
    warmupExpect(!MailWarmupPolicy::eligible(...$case), 'Disabled/ineligible user cannot warm');
}

$fingerprint = MailWarmupPolicy::fingerprint(23, 'cooperado', 'user@example.test');
warmupExpect((bool)preg_match('/^[a-f0-9]{64}$/D', $fingerprint), 'Fingerprint bounded');
warmupExpect(!str_contains($fingerprint, 'cooperado'), 'No UID leaked');
warmupExpect($fingerprint === MailWarmupPolicy::fingerprint(23, 'cooperado', 'USER@EXAMPLE.TEST'), 'Domain case-insensitive');
warmupExpect($fingerprint !== MailWarmupPolicy::fingerprint(23, 'other', 'user@example.test'), 'Different owner');
warmupExpect($fingerprint !== MailWarmupPolicy::fingerprint(24, 'cooperado', 'user@example.test'), 'Recreated account is different');

warmupExpect(MailWarmupPolicy::jobAccount('{"accountId":23}') === 23, 'Native job argument');
foreach (['{"accountId":"23"}', '{"accountId":0}', '{"accountId":-3}', 'null', '[]', '{}', 'malformed', '{"accountId":1.2}'] as $bad) {
    warmupExpect(MailWarmupPolicy::jobAccount($bad) === null, 'Reject unsafe job account');
}
$valid = [true, true, true, 0, false, false, false, 120, 121];
warmupExpect(MailWarmupPolicy::ready(...$valid), 'Warmup complete only after all checks');
foreach ($valid as $idx => $value) {
    $broken = $valid;
    if ($idx === 0 || $idx === 1 || $idx === 2) {
        $broken[$idx] = false;
    } elseif ($idx === 3) {
        $broken[$idx] = 1;
    } elseif ($idx < 7) {
        $broken[$idx] = true;
    } else {
        $broken[$idx] = 0;
    }
    warmupExpect(!MailWarmupPolicy::ready(...$broken), 'Readiness invariant #' . $idx);
}

echo "Mail warmup policy checks passed: eligibility, fingerprint, jobs, preview, locks, all readiness guards\n";
