<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Install administrative Profile Fields required by Mail provisioning.
 * Dry-run unless MAIL_PROVISION_FIELDS_WRITE=1. Existing definitions are
 * never modified; incompatible definitions stop installation.
 */
declare(strict_types=1);

/**
 * Portable definitions. The role belongs to each member; managed state does not.
 * Offline unit tests can validate this schema without starting Nextcloud.
 *
 * @return array<string, array{0:string,1:string,2?:?array,3?:string,4?:string}>
 */
function mailProvisionFieldSchema(): array {
    return [
        'mailcow_mailbox' => ['email', 'Corporate mailbox'],
        'mail_mailbox_kind' => ['select', 'Mailbox category', ['unknown', 'personal_primary', 'alias', 'shared', 'service']],
        'mailcow_login_enabled' => ['boolean', 'Mailbox login enabled'],
        'mail_match_unique' => ['boolean', 'Verified exclusive mailbox link'],
        'mail_mapping_evidence_ref' => ['text', 'Mailbox ownership evidence'],
        'mail_connection_state' => ['select', 'Nextcloud Mail connection status', ['unknown', 'not_configured', 'manual', 'managed', 'error']],
        'mail_existing_account_id' => ['number', 'Nextcloud Mail account ID'],
        // Users can edit their own role, but not administrative provenance.
        'mail_signature_role' => ['text', (string)(getenv('MAIL_PROVISION_SIGNATURE_ROLE_LABEL') ?: 'Role or title'), null, 'users', 'private'],
        'mail_signature_state' => ['text', 'Mail signature template state', null, 'admins', 'hidden'],
    ];
}

if (defined('MAIL_PROVISION_FIELDS_SCHEMA_ONLY')) {
    return;
}

try {
    define('OC_CONSOLE', 1);
    $ncRoot = getenv('MAIL_PROVISION_NEXTCLOUD_ROOT');
    if (!is_string($ncRoot) || $ncRoot === ''
        || preg_match('~^/[a-zA-Z0-9_./-]+$~D', $ncRoot) !== 1
        || str_contains($ncRoot, '..')) {
        throw new \RuntimeException('Nextcloud root must be a validated absolute path');
    }
    require rtrim($ncRoot, '/') . '/lib/base.php';
    $appManager = \OCP\Server::get(\OCP\App\IAppManager::class);
    $appManager->loadApp('profile_fields');
    $definitions = \OCP\Server::get(\OCA\ProfileFields\Service\FieldDefinitionService::class);
    $schema = mailProvisionFieldSchema();
    $write = getenv('MAIL_PROVISION_FIELDS_WRITE') === '1';
    $created = 0;
    foreach ($schema as $key => $spec) {
        [$type, $label] = $spec;
        $options = $spec[2] ?? null;
        $editPolicy = $spec[3] ?? 'admins';
        $exposurePolicy = $spec[4] ?? 'hidden';
        $existing = $definitions->findByFieldKey($key);
        if ($existing !== null) {
            $currentOptions = json_decode((string)($existing->getOptions() ?? ''), true);
            if (!$existing->getActive() || $existing->getType() !== $type
                || $existing->getEditPolicy() !== $editPolicy
                || $existing->getExposurePolicy() !== $exposurePolicy
                || ($options !== null && (!is_array($currentOptions)
                    || array_diff($options, $currentOptions) !== []))) {
                throw new \RuntimeException('Incompatible administrative field definition: ' . $key);
            }
            continue;
        }
        ++$created;
        if ($write) {
            $definitions->create([
                'field_key' => $key, 'label' => $label, 'type' => $type,
                'edit_policy' => $editPolicy, 'exposure_policy' => $exposurePolicy,
                'active' => true, 'sort_order' => $key === 'mail_signature_role' ? 60 : 100,
                'options' => $options,
            ]);
        }
    }
    echo json_encode([
        'mode' => $write ? 'apply' : 'dry-run',
        'missing_definitions' => $created,
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (\Throwable $e) {
    fwrite(STDERR, 'MAIL_FIELDS_FATAL category=' . get_class($e) . PHP_EOL);
    exit(3);
}
