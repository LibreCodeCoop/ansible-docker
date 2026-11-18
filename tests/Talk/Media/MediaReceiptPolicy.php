<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace LibreCode\AnsibleDocker\Tests\Talk\Media;

final class MediaReceiptPolicy
{
    /** @param array{audio:int,video:int} $counts */
    public function hasMinimum(array $counts, int $threshold = 5): bool
    {
        return $counts['audio'] >= $threshold && $counts['video'] >= $threshold;
    }
}
