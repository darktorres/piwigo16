<?php

declare(strict_types=1);

use Piwigo\Exception\AuthException;

global $template, $user, $page, $persistent_cache, $lang;
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHOTOS_ADD_BASE_URL')) {
    throw new AuthException('Hacking attempt!');
}

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

$template->assign('ADMIN_PAGE_TITLE', l10n('Upload Photos'));

$template->assign_var_from_handle('ADMIN_CONTENT', 'photos_add');
