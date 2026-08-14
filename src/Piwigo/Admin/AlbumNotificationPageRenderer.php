<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Projection\AlbumNotificationPageContext;
use Piwigo\Admin\Request\AlbumNotificationSubmitRequest;
use Piwigo\Auth\AuthService;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\DateHelper;
use Piwigo\Core\Env;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Event\Template\RenderCategoryName;
use Piwigo\Group\GroupService;
use Piwigo\Group\Projection\Group;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\Translator;
use Piwigo\Mail\MailService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;

/**
 * Ported from admin/album_notification.php (the "notification" tab of the
 * "album" page slug, dispatched by AlbumSubController).
 *
 * admin.php itself already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch (admin.php:65),
 * so the original album_notification.php's own (redundant) check_status()
 * call is dropped here -- same precedent as PhotosAddSubController.
 */
final readonly class AlbumNotificationPageRenderer
{
    public function __construct(
        private Lang $lang,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private Translator $translator,
        private EventDispatcher $eventDispatcher,
        private CurrentTemplate $currentTemplate,
        private ImageService $imageService,
        private UserService $userService,
        private AuthService $authService,
        private GroupService $groupService,
        private CategoryService $categoryService,
        private HtmlService $htmlService,
        private MailService $mailService,
        private CurrentConfig $currentConfig,
        private CsrfService $csrfService,
        private InputValidator $inputValidator,
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
        /** @var array<string, mixed> $page */
        $page = [];
        $template = $this->currentTemplate->get();
        $save_success = null;

        $category_id = $category['id'];
        $page['cat'] = $category_id;

        // info by email to an access granted group of category informations
        $albumNotificationSubmit = AlbumNotificationSubmitRequest::fromGlobals($this->inputValidator);

        if ($albumNotificationSubmit->isSubmitted) {
            $this->csrfService
                ->checkOrFail($this->htmlService, $this->redirectService);
            $this->urlService->setMakeFullUrl();

            $img = [];

            // Known limitation: when $category['representative_picture_id']
            // is empty, no image is shown -- there's no descendant-fallback
            // lookup ("use a child album's representative instead"), only
            // a direct-representative check. Not a defect, just a smaller
            // feature than a full recursive lookup would be.
            if ($category['representative_picture_id'] !== null && $category['representative_picture_id'] !== 0) {
                $element = $this->imageService->getImageRow($category['representative_picture_id']);
                if ($element !== null) {
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

            $nameEvent = $this->eventDispatcher->dispatchChange(new RenderCategoryName($category['name'], 'admin_cat_list'));
            $renderedCategoryName = $nameEvent->categoryName;

            $args = [
                'subject' => $this->lang->t('[%s] Visit album %s', $this->currentConfig->galleryTitle, $renderedCategoryName),
            ];

            $mail_content = $albumNotificationSubmit->mailContent;

            $tpl = [
                'filename' => 'cat_group_info',
                'assign' => [
                    'IMG' => $img,
                    'CAT_NAME' => $renderedCategoryName,
                    'LINK' => $this->urlService->makeIndexUrl(
                        [
                            'category' => [
                                'id' => $category['id'],
                                'name' => $renderedCategoryName,
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

                $users = $this->userService->getNotificationRecipientsByIds($post_user_ids);
                $usernames = [];

                foreach ($users as $u) {
                    // user_infos.user_id is the row's own key column; a
                    // missing value would mean a corrupt join, so skip this
                    // user rather than pass a fabricated id to create_user_auth_key().
                    if ($u->userId === null) {
                        continue;
                    }

                    $u_username = $u->username ?? '';
                    $usernames[] = $u_username;

                    $authkey = $this->authService
                        ->createUserAuthKey($u->userId->value, $u->status);

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

                    $user_language = $u->language ?? $this->userService->getDefaultLanguage();
                    $user_email = $u->email ?? '';

                    $this->mailService
                        ->switchLangTo($user_language);
                    $this->mailService
                        ->mail($user_email, $user_args, $user_tpl);
                    $this->mailService
                        ->switchLangBack();
                }

                $message = $this->translator->plural('%d mail was sent.', '%d mails were sent.', count($users));
                $message .= ' (' . implode(', ', $usernames) . ')';

                $save_success = $message;
            } elseif ($albumNotificationSubmit->who === 'group' and $albumNotificationSubmit->group !== null) {
                $groupId = $albumNotificationSubmit->group;

                $this->mailService
                    ->mailGroup($groupId->value, $args, $tpl);

                $group_name = $this->groupService->getName($groupId);

                $save_success = $this->lang->t('An information email was sent to group "%s"', $group_name);
            }

            $this->urlService->unsetMakeFullUrl();
        }

        // $page['cat'] was set to $category['id'] (a real int) above, in
        // this same method scope with no intervening by-reference calls,
        // so its narrowing is still provably int here ($page itself is
        // array<string, mixed>).
        $page_cat = $page['cat'];

        $categories_nav = trim(
            $this->htmlService
                ->getCatDisplayNameFromId(
                    $page_cat,
                    'admin.php?page=album-'
                )
        );

        // auth_key_duration is a plain int config value (see
        // include/config_default.inc.php).
        $auth_key_duration = $this->currentConfig->authKeyDuration;
        $auth_key_duration_num = $auth_key_duration;
        $auth_key_duration_value = null;
        if ($auth_key_duration_num > 0) {
            $auth_key_since = Env::now()->getTimestamp() - $auth_key_duration_num;
            $auth_key_duration_value = DateHelper::timeSince($auth_key_since, 'second', null, false);
        }

        $all_group_ids = array_map(
            static fn (Group $group): int => $group->id->value,
            $this->groupService->getAllBasic()
        );
        // group_ids stays [] (rather than undefined) when the gallery has no
        // groups at all, so the "private album" branch below can safely read it
        // unconditionally instead of guarding on definedness.
        $group_ids = [];
        $no_group_in_gallery = null;
        $permission_url = null;
        $group_mail_options = null;

        if (count($all_group_ids) === 0) {
            $no_group_in_gallery = true;
        } else {
            if ($category['status'] === 'private') {
                $permission_url = $admin_album_base_url . '-permissions';

                $group_ids = $this->categoryService->getAccessGroupIds(CategoryId::from($category_id));
            } else {
                $group_ids = $all_group_ids;
            }

            if (count($group_ids) > 0) {
                $group_mail_options = $this->groupService->getNamesByIds($group_ids);
            }
        }

        // all users with status != guest and permitted to this this album (for a
        // perfect search, we should also check that album is not only filled with
        // private photos)
        $all_user_ids = $this->userService->getUserIdsExcludingStatus('guest');

        if ($category['status'] === 'private') {
            $user_ids_access_indirect = [];

            if (count($group_ids) > 0) {
                $user_ids_access_indirect = array_map(
                    strval(...),
                    array_column($this->groupService->getMembersByGroupIds($group_ids), 'user_id')
                );
            }

            $user_ids_access_direct = array_map(
                strval(...),
                $this->categoryService->getAccessUserIds(CategoryId::from($category_id))
            );

            $user_ids_access = array_unique(array_merge($user_ids_access_direct, $user_ids_access_indirect));

            $user_ids = array_intersect($user_ids_access, $all_user_ids);
        } else {
            $user_ids = $all_user_ids;
        }

        $user_options = null;
        if (count($user_ids) > 0) {
            $user_options = $this->userService->getUsernamesByIds(array_values($user_ids));
        }

        $template->assignContext(new AlbumNotificationPageContext(
            saveSuccess: $save_success,
            categoriesNav: $categories_nav,
            fAction: $admin_album_base_url . '-notification',
            pwgToken: $this->csrfService
                ->getToken(),
            authKeyDuration: $auth_key_duration_value,
            noGroupInGallery: $no_group_in_gallery,
            permissionUrl: $permission_url,
            groupMailOptions: $group_mail_options,
            userOptions: $user_options,
        ));

        $template->assignVarFromTemplate('ADMIN_CONTENT', 'album_notification.latte');
    }
}
