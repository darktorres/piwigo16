<?php

declare(strict_types=1);

use Piwigo\Admin\Config\WatermarkProcessor;
use Piwigo\Core\ServiceLocator;
use Piwigo\Exception\AuthException;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    throw new AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;

ServiceLocator::get(WatermarkProcessor::class)->process();
