<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AuthRepository;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\CookieService;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\Translator;
use Piwigo\Mail\MailService;
use Piwigo\Template\Template;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

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
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
    ) {}

    /**
     * @param array<string, mixed> $category
     */
    public function render(string $admin_album_base_url, array $category): void
    {
        // Phase 2 global-residual sweep: $page is a local scratch array
        // for this method's own body only (no longer `global $page;`),
        // same shape as Section\SectionPopulator::populate()'s own
        // equivalent fix (Track A5.2e).
        /** @var array<string, mixed> $page */
        $page = [];
        $template = \Piwigo\Template\CurrentTemplate::get();
        $conn = DbConnection::build();

        // +-------------------------------------------------------------------+
        // |                       variable initialization                     |
        // +-------------------------------------------------------------------+

        // category id is the NOT NULL primary key, always numeric once
        // fetched -- narrowed once here and reused by every raw-SQL splice
        // below instead of re-guarding `$category['id']` at each site.
        $category_id = is_numeric($category['id']) ? (int) $category['id'] : 0;
        $page['cat'] = $category_id;

        // \Piwigo\Config\CurrentConfig::userFields() maps generic field names to table-specific column
        // names (see include/config_default.inc.php); every value is a plain
        // string. Extracted once here and reused by both user-list queries below.
        $user_fields = \Piwigo\Config\CurrentConfig::userFields();
        $user_field_id = $user_fields['id'];
        $user_field_username = $user_fields['username'];
        $user_field_email = $user_fields['email'];

        // +-------------------------------------------------------------------+
        // |                           form submission                         |
        // +-------------------------------------------------------------------+

        // info by email to an access granted group of category informations
        if (isset($_POST['submitEmail'])) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(new HtmlService(), $this->redirectService);
            $this->urlService->setMakeFullUrl();

            $img = [];

            // Known limitation: when $category['representative_picture_id']
            // is empty, no image is shown -- there's no descendant-fallback
            // lookup ("use a child album's representative instead"), only
            // a direct-representative check. Not a defect, just a smaller
            // feature than a full recursive lookup would be.
            if (is_numeric($category['representative_picture_id']) && (int) $category['representative_picture_id'] !== 0) {
                $query = '
SELECT id, file, path, representative_ext
  FROM ' . Tables::images() . '
  WHERE id = ' . (string) $category['representative_picture_id'] . '
;';

                $img_rows = $conn->fetchAllAssociative($query);
                if (count($img_rows) > 0) {
                    $element = $img_rows[0];

                    $img = [
                        'link' => $this->urlService->makePictureUrl(
                            [
                                'image_id' => $element['id'],
                                'image_file' => $element['file'],
                                'category' => $category,
                            ]
                        ),
                        'src' => DerivativeImage::url(ImageStdParams::THUMB, $element),
                    ];
                }
            }

            $args = [
                'subject' => Lang::t('[%s] Visit album %s', \Piwigo\Config\CurrentConfig::galleryTitle(), \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_category_name', $category['name'], 'admin_cat_list')),
            ];

            $mail_content = $_POST['mail_content'] ?? null;
            $mail_content = is_string($mail_content) ? $mail_content : '';

            $tpl = [
                'filename' => 'cat_group_info',
                'assign' => [
                    'IMG' => $img,
                    'CAT_NAME' => \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_category_name', $category['name'], 'admin_cat_list'),
                    'LINK' => $this->urlService->makeIndexUrl(
                        [
                            'category' => [
                                'id' => $category['id'],
                                'name' => \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_category_name', $category['name'], 'admin_cat_list'),
                                'permalink' => $category['permalink'],
                            ],
                        ]
                    ),
                    'CPL_CONTENT' => $mail_content === '' ? '' : stripslashes($mail_content),
                ],
            ];

            $post_users = $_POST['users'] ?? null;
            if ($_POST['who'] === 'users' and is_array($post_users) and count($post_users) > 0) {
                new \Piwigo\Validation\InputValidator()
                    ->validate('users', $_POST, true, ValidationPattern::ID);

                // No real privacy issue sending this notification to a user
                // without access to the album: the email itself carries no
                // private content beyond a public category name/link.

                // check_input_parameter() above already validated that every item
                // matches ValidationPattern::ID (digits only); this loop (rather than
                // array_filter(..., is_string(...))) exists because Psalm doesn't narrow
                // the callback's effect on $_POST's mixed nested-array union type, so a
                // bare array_filter() result is still not provably string-only to implode().
                $post_user_ids = [];
                foreach ($post_users as $post_user_id) {
                    if (is_string($post_user_id)) {
                        $post_user_ids[] = $post_user_id;
                    }
                }

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
                $users = $conn->fetchAllAssociative($query);
                $usernames = [];

                foreach ($users as $u) {
                    // user_infos.user_id is the row's own key column; a
                    // non-numeric value would mean a corrupt join, so skip this
                    // user rather than pass a fabricated id to create_user_auth_key().
                    if (! is_numeric($u['user_id'])) {
                        continue;
                    }

                    $u_username = is_string($u['username']) ? $u['username'] : '';
                    $usernames[] = $u_username;

                    $u_status = is_string($u['status']) ? $u['status'] : null;
                    $authkey = new AuthService(new AuthRepository($conn), new ActivityService(new ActivityRepository($conn)), new HtmlService(), new PasswordService(new PasswordRepository($conn)), new CookieService())
                        ->createUserAuthKey((int) $u['user_id'], $u_status);

                    $user_tpl = $tpl;

                    if ($authkey !== false) {
                        $user_tpl['assign']['LINK'] = $this->urlService->addUrlParams($tpl['assign']['LINK'], [
                            'auth' => $authkey['auth_key'],
                        ]);

                        if (isset($user_tpl['assign']['IMG']['link'])) {
                            $user_tpl['assign']['IMG']['link'] = $this->urlService->addUrlParams(
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

                    $user_language = is_string($u['language']) ? $u['language'] : new UserService(new UserRepository($conn), new GroupRepository($conn), new MailService(), new ActivityService(new ActivityRepository($conn)), new HtmlService(), $conn)->getDefaultLanguage();
                    $user_email = is_string($u['email']) ? $u['email'] : '';

                    new MailService()
                        ->switchLangTo($user_language);
                    new MailService()
                        ->mail($user_email, $user_args, $user_tpl);
                    new MailService()
                        ->switchLangBack();
                }

                $message = Translator::get()->plural('%d mail was sent.', '%d mails were sent.', count($users));
                $message .= ' (' . implode(', ', $usernames) . ')';

                $template->assign(
                    [
                        'save_success' => $message,
                    ]
                );
            } elseif ($_POST['who'] === 'group' and ! in_array($_POST['group'] ?? null, [null, false, 0, '0', '', []], true)) {
                new \Piwigo\Validation\InputValidator()
                    ->validate('group', $_POST, false, ValidationPattern::ID);

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
                $row = $conn->fetchNumeric($query);
                $group_name = $row !== false ? $row[0] : null;

                $template->assign(
                    [
                        'save_success' => Lang::t('An information email was sent to group "%s"', $group_name),
                    ]
                );
            }

            $this->urlService->unsetMakeFullUrl();
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
                'PWG_TOKEN' => new \Piwigo\Csrf\CsrfService()
                    ->getToken(),
            ]
        );

        // auth_key_duration is a plain int config value (see
        // include/config_default.inc.php).
        $auth_key_duration = \Piwigo\Config\CurrentConfig::authKeyDuration();
        $auth_key_duration_num = $auth_key_duration;
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
        $all_group_ids = array_column($conn->fetchAllAssociative($query), 'group_id');
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
  WHERE cat_id = ' . $category_id . '
;';
                $group_ids = array_column($conn->fetchAllAssociative($query), 'group_id');
            } else {
                $group_ids = $all_group_ids;
            }

            if (count($group_ids) > 0) {
                $query = '
SELECT
    id,
    name
  FROM `' . Tables::groups() . '`
  WHERE id IN (' . implode(',', array_filter($group_ids, static fn (mixed $v): bool => is_int($v) || is_string($v))) . ')
  ORDER BY name ASC
;';
                $template->assign(
                    'group_mail_options',
                    array_column($conn->fetchAllAssociative($query), 'name', 'id')
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
        $all_user_ids = array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            array_column($conn->fetchAllAssociative($query), 'user_id')
        );

        if ($category['status'] === 'private') {
            $user_ids_access_indirect = [];

            if (count($group_ids) > 0) {
                $query = '
SELECT
    user_id
  FROM ' . Tables::userGroup() . '
  WHERE group_id IN (' . implode(',', array_filter($group_ids, static fn (mixed $v): bool => is_int($v) || is_string($v))) . ')
';
                $user_ids_access_indirect = array_map(
                    static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
                    array_column($conn->fetchAllAssociative($query), 'user_id')
                );
            }

            $query = '
SELECT
    user_id
  FROM ' . Tables::userAccess() . '
  WHERE cat_id = ' . $category_id . '
;';
            $user_ids_access_direct = array_map(
                static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
                array_column($conn->fetchAllAssociative($query), 'user_id')
            );

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

            $users = array_column($conn->fetchAllAssociative($query), 'username', 'id');

            $template->assign('user_options', $users);
        }

        // +-------------------------------------------------------------------+
        // |                           sending html code                       |
        // +-------------------------------------------------------------------+

        $template->assign_var_from_handle('ADMIN_CONTENT', 'album_notification');
    }
}
