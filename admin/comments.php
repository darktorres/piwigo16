<?php

declare(strict_types=1);

use Piwigo\Admin\Tabsheet;
use Piwigo\Config\Config;
use Piwigo\Exception\AuthException;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    throw new AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;


require_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

check_status(ACCESS_ADMINISTRATOR);

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames(['comments' => 'comments.tpl']);

$comments_disabled = !Config::activateComments();

$template->assign([
    'F_ACTION'           => get_root_url().'admin.php?page=comments',
    'PWG_TOKEN'          => get_pwg_token(),
    'COMMENTS_DISABLED'  => $comments_disabled,
    'U_CONFIGURATION'    => get_root_url().'admin.php?page=configuration&amp;section=comments',
    'page_data_json'     => json_encode([
        'pwg_token'                => get_pwg_token(),
        'str_yes_delete_confirmation' => l10n('Yes, delete'),
        'str_no_delete_confirmation'  => l10n('No, I have changed my mind'),
        'str_delete'               => l10n('Are you sure you want to delete comment #%s?'),
        'str_deletes'              => l10n('Are you sure you want to delete "%d" comments?'),
        'str_no_comments_selected' => l10n('No comments selected, no actions possible.'),
        'str_an_error_has'         => l10n('An error has occured'),
        'str_comment_validated'    => l10n('The comment has been validated.'),
        'str_comments_validated'   => l10n('The comments have been validated.'),
        'str_and_others'           => l10n('and %s others'),
    ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
]);

// +-----------------------------------------------------------------------+
// | Tabs                                                                  |
// +-----------------------------------------------------------------------+

$my_base_url = get_root_url().'admin.php?page=';

$tabsheet = new Tabsheet();
$tabsheet->set_id('comments');
$tabsheet->select('');
$tabsheet->assign();

$template->assign('ADMIN_PAGE_TITLE', l10n('User comments'));

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('ADMIN_CONTENT', 'comments');
