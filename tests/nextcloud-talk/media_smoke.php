#!/usr/bin/env php
<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/vendor-bin/phpunit/vendor/autoload.php';

use LibreCode\AnsibleDocker\Tests\Talk\Media\JanusMediaSmoke;

(new JanusMediaSmoke(getenv('JANUS_WS_URL') ?: 'ws://janus:8188/'))->run();
