<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * One-shot, fail-closed Mailcow -> Nextcloud Mail reconciler.
 *
 * Run inside the existing Nextcloud PHP container as www-data, reading this
 * script from stdin. No web endpoint, additional Nextcloud app or secret file.
 * Dry-run by default; MAIL_PROVISION_WRITE_ENABLED=1 permits account creation.
 */

declare(strict_types=1);

/**
 * Non-secret, per-instance configuration. No organization, hostname or group
 * is assumed by the reusable PHP component. Missing variables fail closed.
 */
final class MailProvisionSettings {
    public function __construct(
        public readonly string $domain,
        public readonly string $group,
        public readonly string $apiUrl,
        public readonly string $imapHost,
        public readonly int $imapPort,
        public readonly string $smtpHost,
        public readonly int $smtpPort,
        public readonly string $accountName,
        public readonly int $quotaMiB,
        public readonly bool $signatureEnabled = false,
        public readonly string $signatureTemplate = '',
        public readonly string $signatureDefaultRole = '',
        public readonly string $signatureOrganization = '',
        public readonly string $signatureWebsite = '',
    ) {
        if (strlen($domain) > 253 || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $domain) !== 1) {
            throw new \InvalidArgumentException('Invalid mailbox domain');
        }
        if ($group === '' || strlen($group) > 128 || $group !== trim($group)
            || $accountName === '' || strlen($accountName) > 128) {
            throw new \InvalidArgumentException('Invalid group or account label');
        }
        $parsed = parse_url($apiUrl);
        if (!is_array($parsed) || ($parsed['scheme'] ?? null) !== 'https'
            || !isset($parsed['host']) || ($parsed['path'] ?? '') !== '/api/v1/'
            || isset($parsed['user']) || isset($parsed['pass']) || isset($parsed['query'])
            || isset($parsed['fragment']) || strpbrk($apiUrl, "\r\n\t\\") !== false) {
            throw new \InvalidArgumentException('Mailcow API must use a dedicated HTTPS origin and /api/v1/ path');
        }
        foreach ([$imapHost, $smtpHost] as $host) {
            if ($host === '' || strlen($host) > 253 || !preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/D', $host)) {
                throw new \InvalidArgumentException('Invalid mail service host');
            }
        }
        if ($imapPort < 1 || $imapPort > 65535 || $smtpPort < 1 || $smtpPort > 65535
            || $quotaMiB < 128 || $quotaMiB > 1048576) {
            throw new \InvalidArgumentException('Invalid mail ports or quota');
        }
        if ($signatureEnabled && (
            trim($signatureTemplate) === '' || strlen($signatureTemplate) > 4096
            || trim($signatureDefaultRole) === '' || strlen($signatureDefaultRole) > 120
            || trim($signatureOrganization) === '' || strlen($signatureOrganization) > 180
            || strlen($signatureWebsite) > 255
        )) {
            throw new \InvalidArgumentException('Invalid signature template or defaults');
        }
    }

    private static function env(string $name): string {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException('Required mail provisioning setting missing: ' . $name);
        }
        return $value;
    }

    public static function fromEnvironment(): self {
        return new self(
            self::env('MAIL_PROVISION_DOMAIN'),
            self::env('MAIL_PROVISION_GROUP'),
            self::env('MAIL_PROVISION_API_URL'),
            self::env('MAIL_PROVISION_IMAP_HOST'),
            (int)self::env('MAIL_PROVISION_IMAP_PORT'),
            self::env('MAIL_PROVISION_SMTP_HOST'),
            (int)self::env('MAIL_PROVISION_SMTP_PORT'),
            self::env('MAIL_PROVISION_ACCOUNT_NAME'),
            (int)self::env('MAIL_PROVISION_QUOTA_MIB'),
            getenv('MAIL_PROVISION_SIGNATURE_ENABLED') === '1',
            (string)(getenv('MAIL_PROVISION_SIGNATURE_TEMPLATE') ?: ''),
            (string)(getenv('MAIL_PROVISION_SIGNATURE_DEFAULT_ROLE') ?: ''),
            (string)(getenv('MAIL_PROVISION_SIGNATURE_ORGANIZATION') ?: ''),
            (string)(getenv('MAIL_PROVISION_SIGNATURE_WEBSITE') ?: ''),
        );
    }
}

/**
 * Pure policy. Keep all eligibility and duplicate checks testable without
 * bootstrapping Nextcloud or connecting to a mailbox.
 */
final class MailReconcilePolicy {

    /**
     * New accounts have a deterministic corporate address, but only for
     * canonical lowercase Nextcloud IDs. Never take an unverified existing
     * corporate mailbox merely because it matches an editable profile email.
     */
    public static function autoMailbox(string $uid, string $primaryEmail, string $domain): ?string {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $uid) !== 1
            || in_array($uid, [
                'admin', 'root', 'postmaster', 'abuse', 'hostmaster',
                'webmaster', 'security', 'support', 'contato', 'financeiro',
                'noreply', 'no-reply', 'mailer-daemon', 'mail',
            ], true)) {
            return null;
        }
        $mailbox = $uid . '@' . $domain;
        $primary = strtolower(trim($primaryEmail));
        if ($primary !== '' && str_ends_with($primary, '@' . $domain) && $primary !== $mailbox) {
            return null;
        }
        return $mailbox;
    }

    /** @return array{action: string, mailbox?: string, mode?: string} */
    public static function decide(
        array $user,
        array $existingAccounts,
        array $mailboxOwners,
        array $claimants,
        string $domain,
    ): array {
        if (!$user['active']) {
            return ['action' => 'skip_disabled'];
        }
        if (!$user['group_member'] || !$user['mail_access']) {
            return ['action' => 'skip_not_eligible'];
        }
        if ($existingAccounts !== []) {
            return ['action' => 'preserve_existing'];
        }
        if ($user['historical_account']) {
            return ['action' => 'review_stale_account_metadata'];
        }

        $mapped = $user['mapped_mailbox'];
        $auto = $mapped === null || $mapped === '';
        if ($auto) {
            if ($user['legacy_user'] ?? false) {
                return ['action' => 'review_legacy_requires_mapping'];
            }
            $mapped = self::autoMailbox($user['uid'], $user['primary_email'], $domain);
            if ($mapped === null) {
                return ['action' => 'review_missing_mapping'];
            }
        } elseif (!$user['mapping_valid'] || $mapped !== trim($mapped)) {
            return ['action' => 'review_invalid_mapping'];
        }

        $mailbox = strtolower($mapped);
        if (preg_match('/^[a-z0-9][a-z0-9._+-]*@' . preg_quote($domain, '/') . '$/D', $mailbox) !== 1) {
            return ['action' => 'review_noncorporate_mailbox'];
        }
        if (($claimants[$mailbox] ?? []) !== [$user['uid']]) {
            return ['action' => 'review_ambiguous_identity'];
        }
        if (isset($mailboxOwners[$mailbox])) {
            return ['action' => 'review_mailbox_already_used'];
        }

        $mode = $auto ? 'auto' : ($user['auto_staged'] ?? false ? 'staged' : 'mapped');
        return ['action' => 'candidate', 'mailbox' => $mailbox, 'mode' => $mode];
    }

    /** @return array<string, array{mailbox: string, mode: string}> */
    public static function limitCandidates(array $candidates, array $people, mixed $onlyUid): array {
        if ($onlyUid === false || $onlyUid === '') {
            return $candidates;
        }
        if (
            !is_string($onlyUid)
            || preg_match('/^[a-zA-Z0-9._-]{1,64}$/D', $onlyUid) !== 1
            || !array_key_exists($onlyUid, $people)
        ) {
            throw new \RuntimeException('Requested user scope is invalid or unknown');
        }
        return array_intersect_key($candidates, [$onlyUid => true]);
    }

    /**
     * Provenance for auto-created mailboxes, stored in a hidden, admin-only
     * Profile Fields value before calling the Mailcow create endpoint.
     */
    public static function intent(string $uid, string $mailbox): string {
        return 'auto-nextcloud-v1:' . hash('sha256', $uid . '|' . $mailbox);
    }

    /** @return array{0:string,1:string,2:string,3:int,4:string,5:string,6:string,7:string,8:int,9:string,10:string,11:string,12:string,13:string} */
    public static function setupArguments(string $uid, string $email, string $secret, MailProvisionSettings $settings): array {
        if ($secret === '') {
            throw new \InvalidArgumentException('Empty Mailcow app credential');
        }
        return [
            $settings->accountName, $email,
            $settings->imapHost, $settings->imapPort, 'ssl', $email, $secret,
            $settings->smtpHost, $settings->smtpPort, 'tls', $email, $secret,
            $uid, 'password',
        ];
    }

    public static function claimedMailbox(?string $mapping, string $primaryEmail, string $domain, string $uid = ''): ?string {
        $candidate = strtolower(trim(($mapping !== null && $mapping !== '') ? $mapping : $primaryEmail));
        if (str_ends_with($candidate, '@' . $domain)) {
            return $candidate;
        }
        return $uid === '' ? null : self::autoMailbox($uid, $primaryEmail, $domain);
    }
}

/**
 * Signature state belongs to the provisioned account, not to the Mailcow
 * mailbox. A user edit permanently opts out of automatic signature updates.
 */
final class MailSignaturePolicy {
    public static function clean(string $value, int $limit = 180): string {
        $value = trim((string)preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        // Guard against unbounded, user-controlled profile fields.
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($characters)) {
            return '';
        }
        if (count($characters) > $limit) {
            $value = implode('', array_slice($characters, 0, $limit));
        }
        return $value;
    }

    public static function render(MailProvisionSettings $settings, string $name, string $email, ?string $role): string {
        $vars = [
            '{name}' => self::clean($name) ?: self::clean($email),
            '{email}' => self::clean($email),
            '{role}' => self::clean($role ?? '', 120) ?: self::clean($settings->signatureDefaultRole, 120),
            '{organization}' => self::clean($settings->signatureOrganization),
            '{website}' => self::clean($settings->signatureWebsite),
        ];
        $unknown = preg_replace('/\{(?:name|email|role|organization|website)\}/', '', $settings->signatureTemplate);
        if (preg_match('/\{[^{}\r\n]+\}/', $unknown ?? '') === 1) {
            throw new \InvalidArgumentException('Signature template contains unsupported placeholders');
        }
        return trim(strtr($settings->signatureTemplate, $vars));
    }

    /** @return 'skip'|'write'|'ack'|'custom' */
    public static function action(?string $state, ?string $current, string $desired): string {
        if ($state === null || $state === '' || $state === 'custom') {
            return 'skip';
        }
        $current = $current ?? '';
        if ($state === 'pending') {
            if ($current === '') {
                return 'write';
            }
            return hash_equals(hash('sha256', $desired), hash('sha256', $current))
                ? 'ack' : 'custom';
        }
        if (!preg_match('/^managed:[0-9a-f]{64}$/D', $state)) {
            return 'skip';
        }
        if (!hash_equals(substr($state, 8), hash('sha256', $current))) {
            return 'custom';
        }
        return hash_equals(hash('sha256', $current), hash('sha256', $desired))
            ? 'skip' : 'write';
    }

    public static function managedState(string $signature): string {
        return 'managed:' . hash('sha256', $signature);
    }
}

/**
 * Mailcow administrator API. All targets are constant, TLS verified and
 * credentials are supplied only at runtime; API response bodies are never
 * included in exceptions or logs.
 */
final class MailcowApiClient {
    public function __construct(
        private string $key,
        private MailProvisionSettings $settings,
        private ?\Closure $fakeTransport = null,
    ) {
        if ($key === '') {
            throw new \RuntimeException('Mailcow API credential missing');
        }
    }

    public function request(string $method, string $path, ?array $payload = null): mixed {
        $mailboxPath = '[a-z0-9][a-z0-9._+-]*@' . preg_quote($this->settings->domain, '~');
        if (!in_array($method, ['GET', 'POST'], true)
            || preg_match('~^(?:get/mailbox/' . $mailboxPath
                . '|get/app-passwd/all/' . $mailboxPath
                . '|add/mailbox|add/app-passwd|delete/app-passwd)$~D', $path) !== 1) {
            throw new \InvalidArgumentException('Unsupported Mailcow operation');
        }
        if ($this->fakeTransport !== null) {
            return ($this->fakeTransport)($method, $path, $payload);
        }
        $curl = curl_init($this->settings->apiUrl . $path);
        if ($curl === false) {
            throw new \RuntimeException('Mailcow API transport unavailable');
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            (defined('CURLOPT_PROTOCOLS_STR') ? CURLOPT_PROTOCOLS_STR : CURLOPT_PROTOCOLS)
                => (defined('CURLOPT_PROTOCOLS_STR') ? 'https' : CURLPROTO_HTTPS),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['X-API-Key: ' . $this->key, 'Accept: application/json', 'Content-Type: application/json'],
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
        }
        curl_setopt_array($curl, $options);
        try {
            $body = curl_exec($curl);
            $code = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        } finally {
            // CurlHandle is released when it goes out of scope; curl_close()
            // is deprecated in PHP 8.5 and has been a no-op since PHP 8.
        }
        if (!is_string($body) || strlen($body) > 1048576 || !in_array($code, [200, 201, 404], true)) {
            throw new \RuntimeException('Mailcow API unavailable or unauthorized');
        }
        if ($code === 404) {
            return null;
        }
        try {
            return json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Mailcow API returned invalid JSON');
        }
    }

    public function mailbox(string $email): ?array {
        $response = $this->request('GET', 'get/mailbox/' . $email);
        if ($response === null || $response === []) {
            return null;
        }
        // A present mailbox is returned as a single JSON object by the
        // Mailcow API. Some older installations return a singleton list.
        // Only accept an exact username match, never a cross-mailbox result.
        if (is_array($response) && array_is_list($response)) {
            if (count($response) !== 1 || !is_array($response[0])) {
                throw new \RuntimeException('Mailcow mailbox response ambiguous');
            }
            $response = $response[0];
        }
        if (!is_array($response)
            || strtolower((string)($response['username'] ?? '')) !== $email) {
            throw new \RuntimeException('Mailcow mailbox response ambiguous');
        }
        return $response;
    }

    public function appPasswords(string $email): array {
        $data = $this->request('GET', 'get/app-passwd/all/' . $email);
        if (!is_array($data) || !array_is_list($data)) {
            throw new \RuntimeException('Mailcow app password response invalid');
        }
        foreach ($data as $item) {
            if (!is_array($item)) {
                throw new \RuntimeException('Mailcow app password response invalid');
            }
        }
        return $data;
    }

    public function createMailbox(string $email, string $secret, int $quotaMiB): void {
        [$local] = explode('@', $email, 2);
        $this->success($this->request('POST', 'add/mailbox', [
            'active' => '1', 'domain' => $this->settings->domain, 'local_part' => $local,
            'name' => $local, 'password' => $secret, 'password2' => $secret,
            'quota' => (string)$quotaMiB, 'force_pw_update' => '1',
            'tls_enforce_in' => '1', 'tls_enforce_out' => '1',
        ]));
    }

    public function createAppPassword(string $email, string $name, string $secret): void {
        $this->success($this->request('POST', 'add/app-passwd', [
            'active' => '1', 'username' => $email, 'app_name' => $name,
            'app_passwd' => $secret, 'app_passwd2' => $secret,
            'protocols' => ['imap_access', 'smtp_access'],
        ]));
    }

    public function deleteAppPassword(int $id): void {
        if ($id < 1) {
            throw new \InvalidArgumentException('Invalid app password identifier');
        }
        $this->success($this->request('POST', 'delete/app-passwd', [(string)$id]));
    }

    private function success(mixed $response): void {
        if (!is_array($response) || !isset($response[0]) || !is_array($response[0])
            || ($response[0]['type'] ?? null) !== 'success') {
            throw new \RuntimeException('Mailcow rejected requested operation');
        }
    }
}

/**
 * One bounded reconciliation attempt. Dependencies are injected so that
 * mailbox creation, individual credentials, Mail account persistence and
 * partial failures can be tested without a live Nextcloud/Mailcow.
 */
final class MailProvisionExecutor {
    public function __construct(
        private MailProvisionSettings $settings,
        private MailcowApiClient $mailcow,
        private object $users,
        private object $groups,
        private object $appManager,
        private object $accounts,
        private object $values,
        private array $fields,
        private \Closure $readField,
        private \Closure $setupAccount,
    ) {}

    private function field(string $uid, string $name): mixed {
        return ($this->readField)($uid, $name);
    }

    public function apply(array $candidates, array &$outcomes): void {
        $attempted = 0;
        foreach ($candidates as $uid => $candidate) {
            if ($attempted >= 5) {
                $outcomes[$uid] = 'deferred_batch_limit';
                continue;
            }
            // Rechecking state and calling external services count towards the
            // batch cap even when the mailbox is found to be preexisting.
            ++$attempted;
            try {
                $outcomes[$uid] = $this->processOne($uid, $candidate);
            } catch (\Throwable) {
                // Do not log user identifiers, mailbox details or credentials.
                $outcomes[$uid] = 'error_connection_or_creation';
            }
        }
    }

    /** @return array{person:object,auto:bool,staged:bool,evidence:mixed}|string */
    private function currentState(string $uid, string $mailbox, string $mode): array|string {
        $person = $this->users->get($uid);
        if ($person === null || !$person->isEnabled()
            || !$this->groups->isInGroup($uid, $this->settings->group)
            || !$this->appManager->isEnabledForUser('mail', $person)
            || $this->accounts->findByUserId($uid) !== []) {
            return 'review_state_changed';
        }
        if (in_array($this->field($uid, 'mail_connection_state'), ['manual', 'managed'], true)
            || $this->field($uid, 'mail_existing_account_id') !== null) {
            return 'review_stale_account_metadata';
        }
        $mapping = $this->field($uid, 'mailcow_mailbox');
        $evidence = $this->field($uid, 'mail_mapping_evidence_ref');
        $auto = $mode === 'auto';
        $staged = $mode === 'staged';
        if ($auto) {
            if ($mapping !== null && $mapping !== ''
                || MailReconcilePolicy::autoMailbox(
                    $uid, (string)($person->getEMailAddress() ?? ''), $this->settings->domain,
                ) !== $mailbox) {
                return 'review_mapping_changed';
            }
        } elseif (!is_string($mapping) || strtolower($mapping) !== $mailbox
            || $mapping !== trim($mapping)
            || ($staged
                ? $evidence !== MailReconcilePolicy::intent($uid, $mailbox)
                : ($this->field($uid, 'mail_mailbox_kind') !== 'personal_primary'
                    || $this->field($uid, 'mailcow_login_enabled') !== true
                    || $this->field($uid, 'mail_match_unique') !== true
                    || !is_string($evidence) || trim($evidence) === ''))) {
            return 'review_mapping_changed';
        }
        return ['person' => $person, 'auto' => $auto, 'staged' => $staged, 'evidence' => $evidence];
    }

    private function identityAvailable(string $uid, string $mailbox, bool $auto): ?string {
        $claims = $this->values->findByDefinitionAndRawValue($this->fields['mailcow_mailbox'], $mailbox);
        if (($auto && $claims !== []) || (!$auto
            && (count($claims) !== 1 || $claims[0]->getUserUid() !== $uid))) {
            return 'review_ambiguous_identity';
        }
        foreach ($this->accounts->getAllAccounts() as $account) {
            if (strtolower((string)$account->getEmail()) === $mailbox) {
                return 'review_mailbox_already_used';
            }
        }
        return null;
    }

    private function ensureMailbox(string $uid, string $mailbox, bool $auto, bool $staged): ?string {
        $current = $this->mailcow->mailbox($mailbox);
        if ($auto) {
            if ($current !== null) {
                return 'review_existing_mailbox_unverified';
            }
            // Stage ownership evidence before external creation. If the worker
            // crashes, the next run refuses automatic adoption of the mailbox.
            $this->values->upsert($this->fields['mailcow_mailbox'], $uid, $mailbox, 'mail-provision-worker', 'private');
            $this->values->upsert(
                $this->fields['mail_mapping_evidence_ref'], $uid,
                MailReconcilePolicy::intent($uid, $mailbox), 'mail-provision-worker', 'private',
            );
            $initialSecret = 'Aa1!' . bin2hex(random_bytes(24));
            try {
                $this->mailcow->createMailbox($mailbox, $initialSecret, $this->settings->quotaMiB);
            } finally {
                unset($initialSecret);
            }
            $current = $this->mailcow->mailbox($mailbox);
        }
        if ($current === null || (string)($current['active'] ?? '0') !== '1') {
            return 'review_mailbox_missing_or_disabled';
        }
        return $staged ? 'review_staged_mailbox_provenance' : null;
    }

    /** @return array{password:string,id:int}|string */
    private function createIndividualCredential(string $uid, string $mailbox): array|string {
        $label = 'nextcloud-' . substr(hash('sha256', $uid . '|' . $mailbox), 0, 20);
        foreach ($this->mailcow->appPasswords($mailbox) as $entry) {
            if (($entry['name'] ?? null) === $label) {
                return 'review_existing_managed_password';
            }
        }
        $password = 'Aa1!' . bin2hex(random_bytes(24));
        $this->mailcow->createAppPassword($mailbox, $label, $password);
        $createdId = null;
        foreach ($this->mailcow->appPasswords($mailbox) as $entry) {
            if (($entry['name'] ?? null) === $label && is_numeric($entry['id'] ?? null)) {
                if ($createdId !== null) {
                    throw new \RuntimeException('Managed app credential ambiguous');
                }
                $createdId = (int)$entry['id'];
            }
        }
        if ($createdId === null || $createdId < 1) {
            throw new \RuntimeException('Managed app credential not identifiable');
        }
        return ['password' => $password, 'id' => $createdId];
    }

    private function stillEligible(
        string $uid, string $mailbox, bool $auto, bool $staged, mixed $originalEvidence, object $person,
    ): bool {
        if ($this->accounts->findByUserId($uid) !== []
            || !$this->groups->isInGroup($uid, $this->settings->group)
            || !$person->isEnabled() || !$this->appManager->isEnabledForUser('mail', $person)
            || strtolower((string)$this->field($uid, 'mailcow_mailbox')) !== $mailbox) {
            return false;
        }
        if ($auto) {
            return $this->field($uid, 'mail_mapping_evidence_ref') === MailReconcilePolicy::intent($uid, $mailbox);
        }
        return $staged || (
            $this->field($uid, 'mail_mailbox_kind') === 'personal_primary'
            && $this->field($uid, 'mailcow_login_enabled') === true
            && $this->field($uid, 'mail_match_unique') === true
            && $this->field($uid, 'mail_mapping_evidence_ref') === $originalEvidence
        );
    }

    private function connectUser(
        string $uid, string $mailbox, array $credential,
        bool $auto, bool $staged, mixed $evidence, object $person,
    ): string {
        $password = $credential['password'];
        try {
            if (!$this->stillEligible($uid, $mailbox, $auto, $staged, $evidence, $person)) {
                throw new \RuntimeException('User or mapping changed');
            }
            if ($this->settings->signatureEnabled) {
                // Durable intent before account creation: if the worker dies,
                // the following run can finish just this signature safely.
                $this->values->upsert(
                    $this->fields['mail_signature_state'], $uid, 'pending',
                    'mail-provision-worker', 'private',
                );
            }
            ($this->setupAccount)(...MailReconcilePolicy::setupArguments($uid, $mailbox, $password, $this->settings));
        } catch (\Throwable) {
            // Revoke only the credential we just created and only when no
            // account was saved; keep it if account persistence was partial.
            if ($this->accounts->findByUserId($uid) === []) {
                $this->mailcow->deleteAppPassword($credential['id']);
            }
            throw new \RuntimeException('Nextcloud Mail account setup failed');
        } finally {
            unset($password);
        }
        $linked = $this->accounts->findByUserId($uid);
        if (count($linked) !== 1 || strtolower((string)$linked[0]->getEmail()) !== $mailbox) {
            throw new \RuntimeException('Mail account postcondition failed');
        }
        return $this->persistMetadata($uid, $linked[0]->getId(), $auto);
    }

    private function persistMetadata(string $uid, int $accountId, bool $auto): string {
        try {
            if ($auto) {
                $this->values->upsert($this->fields['mail_mailbox_kind'], $uid, 'personal_primary', 'mail-provision-worker', 'private');
                $this->values->upsert($this->fields['mailcow_login_enabled'], $uid, true, 'mail-provision-worker', 'private');
                $this->values->upsert($this->fields['mail_match_unique'], $uid, true, 'mail-provision-worker', 'private');
            }
            $this->values->upsert($this->fields['mail_connection_state'], $uid, 'managed', 'mail-provision-worker', 'private');
            $this->values->upsert($this->fields['mail_existing_account_id'], $uid, $accountId, 'mail-provision-worker', 'private');
            return 'created';
        } catch (\Throwable) {
            return 'created_metadata_review';
        }
    }

    private function processOne(string $uid, array $candidate): string {
        $mailbox = $candidate['mailbox'];
        $state = $this->currentState($uid, $mailbox, $candidate['mode']);
        if (is_string($state)) {
            return $state;
        }
        $collision = $this->identityAvailable($uid, $mailbox, $state['auto']);
        if ($collision !== null) {
            return $collision;
        }
        $status = $this->ensureMailbox($uid, $mailbox, $state['auto'], $state['staged']);
        if ($status !== null) {
            return $status;
        }
        $credential = $this->createIndividualCredential($uid, $mailbox);
        if (is_string($credential)) {
            return $credential;
        }
        return $this->connectUser(
            $uid, $mailbox, $credential, $state['auto'], $state['staged'], $state['evidence'], $state['person'],
        );
    }
}

/**
 * Operates on Mail accounts only when a private, admin-managed intent exists.
 * Other accounts, including manually connected accounts, are never changed.
 */
final class MailSignatureReconciler {
    public function __construct(
        private MailProvisionSettings $settings,
        private object $users,
        private object $groups,
        private object $appManager,
        private object $accounts,
        private object $values,
        private array $fields,
        private \Closure $readField,
        private \Closure $updateSignature,
    ) {}

    /** @return array{updated:int,acknowledged:int,customized:int,failed:int} */
    public function apply(): array {
        $totals = ['updated' => 0, 'acknowledged' => 0, 'customized' => 0, 'failed' => 0];
        if (!$this->settings->signatureEnabled) {
            return $totals;
        }
        foreach ($this->accounts->getAllAccounts() as $account) {
            $uid = $account->getUserId();
            $state = ($this->readField)($uid, 'mail_signature_state');
            if (!is_string($state) || $state === '' || $state === 'custom') {
                continue;
            }
            $person = $this->users->get($uid);
            if ($person === null || !$person->isEnabled()
                || !$this->groups->isInGroup($uid, $this->settings->group)
                || !$this->appManager->isEnabledForUser('mail', $person)) {
                continue;
            }
            // The pending intent alone must never claim a manually connected
            // mailbox after a partial setup failure. The original provisioning
            // workflow must have completed its admin-only ownership mapping.
            $linkedId = ($this->readField)($uid, 'mail_existing_account_id');
            if (($this->readField)($uid, 'mail_connection_state') !== 'managed'
                || (int)$linkedId !== $account->getId()) {
                continue;
            }
            try {
                $role = ($this->readField)($uid, 'mail_signature_role');
                $desired = MailSignaturePolicy::render(
                    $this->settings, (string)$person->getDisplayName(),
                    (string)$account->getEmail(), is_string($role) ? $role : null,
                );
                $action = MailSignaturePolicy::action(
                    $state, $account->getSignature(), $desired,
                );
                if ($action === 'write') {
                    ($this->updateSignature)($account->getId(), $uid, $desired);
                    $this->values->upsert(
                        $this->fields['mail_signature_state'], $uid,
                        MailSignaturePolicy::managedState($desired),
                        'mail-provision-worker', 'private',
                    );
                    $totals['updated']++;
                } elseif ($action === 'ack' || $action === 'custom') {
                    $this->values->upsert(
                        $this->fields['mail_signature_state'], $uid,
                        $action === 'ack' ? MailSignaturePolicy::managedState($desired) : 'custom',
                        'mail-provision-worker', 'private',
                    );
                    $totals[$action === 'ack' ? 'acknowledged' : 'customized']++;
                }
            } catch (\Throwable) {
                // Never log signature content, identity or full exceptions.
                $totals['failed']++;
            }
        }
        return $totals;
    }
}

// Unit tests include this file without starting Nextcloud or reading secrets.
if (defined('MAIL_PROVISION_POLICY_ONLY')) {
    return;
}

function mailProvisionMain(): int {
    define('OC_CONSOLE', 1);
    $ncRoot = getenv('MAIL_PROVISION_NEXTCLOUD_ROOT');
    if (!is_string($ncRoot) || $ncRoot === ''
        || preg_match('~^/[a-zA-Z0-9_./-]+$~D', $ncRoot) !== 1
        || str_contains($ncRoot, '..')) {
        throw new \RuntimeException('Nextcloud root must be a validated absolute path');
    }
    require rtrim($ncRoot, '/') . '/lib/base.php';

    $lock = fopen(sys_get_temp_dir() . '/mail-provision-reconcile.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        fwrite(STDERR, "RECONCILE_BUSY\n");
        return 2;
    }

    $apply = getenv('MAIL_PROVISION_WRITE_ENABLED') === '1';
    $settings = MailProvisionSettings::fromEnvironment();
    $appManager = \OCP\Server::get(\OCP\App\IAppManager::class);
    $appManager->loadApp('mail');
    $appManager->loadApp('profile_fields');
    $groups = \OCP\Server::get(\OCP\IGroupManager::class);
    $users = \OCP\Server::get(\OCP\IUserManager::class);
    $accounts = \OCP\Server::get(\OCA\Mail\Db\MailAccountMapper::class);
    $provisionings = \OCP\Server::get(\OCA\Mail\Db\ProvisioningMapper::class);
    $definitions = \OCP\Server::get(\OCA\ProfileFields\Service\FieldDefinitionService::class);
    $values = \OCP\Server::get(\OCA\ProfileFields\Service\FieldValueService::class);

    if ($groups->get($settings->group) === null) {
        throw new \RuntimeException('Required group missing');
    }
    // Never allow the built-in provisioning manager and this reconciler to
    // compete for accounts. The native manager can duplicate manual accounts.
    if ($provisionings->getAll() !== []) {
        throw new \RuntimeException('Native provisioning is active; refusing to compete');
    }
    // A legacy standalone Flow provisioner was installed on Ada. It must be
    // explicitly deactivated after auditing consumers before this worker
    // can create accounts, otherwise two engines could race or duplicate.
    if ($apply && $appManager->isEnabledForAnyone('mailcow_provisioning')) {
        throw new \RuntimeException('Legacy standalone Mailcow provisioner must be disabled');
    }

    // The baseline is captured once, before activation, and never regenerated
    // by the scheduled worker. It prevents accidental onboarding of historic
    // accounts and protects deliberately unconnected existing identities.
    $legacyJson = getenv('MAIL_PROVISION_LEGACY_UIDS');
    $legacyUids = [];
    if ($legacyJson !== false) {
        $decoded = json_decode($legacyJson, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new \RuntimeException('Invalid legacy user baseline');
        }
        foreach ($decoded as $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new \RuntimeException('Invalid legacy user identifier');
            }
            $legacyUids[$entry] = true;
        }
        if (count($legacyUids) !== count($decoded)) {
            throw new \RuntimeException('Duplicate legacy user baseline entry');
        }
    }
    if ($apply && $legacyJson === false) {
        throw new \RuntimeException('Legacy user baseline required before writes');
    }

    $fieldNames = [
        'mailcow_mailbox', 'mail_mailbox_kind', 'mailcow_login_enabled',
        'mail_match_unique', 'mail_mapping_evidence_ref',
        'mail_connection_state', 'mail_existing_account_id',
    ];
    if ($settings->signatureEnabled) {
        $fieldNames[] = 'mail_signature_role';
        $fieldNames[] = 'mail_signature_state';
    }
    $fields = [];
    foreach ($fieldNames as $name) {
        $def = $definitions->findByFieldKey($name);
        if (
            $def === null || !$def->getActive()
            || $def->getEditPolicy() !== ($name === 'mail_signature_role' ? 'users' : 'admins')
            || $def->getExposurePolicy() !== ($name === 'mail_signature_role' ? 'private' : 'hidden')
            || ($name === 'mail_signature_role' && $def->getType() !== 'text')
            || ($name === 'mail_signature_state' && $def->getType() !== 'text')
        ) {
            throw new \RuntimeException('Required Profile Fields definition has unsafe policy');
        }
        $fields[$name] = $def;
    }
    $field = static function (string $uid, string $name) use ($values, $fields): mixed {
        $stored = $values->findByFieldDefinitionIdAndUserUid($fields[$name]->getId(), $uid);
        if ($stored === null) {
            return null;
        }
        $data = json_decode($stored->getValueJson(), true, 512, JSON_THROW_ON_ERROR);
        return $data['value'] ?? null;
    };

    $allAccounts = $accounts->getAllAccounts();
    $byUid = [];
    $mailboxOwners = [];
    foreach ($allAccounts as $account) {
        $byUid[$account->getUserId()][] = $account;
        $email = strtolower(trim((string)$account->getEmail()));
        $mailboxOwners[$email][] = $account->getUserId();
    }

    $people = [];
    $claimants = [];
    $users->callForAllUsers(static function (\OCP\IUser $user) use (
        &$people, &$claimants, $groups, $appManager, $field, $legacyUids, $settings,
    ): void {
        $uid = $user->getUID();
        $mapping = $field($uid, 'mailcow_mailbox');
        if ($mapping !== null && !is_string($mapping)) {
            $mapping = ''; // fail closed via the mapping validation below
        }
        $primary = (string)($user->getEMailAddress() ?? '');
        $person = [
            'uid' => $uid,
            'legacy_user' => isset($legacyUids[$uid]),
            'active' => $user->isEnabled(),
            'group_member' => $groups->isInGroup($uid, $settings->group),
            'mail_access' => $appManager->isEnabledForUser('mail', $user),
            'primary_email' => $primary,
            'auto_staged' => is_string($mapping) && $mapping !== ''
                && $field($uid, 'mail_mapping_evidence_ref') === MailReconcilePolicy::intent($uid, strtolower($mapping)),
            'historical_account' => in_array($field($uid, 'mail_connection_state'), ['manual', 'managed'], true)
                || $field($uid, 'mail_existing_account_id') !== null,
            'mapped_mailbox' => $mapping,
            'mapping_valid' => $mapping === null || $mapping === ''
                || (is_string($mapping) && $mapping !== ''
                    && $field($uid, 'mail_mapping_evidence_ref') === MailReconcilePolicy::intent($uid, strtolower($mapping)))
                || (
                    $field($uid, 'mail_mailbox_kind') === 'personal_primary'
                    && $field($uid, 'mailcow_login_enabled') === true
                    && $field($uid, 'mail_match_unique') === true
                    && is_string($field($uid, 'mail_mapping_evidence_ref'))
                    && trim($field($uid, 'mail_mapping_evidence_ref')) !== ''
                ),
        ];
        $people[$uid] = $person;
        $claim = MailReconcilePolicy::claimedMailbox($mapping, $primary, $settings->domain, $uid);
        if ($claim !== null) {
            $claimants[$claim][] = $uid;
        }
    });

    ksort($people);
    foreach ($claimants as &$uids) {
        sort($uids);
    }
    unset($uids);

    $outcomes = [];
    $candidates = [];
    foreach ($people as $uid => $person) {
        $decision = MailReconcilePolicy::decide(
            $person, $byUid[$uid] ?? [], $mailboxOwners, $claimants, $settings->domain,
        );
        $outcomes[$uid] = $decision['action'];
        if ($decision['action'] === 'candidate') {
            $candidates[$uid] = ['mailbox' => $decision['mailbox'], 'mode' => $decision['mode']];
        }
    }

    // Optional one-user execution scope for a controlled onboarding or E2E
    // rollout. Eligibility and ownership checks still include all users.
    $onlyUid = getenv('MAIL_PROVISION_ONLY_UID');
    $candidates = MailReconcilePolicy::limitCandidates(
        $candidates, $people, $onlyUid,
    );

    if ($apply && $candidates !== []) {
        $apiKey = getenv('MAIL_PROVISION_API_KEY');
        if (!is_string($apiKey) || $apiKey === '') {
            throw new \RuntimeException('Mailcow administrator API credential missing');
        }
        $mailcow = new MailcowApiClient($apiKey, $settings);
        unset($apiKey);
        $setup = \OCP\Server::get(\OCA\Mail\Service\SetupService::class);
        $executor = new MailProvisionExecutor(
            $settings, $mailcow, $users, $groups, $appManager,
            $accounts, $values, $fields, $field,
            static fn (...$args) => $setup->createNewAccount(...$args),
        );
        $executor->apply($candidates, $outcomes);
    }

    $signatureReport = ['updated' => 0, 'acknowledged' => 0, 'customized' => 0, 'failed' => 0];
    if ($apply && $settings->signatureEnabled) {
        $accountService = \OCP\Server::get(\OCA\Mail\Service\AccountService::class);
        $signatures = new MailSignatureReconciler(
            $settings, $users, $groups, $appManager, $accounts,
            $values, $fields, $field,
            static fn (int $id, string $uid, string $sig) => $accountService->updateSignature($id, $uid, $sig),
        );
        $signatureReport = $signatures->apply();
    }

    $counts = array_count_values($outcomes);
    ksort($counts);
    $report = [
        'mode' => $apply ? 'apply' : 'dry-run',
        'counts' => $counts,
        'created_count' => count(array_filter($outcomes, static fn (string $x) => str_starts_with($x, 'created'))),
        'review_count' => count(array_filter($outcomes, static fn (string $x) => str_starts_with($x, 'review_') || str_starts_with($x, 'error_') || $x === 'created_metadata_review')),
        'signatures' => $signatureReport,
    ];
    if ($onlyUid !== false && $onlyUid !== '') {
        // Report the selected decision without disclosing the person's UID.
        $report['scope_action'] = $outcomes[$onlyUid];
        $report['scoped_candidates'] = count($candidates);
    }
    echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    // Missing/ambiguous mappings are expected onboarding review items, not
    // execution failures every five minutes. Connection and bookkeeping
    // failures must still produce a nonzero status for monitoring.
    return (
        ($counts['error_connection_or_creation'] ?? 0) > 0
        || ($counts['created_metadata_review'] ?? 0) > 0
        || $signatureReport['failed'] > 0
    ) ? 2 : 0;
}

try {
    exit(mailProvisionMain());
} catch (\Throwable $e) {
    // Never output account passwords, server replies or raw exception messages.
    fwrite(STDERR, 'RECONCILE_FATAL category=' . get_class($e) . PHP_EOL);
    exit(3);
}
