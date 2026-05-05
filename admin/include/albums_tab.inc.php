<?php

declare(strict_types=1);

use Piwigo\Admin\Album\AlbumsTabRenderer;
use Piwigo\Core\ServiceLocator;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

global $template, $user, $page, $persistent_cache, $lang;

ServiceLocator::get(AlbumsTabRenderer::class)->render();
