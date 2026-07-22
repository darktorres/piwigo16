<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// P23 batch 8f: page logic moved to
// Piwigo\Controller\ImageDerivativeController; this file is now the thin
// FAST-BOOTSTRAP entry shell -- it deliberately does NOT include
// include/common.inc.php (no plugin loading, no user/session bootstrap,
// minimal DB work), a hard performance requirement for serving derivative
// images.
//
// Legacy Coupling Retirement gap-closure (entry-shell define()/include
// round): the former config_default.inc.php + local/config/config.inc.php
// includes that used to sit here are gone -- they built a bare $conf array
// ImageDerivativeController::serve() never read (it uses its own local
// $conf_unused, see that method's own comment), the same dead-code shape
// already found and removed from install.php in an earlier phase and from
// include/common.inc.php in this same round.
//
// PHPWG_ROOT_PATH stays defined here (SEC-60 forbids define() in
// src/Piwigo/, so it has to live in an entry shell if it lives anywhere) --
// but only for ImageDerivativeController::parseRequest()'s own two
// remaining reads, both real URL-generation concerns (a redirect target),
// deliberately deferred to Workstream C3 Part III's own dedicated pass on
// this controller. Every genuine filesystem-path read in that class
// already reads $paths instead.
require __DIR__ . '/vendor/autoload.php';

$paths = \Piwigo\Core\Paths::fromIndex(__FILE__);
defined('PHPWG_ROOT_PATH') or define('PHPWG_ROOT_PATH', './');

// autoload boundary -- nothing above this line may reference a Piwigo\ class
include $paths->root . 'include/env.inc.php';

new \Piwigo\Controller\ImageDerivativeController($paths)
    ->serve();
