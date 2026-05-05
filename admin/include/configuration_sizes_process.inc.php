<?php

declare(strict_types=1);

use Piwigo\Exception\AuthException;
use Piwigo\Admin\Config\SizesProcessor;
use Piwigo\Core\ServiceLocator;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    throw new AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;

ServiceLocator::get(SizesProcessor::class)->process();
