<?php

declare(strict_types=1);

use Piwigo\Config\Config;
use Piwigo\Core\ServiceLocator;
use Piwigo\Exception\AuthException;
use Piwigo\Group\GroupRepository;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageRepository;
use Piwigo\Url\UrlGenerator;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    throw new AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang, $category, $admin_album_base_url;


require_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

check_status(ACCESS_ADMINISTRATOR);

// +-----------------------------------------------------------------------+
// |                       variable initialization                         |
// +-----------------------------------------------------------------------+

$page['cat'] = $category['id'];

// +-----------------------------------------------------------------------+
// |                           form submission                             |
// +-----------------------------------------------------------------------+

// info by email to an access granted group of category informations
if (isset($_POST['submitEmail'])) {
    check_pwg_token();
    set_make_full_url();

    $img = [];

    /* TODO: if $category['representative_picture_id']
      is empty find child representative_picture_id */
    if (!empty($category['representative_picture_id'])) {
        $element = ServiceLocator::get(ImageRepository::class)
            ->findById(is_numeric($category['representative_picture_id']) ? (int) $category['representative_picture_id'] : 0);
        if ($element !== null) {
            $img = [
              'link' => make_picture_url(
                  [
                  'image_id' => $element['id'],
                  'image_file' => $element['file'],
                  'category' => $category,
                  ]
              ),
              'src' => DerivativeImage::url(IMG_THUMB, $element),
              ];
        }
    }

    $args = [
      'subject' => l10n('[%s] Visit album %s', Config::galleryTitle(), trigger_change('render_category_name', $category['name'], 'admin_cat_list')),
      // TODO : change this language variable to 'Visit album %s'
      // TODO : 'language_selected' => ....
      ];

    $tpl = [
      'filename' => 'cat_group_info',
      'assign' => [
        'IMG' => $img,
        'CAT_NAME' => trigger_change('render_category_name', $category['name'], 'admin_cat_list'),
        'LINK' => make_index_url(
            [
            'category' => [
              'id' => $category['id'],
              'name' => trigger_change('render_category_name', $category['name'], 'admin_cat_list'),
              'permalink' => $category['permalink'],
              ],
            ]
        ),
        'CPL_CONTENT' => empty($_POST['mail_content']) ? '' : stripslashes(is_scalar($_POST['mail_content']) ? (string) $_POST['mail_content'] : ''),
        ],
      ];

    if ('users' == $_POST['who'] and isset($_POST['users']) and is_array($_POST['users']) and count($_POST['users']) > 0) {
        check_input_parameter('users', $_POST, true, PATTERN_ID);

        // TODO code very similar to function pwg_mail_group. We'd better create
        // a function pwg_mail_users that could be called from here and from
        // pwg_mail_group

        // TODO to make checks even better, we should check that theses users
        // have access to this album. No real privacy issue here, even if we
        // send the email to a user without permission.

        $query = '
SELECT
    ui.user_id,
    ui.status,
    ui.language,
    u.'.Config::userFields()['email'].' AS email,
    u.'.Config::userFields()['username'].' AS username
  FROM '.USER_INFOS_TABLE.' AS ui
    JOIN '.USERS_TABLE.' AS u ON u.'.Config::userFields()['id'].' = ui.user_id
  WHERE ui.user_id IN ('.implode(',', array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', (array) $_POST['users'])).')
;';
        $users = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
        $usernames = [];

        foreach ($users as $u) {
            $usernames[] = is_scalar($u['username']) ? (string) $u['username'] : '';

            $authkey = create_user_auth_key(is_numeric($u['user_id']) ? (int) $u['user_id'] : 0, is_string($u['status']) ? $u['status'] : null);

            $user_tpl = $tpl;

            if ($authkey !== false) {
                $user_tpl['assign']['LINK'] = add_url_params($tpl['assign']['LINK'], ['auth' => $authkey['auth_key']]);

                if (isset($user_tpl['assign']['IMG']['link'])) {
                    $user_tpl['assign']['IMG']['link'] = add_url_params(
                        $user_tpl['assign']['IMG']['link'],
                        ['auth' => $authkey['auth_key']]
                    );
                }
            }

            $user_args = $args;
            if (isset($authkey['auth_key'])) {
                $user_args['auth_key'] = $authkey['auth_key'];
            }

            switch_lang_to(is_scalar($u['language']) ? (string) $u['language'] : '');
            pwg_mail(is_scalar($u['email']) ? (string) $u['email'] : '', $user_args, $user_tpl);
            switch_lang_back();
        }

        $message = l10n_dec('%d mail was sent.', '%d mails were sent.', count($users));
        $message .= ' ('.implode(', ', $usernames).')';

        $template->assign(
            [
            'save_success' => $message,
      ]
        );
    } elseif ('group' == $_POST['who'] and !empty($_POST['group'])) {
        check_input_parameter('group', $_POST, false, PATTERN_ID);

        pwg_mail_group(is_numeric($_POST['group']) ? (int) $_POST['group'] : 0, $args, $tpl);

        $post_group_str = is_scalar($_POST['group']) ? (string) $_POST['group'] : '0';
        $group_name = ServiceLocator::get(GroupRepository::class)
            ->findNameById((int) $post_group_str);

        $template->assign(
            [
            'save_success' => l10n('An information email was sent to group "%s"', $group_name),
      ]
        );
    }

    unset_make_full_url();
}

// +-----------------------------------------------------------------------+
// |                       template initialization                         |
// +-----------------------------------------------------------------------+

$template->set_filename('album_notification', 'album_notification.tpl');

$template->assign(
    [
    'CATEGORIES_NAV' =>
      trim(
          get_cat_display_name_from_id(
              $page['cat'],
              ServiceLocator::get(UrlGenerator::class)->admin() . '&page=album-'
          )
      ),
    'F_ACTION' => $admin_album_base_url.'-notification',
    'PWG_TOKEN' => get_pwg_token(),
    ]
);

if (Config::authKeyDuration() > 0) {
    $template->assign(
        'auth_key_duration',
        time_since(
            strtotime('now -'.Config::authKeyDuration().' second') ?: null,
            'second',
            null,
            false
        )
    );
}

// +-----------------------------------------------------------------------+
// |                          form construction                            |
// +-----------------------------------------------------------------------+

$query = '
SELECT
    id AS group_id
  FROM `'.GROUPS_TABLE.'`
;';
$all_group_ids = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'group_id');

if (count($all_group_ids) == 0) {
    $template->assign('no_group_in_gallery', true);
} else {
    if ('private' == $category['status']) {
        $template->assign('permission_url', $admin_album_base_url.'-permissions');

        $query = '
SELECT
    group_id
  FROM '.GROUP_ACCESS_TABLE.'
  WHERE cat_id = '.$category['id'].'
;';
        $group_ids = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'group_id');
    } else {
        $group_ids = $all_group_ids;
    }

    if (count($group_ids) > 0) {
        $query = '
SELECT
    id,
    name
  FROM `'.GROUPS_TABLE.'`
  WHERE id IN ('.implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $group_ids)).')
  ORDER BY name ASC
;';
        $template->assign(
            'group_mail_options',
            array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'name', 'id')
        );
    }
}

// all users with status != guest and permitted to this this album (for a
// perfect search, we should also check that album is not only filled with
// private photos)
$query = '
SELECT
    user_id
  FROM '.USER_INFOS_TABLE.'
  WHERE status != \'guest\'
;';
$all_user_ids = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'user_id');

if ('private' == $category['status']) {
    $user_ids_access_indirect = [];

    if (isset($group_ids) and count($group_ids) > 0) {
        $query = '
SELECT
    user_id
  FROM '.USER_GROUP_TABLE.'
  WHERE group_id IN ('.implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $group_ids)).')
';
        $user_ids_access_indirect = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'user_id');
    }

    $query = '
SELECT
    user_id
  FROM '.USER_ACCESS_TABLE.'
  WHERE cat_id = '.$category['id'].'
;';
    $user_ids_access_direct = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'user_id');

    $user_ids_access = array_unique(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', array_merge($user_ids_access_direct, $user_ids_access_indirect)));

    $user_ids = array_intersect($user_ids_access, array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $all_user_ids));
} else {
    $user_ids = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $all_user_ids);
}

if (count($user_ids) > 0) {
    $query = '
SELECT
    '.Config::userFields()['id'].' AS id,
    '.Config::userFields()['username'].' AS username
  FROM '.USERS_TABLE.'
  WHERE id IN ('.implode(',', $user_ids).')
;';

    $users = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'username', 'id');

    $template->assign('user_options', $users);
}

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('ADMIN_CONTENT', 'album_notification');
