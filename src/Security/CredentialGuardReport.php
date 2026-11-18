<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace LibreCode\AnsibleDocker\Security;

final class CredentialGuardReport
{
    /** @param array{path:string,line:int,field:string,commit?:string} $finding */
    public function formatFinding(array $finding): string
    {
        $prefix = isset($finding['commit']) ? $finding['commit'] . ' ' : '';

        // The scanner never stores matched values. Only metadata is displayed.
        return $prefix . $finding['path'] . ':' . $finding['line']
            . ': static credential forbidden in ' . $finding['field']
            . '; use Vault or an external variable';
    }
}
