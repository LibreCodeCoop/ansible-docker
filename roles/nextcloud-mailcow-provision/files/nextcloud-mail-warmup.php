<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Read-only inspection and narrowly scoped native setup for initial Mail warmup.
 * Run as the Nextcloud PHP user, streamed from stdin. No mailbox content or
 * credential values are ever returned to the host runner.
 */
declare(strict_types=1);

final class MailWarmupPolicy {
    public static function folderRole(string $name, string $specialUse): ?string {
        if (strtoupper($name) === 'INBOX') {
            return 'inbox';
        }
        return str_contains(strtoupper($specialUse), 'SENT') ? 'sent' : null;
    }

    public static function fingerprint(int $accountId, string $userId, string $email): string {
        return hash('sha256', $accountId . "\0" . $userId . "\0" . strtolower($email));
    }

    public static function eligible(bool $userEnabled, bool $member, bool $mailAccess): bool {
        return $userEnabled && $member && $mailAccess;
    }

    public static function ready(
        bool $foldersValid, bool $countsMatch, bool $tokensReady,
        int $pendingPreviews, bool $locked, bool $syncBusy, bool $previewBusy,
        int $syncJob, int $previewJob,
    ): bool {
        return $foldersValid && $countsMatch && $tokensReady
            && $pendingPreviews === 0 && !$locked && !$syncBusy && !$previewBusy
            && $syncJob > 0 && $previewJob > 0;
    }

    public static function jobAccount(string $argument): ?int {
        $value = json_decode($argument, true);
        if (!is_array($value) || !isset($value['accountId'])
            || !is_int($value['accountId']) || $value['accountId'] < 1) {
            return null;
        }
        return $value['accountId'];
    }
}

if (defined('MAIL_WARMUP_POLICY_ONLY')) {
    return;
}

function mailWarmupCount(object $db, int $mailboxId): int {
    $q = $db->getQueryBuilder();
    $q->select($q->func()->count('*', 'count'))
        ->from('mail_messages')
        ->where($q->expr()->eq(
            'mailbox_id', $q->createNamedParameter($mailboxId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
        ));
    $r = $q->executeQuery();
    $n = (int)$r->fetchOne();
    $r->closeCursor();
    return $n;
}

function mailWarmupPendingPreviews(object $db, int $accountId): int {
    $q = $db->getQueryBuilder();
    $q->select($q->func()->count('*', 'count'))
        ->from('mail_messages', 'm')
        ->innerJoin('m', 'mail_mailboxes', 'b', $q->expr()->eq('m.mailbox_id', 'b.id'))
        ->where($q->expr()->eq(
            'b.account_id', $q->createNamedParameter($accountId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
        ))
        ->andWhere($q->expr()->gte(
            'm.sent_at', $q->createNamedParameter(time() - 14 * 86400, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
        ))
        ->andWhere($q->expr()->eq(
            'm.structure_analyzed', $q->createNamedParameter(false, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL),
            \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL,
        ));
    $r = $q->executeQuery();
    $n = (int)$r->fetchOne();
    $r->closeCursor();
    return $n;
}

function mailWarmupMain(): int {
    define('OC_CONSOLE', 1);
    $root = getenv('MAIL_PROVISION_NEXTCLOUD_ROOT');
    if (!is_string($root) || $root === '' || str_contains($root, '..')
        || preg_match('~^/[a-zA-Z0-9_./-]+$~D', $root) !== 1) {
        throw new \InvalidArgumentException('Invalid Nextcloud path');
    }
    $group = getenv('MAIL_WARMUP_GROUP');
    if (!is_string($group) || $group === '' || strlen($group) > 128 || trim($group) !== $group) {
        throw new \InvalidArgumentException('Invalid eligibility group');
    }
    $mode = getenv('MAIL_WARMUP_MODE') ?: 'scan';
    if (!in_array($mode, ['scan', 'inspect', 'privacy', 'ensure-jobs'], true)) {
        throw new \InvalidArgumentException('Invalid warmup mode');
    }
    $target = getenv('MAIL_WARMUP_ACCOUNT_ID') ?: '';
    if ($mode !== 'scan' && (!ctype_digit($target) || (int)$target < 1)) {
        throw new \InvalidArgumentException('Invalid account scope');
    }

    require rtrim($root, '/') . '/lib/base.php';
    $appManager = \OCP\Server::get(\OCP\App\IAppManager::class);
    $appManager->loadApp('mail');
    $users = \OCP\Server::get(\OCP\IUserManager::class);
    $groups = \OCP\Server::get(\OCP\IGroupManager::class);
    $accounts = \OCP\Server::get(\OCA\Mail\Db\MailAccountMapper::class);
    $db = \OCP\Server::get(\OCP\IDBConnection::class);
    $config = \OCP\Server::get(\OCP\IConfig::class);

    $syncClass = \OCA\Mail\BackgroundJob\SyncJob::class;
    $previewClass = \OCA\Mail\BackgroundJob\PreviewEnhancementProcessingJob::class;
    $q = $db->getQueryBuilder();
    $q->select('id', 'class', 'argument', 'reserved_at')->from('jobs');
    $cursor = $q->executeQuery();
    $jobsByAccount = [];
    foreach ($cursor->fetchAllAssociative() as $job) {
        if ($job['class'] !== $syncClass && $job['class'] !== $previewClass) {
            continue;
        }
        $owner = MailWarmupPolicy::jobAccount((string)$job['argument']);
        if ($owner === null) {
            continue;
        }
        $kind = $job['class'] === $syncClass ? 'sync' : 'preview';
        $jobsByAccount[$owner][$kind][] = [
            'id' => (int)$job['id'],
            'reserved' => (int)$job['reserved_at'] > 0,
        ];
    }
    $cursor->closeCursor();

    $list = $accounts->getAllAccounts();
    // Warm newest accounts first if existing accounts still need their baseline pass.
    usort($list, static fn (object $a, object $b): int => $b->getId() <=> $a->getId());
    $matched = false;
    foreach ($list as $account) {
        $id = (int)$account->getId();
        if ($mode !== 'scan' && $id !== (int)$target) {
            continue;
        }
        $uid = $account->getUserId();
        $user = $users->get($uid);
        if ($user === null || !MailWarmupPolicy::eligible(
            $user->isEnabled(), $groups->isInGroup($uid, $group),
            $appManager->isEnabledForUser('mail', $user),
        )) {
            continue;
        }
        $matched = true;
        if ($mode === 'privacy') {
            $config->setUserValue($uid, 'mail', 'always-show-external-images', 'false');
            $config->setUserValue($uid, 'mail', 'external-avatars', 'false');
            echo "privacy_applied\n";
            return 0;
        }
        $syncJobs = $jobsByAccount[$id]['sync'] ?? [];
        $previewJobs = $jobsByAccount[$id]['preview'] ?? [];
        if ($mode === 'ensure-jobs') {
            if (count($syncJobs) > 1 || count($previewJobs) > 1) {
                throw new \RuntimeException('Ambiguous native Mail jobs');
            }
            $jobs = \OCP\Server::get(\OCP\BackgroundJob\IJobList::class);
            if ($syncJobs === []) {
                $jobs->add($syncClass, ['accountId' => $id]);
            }
            if ($previewJobs === []) {
                $jobs->add($previewClass, ['accountId' => $id]);
            }
            echo "jobs_ensured\n";
            return 0;
        }

        $q = $db->getQueryBuilder();
        $q->select('id', 'name', 'special_use', 'messages', 'selectable',
            'sync_new_lock', 'sync_changed_lock', 'sync_vanished_lock',
            'sync_new_token', 'sync_changed_token', 'sync_vanished_token')
            ->from('mail_mailboxes')
            ->where($q->expr()->eq(
                'account_id', $q->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
            ));
        $r = $q->executeQuery();
        $boxes = $r->fetchAllAssociative();
        $r->closeCursor();
        $roles = ['inbox' => 0, 'sent' => 0];
        $counts = true;
        $tokens = true;
        $locked = false;
        foreach ($boxes as $box) {
            if (($box['sync_new_lock'] ?? null) !== null
                || ($box['sync_changed_lock'] ?? null) !== null
                || ($box['sync_vanished_lock'] ?? null) !== null) {
                $locked = true;
            }
            if (!(bool)$box['selectable']) {
                continue;
            }
            $role = MailWarmupPolicy::folderRole((string)$box['name'], (string)($box['special_use'] ?? ''));
            if ($role === null) {
                continue;
            }
            ++$roles[$role];
            if ((int)$box['messages'] !== mailWarmupCount($db, (int)$box['id'])) {
                $counts = false;
            }
            if (($box['sync_new_token'] ?? null) === null
                || ($box['sync_changed_token'] ?? null) === null
                || ($box['sync_vanished_token'] ?? null) === null) {
                $tokens = false;
            }
        }
        $foldersValid = $roles['inbox'] === 1 && $roles['sent'] === 1;
        $pending = mailWarmupPendingPreviews($db, $id);
        $syncId = count($syncJobs) === 1 ? $syncJobs[0]['id'] : 0;
        $previewId = count($previewJobs) === 1 ? $previewJobs[0]['id'] : 0;
        $syncBusy = count(array_filter($syncJobs, static fn (array $j) => $j['reserved'])) > 0;
        $previewBusy = count(array_filter($previewJobs, static fn (array $j) => $j['reserved'])) > 0;
        $ready = MailWarmupPolicy::ready(
            $foldersValid, $counts, $tokens, $pending, $locked, $syncBusy,
            $previewBusy, $syncId, $previewId,
        );
        echo implode('|', [
            $id,
            MailWarmupPolicy::fingerprint($id, $uid, (string)$account->getEmail()),
            (int)$locked, (int)$syncBusy, (int)$previewBusy,
            $syncId, $previewId, (int)$counts, (int)$tokens,
            $pending, (int)$foldersValid, (int)$ready,
        ]) . "\n";
    }
    if ($mode !== 'scan' && !$matched) {
        throw new \RuntimeException('Account missing, disabled, or outside allowed group');
    }
    return 0;
}

try {
    exit(mailWarmupMain());
} catch (\Throwable $e) {
    // Class-only error: never reveal users, accounts, IMAP or database details.
    fwrite(STDERR, 'MAIL_WARMUP_FATAL category=' . get_class($e) . PHP_EOL);
    exit(3);
}
