<?php

declare(strict_types=1);

use Piwigo\Admin\Upload\DirectPreparer;
use Piwigo\Core\ServiceLocator;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

global $template, $user, $page, $persistent_cache, $lang;

ServiceLocator::get(DirectPreparer::class)->prepare(PHOTOS_ADD_BASE_URL);
