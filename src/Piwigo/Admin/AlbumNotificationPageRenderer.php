<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\Translator;
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
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
    ) {}

    /**
     * $category is AlbumSubController::handle()'s own
     * {@see \Piwigo\Category\Projection\Category::toArray()} result, shared
     * verbatim with CatModifyPageRenderer/CatPermPageRenderer's own
     * render() calls from that same dispatch site.
     *
     * @param array{id: int, name: string, id_uppercat: ?int, comment: ?string,
     *   dir: ?string, rank: ?int, status: string, site_id: ?int, visible: bool,
     *   representative_picture_id: ?int, uppercats: string, commentable: bool,
     *   global_rank: ?string, image_order: ?string, permalink: ?string, lastmodified: string} $category
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

        $category_id = $category['id'];
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
        $albumNotificationSubmit = Request\AlbumNotificationSubmitRequest::fromGlobals();

        if ($albumNotificationSubmit->isSubmitted) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(\Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->redirectService);
            $this->urlService->setMakeFullUrl();

            $img = [];

            // Known limitation: when $category['representative_picture_id']
            // is empty, no image is shown -- there's no descendant-fallback
            // lookup ("use a child album's representative instead"), only
            // a direct-representative check. Not a defect, just a smaller
            // feature than a full recursive lookup would be.
            if ($category['representative_picture_id'] !== null && $category['representative_picture_id'] !== 0) {
                $query = '
SELECT id, file, path, representative_ext
  FROM ' . Tables::images() . '
  WHERE id = ' . $category['representative_picture_id'] . '
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

            $mail_content = $albumNotificationSubmit->mailContent;

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

            if ($albumNotificationSubmit->who === 'users' and $albumNotificationSubmit->users !== []) {
                // No real privacy issue sending this notification to a user
                // without access to the album: the email itself carries no
                // private content beyond a public category name/link.
                $post_user_ids = $albumNotificationSubmit->users;

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
                    $authkey = \Piwigo\Bootstrap\CoreDomainAccessor::authService()
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
                    if (isset($authkey['auth_key'])) {
                        $user_args['auth_key'] = $authkey['auth_key'];
                    }

                    $user_language = is_string($u['language']) ? $u['language'] : \Piwigo\Bootstrap\CoreDomainAccessor::userService()->getDefaultLanguage();
                    $user_email = is_string($u['email']) ? $u['email'] : '';

                    \Piwigo\Bootstrap\PresentationAccessor::mailService()
                        ->switchLangTo($user_language);
                    \Piwigo\Bootstrap\PresentationAccessor::mailService()
                        ->mail($user_email, $user_args, $user_tpl);
                    \Piwigo\Bootstrap\PresentationAccessor::mailService()
                        ->switchLangBack();
                }

                $message = Translator::get()->plural('%d mail was sent.', '%d mails were sent.', count($users));
                $message .= ' (' . implode(', ', $usernames) . ')';

                $template->assign(
                    [
                        'save_success' => $message,
                    ]
                );
            } elseif ($albumNotificationSubmit->who === 'group' and ! in_array($albumNotificationSubmit->group, [null, false, 0, '0', '', []], true)) {
                // AlbumNotificationSubmitRequest::fromArray() already validated
                // (fatal_errors, never returns) that group matches
                // ValidationPattern::ID (digits only) when this branch is
                // reachable; the is_numeric() check here only narrows the type
                // for what follows.
                $group_id = is_numeric($albumNotificationSubmit->group) ? (int) $albumNotificationSubmit->group : 0;

                \Piwigo\Bootstrap\PresentationAccessor::mailService()
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

        // $page['cat'] was set to $category['id'] (a real int) above, in
        // this same method scope with no intervening by-reference calls,
        // so its narrowing is still provably int here ($page itself is
        // array<string, mixed>).
        $page_cat = $page['cat'];

        $template->assign(
            [
                'CATEGORIES_NAV' => trim(
                    \Piwigo\Bootstrap\PresentationAccessor::htmlService()
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
