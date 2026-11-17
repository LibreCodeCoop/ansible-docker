<?php
/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
define('MAIL_PROVISION_FIELDS_SCHEMA_ONLY', true);
putenv('MAIL_PROVISION_SIGNATURE_ROLE_LABEL=Role or department');
require __DIR__ . '/../files/nextcloud-mailcow-fields.php';

$definitions = mailProvisionFieldSchema();
if (count($definitions) !== 9) {
    throw new RuntimeException('Unexpected Profile Fields count');
}
foreach ($definitions as $key => $spec) {
    $edit = $spec[3] ?? 'admins';
    $expose = $spec[4] ?? 'hidden';
    if ($key === 'mail_signature_role') {
        if ($spec[0] !== 'text' || $spec[1] !== 'Role or department'
            || $edit !== 'users' || $expose !== 'private') {
            throw new RuntimeException('User role must be editable and private');
        }
    } elseif ($edit !== 'admins' || $expose !== 'hidden') {
        throw new RuntimeException('Administrative fields may not be user-editable');
    }
}
if ($definitions['mail_signature_state'][0] !== 'text') {
    throw new RuntimeException('Template state must be a hidden text field');
}
putenv('MAIL_PROVISION_SIGNATURE_ROLE_LABEL');
if (mailProvisionFieldSchema()['mail_signature_role'][1] !== 'Role or title') {
    throw new RuntimeException('Generic label fallback not applied');
}
echo "SIGNATURE_FIELDS_POLICY_PASS: role visible/editable to owner, provenance admin-only, generic label\n";
