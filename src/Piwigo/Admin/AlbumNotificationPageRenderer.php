<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\ValidationPattern;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Mail\MailService;
use Piwigo\Template\Template;

/**
 * Ported from admin/album_notification.php (the "notification" tab of the
 * "album" page slug, dispatched by AlbumSubController).
 *
 * admin.php itself already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch (admin.php:65),
 * so the original album_notification.php's own (redundant) check_status()
 * call is dropped here -- same precedent as PhotosAddSubController.
 */
final class AlbumNotificationPageRenderer
{
    public function render(): void
    {
        /**
         * @var string $admin_album_base_url
         * @var array<string, string|null> $category
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $page
         * @var Template $template
         */
        global $admin_album_base_url, $category, $conf, $page, $template;

        // +-------------------------------------------------------------------+
        // |                       variable initialization                     |
        // +-------------------------------------------------------------------+

        $page['cat'] = (int) $category['id'];

        // $conf['user_fields'] maps generic field names to table-specific column
        // names (see include/config_default.inc.php); every value is a plain
        // string. Extracted once here and reused by both user-list queries below.
        $user_fields_raw = $conf['user_fields'];
        $user_fields = [];
        if (is_array($user_fields_raw)) {
            foreach ($user_fields_raw as $field_key => $field_value) {
                if (is_string($field_key) and is_string($field_value)) {
                    $user_fields[$field_key] = $field_value;
                }
            }
        }
        $user_field_id = $user_fields['id'] ?? 'id';
        $user_field_username = $user_fields['username'] ?? 'username';
        $user_field_email = $user_fields['email'] ?? 'mail_address';

        // +-------------------------------------------------------------------+
        // |                           form submission                         |
        // +-------------------------------------------------------------------+

        // info by email to an access granted group of category informations
        if (isset($_POST['submitEmail'])) {
            check_pwg_token();
            set_make_full_url();

            $img = [];

            /* TODO: if $category['representative_picture_id']
              is empty find child representative_picture_id */
            if (! empty($category['representative_picture_id'])) {
                $query = '
SELECT id, file, path, representative_ext
  FROM ' . Tables::images() . '
  WHERE id = ' . $category['representative_picture_id'] . '
;';

                $result = \Piwigo\Db\MysqliDb::query($query);
                if (\Piwigo\Db\MysqliDb::numRows($result) > 0) {
                    $element = \Piwigo\Db\MysqliDb::fetchAssoc($result);
                    // the num_rows > 0 check above guarantees a row is available
                    assert(is_array($element));

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
                'subject' => l10n('[%s] Visit album %s', $conf['gallery_title'], trigger_change('render_category_name', $category['name'], 'admin_cat_list')),
                // TODO : change this language variable to 'Visit album %s'
                // TODO : 'language_selected' => ....
            ];

            $mail_content = $_POST['mail_content'] ?? null;
            $mail_content = is_string($mail_content) ? $mail_content : '';

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
                    'CPL_CONTENT' => empty($mail_content) ? '' : stripslashes($mail_content),
                ],
            ];

            if ($_POST['who'] === 'users' and isset($_POST['users']) and is_array($_POST['users']) and count($_POST['users']) > 0) {
                (new \Piwigo\Validation\InputValidator())->validate('users', $_POST, true, ValidationPattern::ID);

                // TODO code very similar to function pwg_mail_group. We'd better create
                // a function pwg_mail_users that could be called from here and from
                // pwg_mail_group

                // TODO to make checks even better, we should check that theses users
                // have access to this album. No real privacy issue here, even if we
                // send the email to a user without permission.

                // check_input_parameter() above already validated that every item
                // matches ValidationPattern::ID (digits only), so this filter only exists to
                // give implode() a provably string-castable array.
                $post_user_ids = array_filter($_POST['users'], is_string(...));

                $query = '
SELECT
    ui.user_id,
    ui.status,
    ui.language,
    u.' . $user_field_email . ' AS email,
    u.' . $user_field_username . ' AS username
  FROM ' . Tables::userInfos() . ' AS ui
    JOIN ' . Tables::users() . ' AS u ON u.' . $user_field_id . ' = ui.user_id
  WHERE ui.user_id IN (' . implode(',', $post_user_ids) . ')
;';
                $users = \Piwigo\Db\MysqliDb::query2Array($query);
                $usernames = [];

                foreach ($users as $u) {
                    // user_infos.user_id is the row's own key column; a
                    // non-numeric value would mean a corrupt join, so skip this
                    // user rather than pass a fabricated id to create_user_auth_key().
                    if (! is_numeric($u['user_id'])) {
                        continue;
                    }

                    $usernames[] = $u['username'];

                    $authkey = (new \Piwigo\Auth\AuthService(new \Piwigo\Auth\AuthRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService()))->createUserAuthKey((int) $u['user_id'], $u['status']);

                    $user_tpl = $tpl;

                    if ($authkey !== false) {
                        $user_tpl['assign']['LINK'] = add_url_params($tpl['assign']['LINK'], [
                            'auth' => $authkey['auth_key'],
                        ]);

                        if (isset($user_tpl['assign']['IMG']['link'])) {
                            $user_tpl['assign']['IMG']['link'] = add_url_params(
                                $user_tpl['assign']['IMG']['link'],
                                [
                                    'auth' => $authkey['auth_key'],
                                ]
                            );
                        }
                    }

                    $user_args = $args;
                    if (isset($authkey['auth_key']) and is_string($authkey['auth_key'])) {
                        $user_args['auth_key'] = $authkey['auth_key'];
                    }

                    $user_language = is_string($u['language']) ? $u['language'] : (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService()))->getDefaultLanguage();
                    $user_email = is_string($u['email']) ? $u['email'] : '';

                    new MailService()
                        ->switchLangTo($user_language);
                    new MailService()
                        ->mail($user_email, $user_args, $user_tpl);
                    new MailService()
                        ->switchLangBack();
                }

                $message = l10n_dec('%d mail was sent.', '%d mails were sent.', count($users));
                $message .= ' (' . implode(', ', $usernames) . ')';

                $template->assign(
                    [
                        'save_success' => $message,
                    ]
                );
            } elseif ($_POST['who'] === 'group' and ! empty($_POST['group'])) {
                (new \Piwigo\Validation\InputValidator())->validate('group', $_POST, false, ValidationPattern::ID);

                // check_input_parameter() above fatal_errors (never returns) unless
                // $_POST['group'] matches ValidationPattern::ID (digits only); the is_numeric()
                // check here only narrows the type for what follows.
                $group_id = is_numeric($_POST['group']) ? (int) $_POST['group'] : 0;

                new MailService()
                    ->mailGroup($group_id, $args, $tpl);

                $query = '
SELECT
    name
  FROM `' . Tables::groups() . '`
  WHERE id = ' . $group_id . '
;';
                $row = \Piwigo\Db\MysqliDb::fetchRow(\Piwigo\Db\MysqliDb::query($query));
                $group_name = $row !== null ? $row[0] : null;

                $template->assign(
                    [
                        'save_success' => l10n('An information email was sent to group "%s"', $group_name),
                    ]
                );
            }

            unset_make_full_url();
        }

        // +-------------------------------------------------------------------+
        // |                       template initialization                     |
        // +-------------------------------------------------------------------+

        $template->set_filename('album_notification', 'album_notification.tpl');

        // $page['cat'] was set to (int) $category['id'] above, in this same
        // method scope with no intervening by-reference calls, so its
        // narrowing is still provably int here.
        $page_cat = $page['cat'];

        $template->assign(
            [
                'CATEGORIES_NAV' => trim(
                    new HtmlService()
                        ->getCatDisplayNameFromId(
                            $page_cat,
                            'admin.php?page=album-'
                        )
                ),
                'F_ACTION' => $admin_album_base_url . '-notification',
                'PWG_TOKEN' => (new \Piwigo\Csrf\CsrfService())->getToken(),
            ]
        );

        // auth_key_duration is a plain int config value (see
        // include/config_default.inc.php).
        $auth_key_duration = $conf['auth_key_duration'];
        $auth_key_duration_num = is_numeric($auth_key_duration) ? (int) $auth_key_duration : 0;
        if ($auth_key_duration_num > 0) {
            $auth_key_since = strtotime('now -' . $auth_key_duration_num . ' second');
            // the relative time expression above is always syntactically valid
            assert($auth_key_since !== false);
            $template->assign(
                'auth_key_duration',
                \Piwigo\Core\DateHelper::timeSince($auth_key_since, 'second', null, false)
            );
        }

        // +-------------------------------------------------------------------+
        // |                          form construction                        |
        // +-------------------------------------------------------------------+

        $query = '
SELECT
    id AS group_id
  FROM `' . Tables::groups() . '`
;';
        $all_group_ids = \Piwigo\Db\MysqliDb::query2Array($query, null, 'group_id');
        // group_ids stays [] (rather than undefined) when the gallery has no
        // groups at all, so the "private album" branch below can safely read it
        // unconditionally instead of guarding on definedness.
        $group_ids = [];

        if (count($all_group_ids) === 0) {
            $template->assign('no_group_in_gallery', true);
        } else {
            if ($category['status'] === 'private') {
                $template->assign('permission_url', $admin_album_base_url . '-permissions');

                $query = '
SELECT
    group_id
  FROM ' . Tables::groupAccess() . '
  WHERE cat_id = ' . $category['id'] . '
;';
                $group_ids = \Piwigo\Db\MysqliDb::query2Array($query, null, 'group_id');
            } else {
                $group_ids = $all_group_ids;
            }

            if (count($group_ids) > 0) {
                $query = '
SELECT
    id,
    name
  FROM `' . Tables::groups() . '`
  WHERE id IN (' . implode(',', array_filter($group_ids, is_string(...))) . ')
  ORDER BY name ASC
;';
                $template->assign(
                    'group_mail_options',
                    \Piwigo\Db\MysqliDb::query2Array($query, 'id', 'name')
                );
            }
        }

        // all users with status != guest and permitted to this this album (for a
        // perfect search, we should also check that album is not only filled with
        // private photos)
        $query = '
SELECT
    user_id
  FROM ' . Tables::userInfos() . '
  WHERE status != \'guest\'
;';
        $all_user_ids = \Piwigo\Db\MysqliDb::query2Array($query, null, 'user_id');

        if ($category['status'] === 'private') {
            $user_ids_access_indirect = [];

            if (count($group_ids) > 0) {
                $query = '
SELECT
    user_id
  FROM ' . Tables::userGroup() . '
  WHERE group_id IN (' . implode(',', array_filter($group_ids, is_string(...))) . ')
';
                $user_ids_access_indirect = \Piwigo\Db\MysqliDb::query2Array($query, null, 'user_id');
            }

            $query = '
SELECT
    user_id
  FROM ' . Tables::userAccess() . '
  WHERE cat_id = ' . $category['id'] . '
;';
            $user_ids_access_direct = \Piwigo\Db\MysqliDb::query2Array($query, null, 'user_id');

            $user_ids_access = array_unique(array_merge($user_ids_access_direct, $user_ids_access_indirect));

            $user_ids = array_intersect($user_ids_access, $all_user_ids);
        } else {
            $user_ids = $all_user_ids;
        }

        if (count($user_ids) > 0) {
            // WHERE must filter on the same (possibly remapped, see $user_fields
            // above) id column the SELECT aliases to "id" -- a literal `id` here
            // would silently filter on the wrong column for a site using a
            // non-default external-auth $conf['user_fields']['id'] mapping.
            $query = '
SELECT
    ' . $user_field_id . ' AS id,
    ' . $user_field_username . ' AS username
  FROM ' . Tables::users() . '
  WHERE ' . $user_field_id . ' IN (' . implode(',', $user_ids) . ')
;';

            $users = \Piwigo\Db\MysqliDb::query2Array($query, 'id', 'username');

            $template->assign('user_options', $users);
        }

        // +-------------------------------------------------------------------+
        // |                           sending html code                       |
        // +-------------------------------------------------------------------+

        $template->assign_var_from_handle('ADMIN_CONTENT', 'album_notification');
    }
}
