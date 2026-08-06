<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Request\PictureModifyRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Cache\CachePools;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\DateHelper;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\StringHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\Location\LocEndPictureModify;
use Piwigo\Event\Picture\PictureModifyBeforeUpdate;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Lang\Translator;
use Piwigo\Metadata\MetadataService;
use Piwigo\Permission\ForbiddenCategoriesCache;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Rate\RateService;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;

/**
 * Ported from admin/picture_modify.php (the "properties" tab of the "photo"
 * page slug, dispatched by PhotoSubController). PhotoSubController threads
 * $adminPhotoBaseUrl through render() directly.
 *
 * The sync_metadata action requires a valid CSRF token (checked via
 * CsrfService::checkOrFail()), and its own template link (U_SYNC) carries
 * one, the same way U_DELETE protects itself.
 */
final class PictureModifyPageRenderer
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly ProcessCache $processCache,
        private readonly SessionService $sessionService,
        private readonly EventDispatcher $eventDispatcher,
        private readonly PageState $pageState,
        private readonly CurrentUser $currentUser,
        private readonly CurrentTemplate $currentTemplate,
        private readonly EntityManagerInterface $entityManager,
        private readonly ActivityService $activityService,
        private readonly MetadataService $metadataService,
        private readonly RateService $rateService,
        private readonly UserService $userService,
        private readonly TagService $tagService,
        private readonly CategoryService $categoryService,
        private readonly PermissionService $permissionService,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly CurrentConfig $currentConfig,
        private readonly InputValidator $inputValidator,
        private readonly Translator $translator,
        private readonly Paths $paths,
    ) {}

    public function render(string $adminPhotoBaseUrl): void
    {
        // $page['image'] starts as ImageService::getImageInfos()'s own
        // precisely-shaped return, but $row (derived from it below) later
        // has 'added_by' widened from ?int to a resolved username string
        // and a brand new 'nb_rates' key added -- it outgrows that fixed
        // shape as this method progresses, same as FilterService::
        // initializeFromRequest()'s own scratch array. Left as
        // array<string, mixed>, each read narrowed defensively at its own
        // use site (is_scalar()/is_numeric() + a default).
        /** @var array<string, mixed> $page */
        $page = [];
        $template = $this->currentTemplate->get();

        $conn = DbConnection::build();
        $imageService = new ImageService($this->lang, EntityManagerFactory::build($conn)->getRepository(ImageEntity::class), $this->activityService, $this->sessionService, $this->eventDispatcher, $this->currentConfig, $this->translator, $this->paths);
        $htmlRenderer = $this->htmlRenderer;

        $this->accessControl->checkStatus(AccessLevel::Administrator);

        $pictureModifyRequest = PictureModifyRequest::fromGlobals($this->inputValidator);

        $image_id = $pictureModifyRequest->imageId;

        // retrieving direct information about picture. This may have been
        // already done by PhotoSubController but this renderer can also be
        // reached directly.
        if (! isset($page['image'])) {
            $page['image'] = $imageService->getImageInfos($image_id, $htmlRenderer, true);
        }

        // represent
        $represented_albums = $this->categoryService->getCategoryIdsRepresentedByImage($image_id);

        // +-------------------------------------------------------------------+
        // |                             delete photo                          |
        // +-------------------------------------------------------------------+

        if ($pictureModifyRequest->deletePresent) {
            new CsrfService($this->currentConfig)
                ->checkOrFail($this->htmlRenderer, $this->redirectService);

            $imageService->deleteElements([$image_id], $this->urlService, true);
            PermissionCacheInvalidator::invalidate();

            // where to redirect the user now?
            //
            // 1. if a category is available in the URL, use it
            // 2. else use the first reachable linked category
            // 3. redirect to gallery root

            if ((bool) ($custom_context = $this->userService->getEditContext($image_id))) {
                // considering we have a context available, we fake one to build the url
                // and we replace it with the context found in the session for this image_id
                $this->redirectService->redirect(str_replace('list/1,2', $custom_context, $this->urlService->makeIndexUrl([
                    'list' => [1, 2],
                ])));
            }

            $this->redirectService->redirect($this->urlService->makeIndexUrl());
        }

        // +-------------------------------------------------------------------+
        // |                          synchronize metadata                     |
        // +-------------------------------------------------------------------+

        if ($pictureModifyRequest->syncMetadataPresent) {
            new CsrfService($this->currentConfig)
                ->checkOrFail($this->htmlRenderer, $this->redirectService);

            $this->metadataService
                ->syncMetadata([$image_id]);
            $this->pageState->addInfo($this->lang->t('Metadata synchronized from file'));
        }

        // --------------------------------------------------------- update informations
        /** @var array<string, mixed> $data */
        $data = [];
        if ($pictureModifyRequest->isSubmitted) {
            new CsrfService($this->currentConfig)
                ->checkOrFail($this->htmlRenderer, $this->redirectService);

            $data = [];
            $data['id'] = $image_id;
            $data['level'] = $pictureModifyRequest->postLevel;

            $to_sanitize_fields = [
                'name' => $pictureModifyRequest->nameField ?? '',
                'author' => $pictureModifyRequest->authorField ?? '',
                'comment' => $pictureModifyRequest->commentField ?? '',
            ];
            foreach ($to_sanitize_fields as $field => $field_value) {
                $data[$field] = ($this->currentConfig->allowHtmlDescriptions()) ? $field_value : strip_tags($field_value);
            }

            $data['date_creation'] = $pictureModifyRequest->dateCreation;

            $data = $this->eventDispatcher->dispatchChange(new PictureModifyBeforeUpdate($data))
                ->data;

            unset($data['id']);
            $imageService->updateFields(ImageId::from($image_id), $data);
            $this->entityManager->clear();

            // time to deal with tags
            $tagService = $this->tagService;

            $tag_ids = [];
            $raw_tags_post = $pictureModifyRequest->tagsRaw;
            if (! in_array($raw_tags_post, [null, false, 0, '0', '', []], true)) {
                if (is_array($raw_tags_post)) {
                    $raw_tags_post_strings = [];
                    foreach ($raw_tags_post as $raw_tag) {
                        if (is_string($raw_tag)) {
                            $raw_tags_post_strings[] = $raw_tag;
                        }
                    }
                    $tag_ids = $tagService->getTagIds($raw_tags_post_strings);
                } elseif (is_string($raw_tags_post)) {
                    $tag_ids = $tagService->getTagIds($raw_tags_post);
                }
            }
            $tagService->setTags($tag_ids, $image_id);

            // association to albums
            $associate_categories = $pictureModifyRequest->associate;
            $imageService->moveImagesToCategories([$image_id], $associate_categories);

            PermissionCacheInvalidator::invalidate();

            // thumbnail for albums
            $represent_categories = $pictureModifyRequest->represent;

            $no_longer_thumbnail_for = array_diff($represented_albums, $represent_categories);
            if (count($no_longer_thumbnail_for) > 0) {
                new CategoryService(
                    $this->lang,
                    new CategoryRepository(EntityManagerFactory::build($conn), $this->currentConfig),
                    $this->permissionService,
                    $this->currentConfig,
                    $this->eventDispatcher,
                    $this->translator,
                    new AccessLevelChecker($this->currentUser, $this->currentConfig)
                )->setRandomRepresentant($no_longer_thumbnail_for);
            }

            $new_thumbnail_for = array_diff($represent_categories, $represented_albums);
            if (count($new_thumbnail_for) > 0) {
                $this->categoryService->setRepresentativeImageForCategories(
                    array_values(array_map(intval(...), $new_thumbnail_for)),
                    $image_id
                );
                $this->entityManager->clear();
            }

            $represented_albums = $represent_categories;

            $template->assign(
                [
                    'save_success' => $this->lang->t('Photo informations updated'),
                ]
            );

            $this->activityService
                ->record('photo', $image_id, 'edit');

            // refresh page cache
            $page['image'] = $imageService->getImageInfos($image_id, $htmlRenderer, true);
        }

        // tags
        $tag_selection = $this->tagService
            ->getTagListForImage($image_id, $htmlRenderer);

        // getImageInfos($image_id, $htmlRenderer, true) fatal_errors (never returns) when the
        // photo doesn't exist, so $page['image'] is guaranteed to be a real
        // array<string, mixed> row by this point -- see $page's own
        // docblock above for why this stays a loose bag rather than
        // getImageInfos()'s own precise return shape.
        /** @var array<string, mixed> $row */
        $row = $page['image'];

        if (isset($data['date_creation'])) {
            $row['date_creation'] = $data['date_creation'];
        }

        $storage_category_id = null;
        if (is_numeric($row['storage_category_id']) && (int) $row['storage_category_id'] !== 0) {
            $raw_storage_category_id = $row['storage_category_id'];
            $storage_category_id = (is_int($raw_storage_category_id) || is_string($raw_storage_category_id)) ? (string) $raw_storage_category_id : null;
        }

        $image_file = $row['file'];

        // +-------------------------------------------------------------------+
        // |                             template init                         |
        // +-------------------------------------------------------------------+

        $template->set_filenames(
            [
                'picture_modify' => 'picture_modify.tpl',
            ]
        );

        $admin_url_start = $adminPhotoBaseUrl . '-properties';

        $src_image = new SrcImage($row);

        // in case the photo needs a rotation of 90 degrees (clockwise or counterclockwise), we switch width and height
        if (in_array(is_numeric($row['rotation']) ? (int) $row['rotation'] : null, [1, 3], true)) {
            [$row['width'], $row['height']] = [$row['height'], $row['width']];
        }

        $post_name = $pictureModifyRequest->nameField;
        $name_value = $post_name !== null ? stripslashes($post_name) : (is_string($row['name'] ?? null) ? $row['name'] : '');

        $post_author = $pictureModifyRequest->authorField;
        $author_value = $post_author !== null ? stripslashes($post_author) : (is_string($row['author'] ?? null) && $row['author'] !== '' ? $row['author'] : '');

        $post_comment = $pictureModifyRequest->commentField;
        $comment_value = $post_comment !== null ? stripslashes($post_comment) : (is_string($row['comment'] ?? null) && $row['comment'] !== '' ? $row['comment'] : '');

        $template->assign(
            [
                'tag_selection' => $tag_selection,
                'U_DOWNLOAD' => 'action.php?id=' . $image_id . '&amp;part=e&amp;pwg_token=' . new CsrfService($this->currentConfig)->getToken(),
                'U_SYNC' => $admin_url_start . '&amp;sync_metadata=1&amp;pwg_token=' . new CsrfService($this->currentConfig)->getToken(),
                'U_DELETE' => $admin_url_start . '&amp;delete=1&amp;pwg_token=' . new CsrfService($this->currentConfig)->getToken(),
                'U_HISTORY' => $this->urlService->getRootUrl() . 'admin.php?page=history&amp;filter_image_id=' . $image_id,
                'U_ACTIVITY' => $this->urlService->getRootUrl() . 'admin.php?page=user_activity&photo=' . $image_id,

                'PATH' => $row['path'],

                'TN_SRC' => DerivativeImage::url(ImageStdParams::MEDIUM, $src_image),
                'FILE_SRC' => DerivativeImage::url(ImageStdParams::LARGE, $src_image),

                'NAME' => $name_value,

                'TITLE' => $htmlRenderer->renderElementName($row),

                'DIMENSIONS' => (is_scalar($row['width']) ? (string) $row['width'] : '') . ' * ' . (is_scalar($row['height']) ? (string) $row['height'] : ''),

                'FORMAT' => ($row['width'] >= $row['height']) ? 1 : 0, // 0:horizontal, 1:vertical

                'FILESIZE' => (is_scalar($row['filesize']) ? (string) $row['filesize'] : '') . ' KB',

                'REGISTRATION_DATE' => DateHelper::formatDate(is_string($row['date_available']) || is_int($row['date_available']) ? $row['date_available'] : false),

                'AUTHOR' => htmlspecialchars($author_value),

                'DATE_CREATION' => $row['date_creation'],

                'DESCRIPTION' => htmlspecialchars($comment_value),

                'F_ACTION' => $this->urlService->getRootUrl() . 'admin.php'
                    . $this->urlService->getQueryStringDiff(['sync_metadata']),
            ]
        );

        $added_by = 'N/A';
        $row_added_by = UserId::tryFrom($row['added_by']);
        $added_by_username = $row_added_by === null ? null : (new UserRepository(EntityManagerFactory::build($conn), $this->eventDispatcher, $this->currentConfig))
            ->findUsernameById($row_added_by);
        if ($added_by_username !== null) {
            $row['added_by'] = $added_by_username->value;
        }

        $row_file = is_string($row['file']) ? $row['file'] : '';
        $extTab = explode('.', $row_file);

        $intro_vars = [
            'file' => $this->lang->t('%s', $row_file),
            'date' => $this->lang->t('Posted the %s', DateHelper::formatDate(is_string($row['date_available']) || is_int($row['date_available']) ? $row['date_available'] : false, ['day', 'month', 'year'])),
            'age' => $this->lang->t(ucfirst(DateHelper::timeSince(is_string($row['date_available']) || is_int($row['date_available']) ? $row['date_available'] : '', 'year'))),
            'added_by' => $this->lang->t('Added by %s', $row['added_by']),
            'size' => $this->lang->t('%s pixels, %.2f MB', (is_scalar($row['width']) ? (string) $row['width'] : '') . '&times;' . (is_scalar($row['height']) ? (string) $row['height'] : ''), (is_numeric($row['filesize']) ? (float) $row['filesize'] : 0.0) / 1024.0),
            'stats' => $this->lang->t('Visited %d times', $row['hit']),
            'id' => $this->lang->t(is_string($row['id']) ? $row['id'] : ''),
            'ext' => $this->lang->t('%s file type', strtoupper(end($extTab))),
            'is_svg' => (strtoupper(end($extTab)) === 'SVG'),
        ];

        if ($this->currentConfig->rateEnabled() && ! in_array($row['rating_score'], [null, false, 0, 0.0, '0', '', []], true)) {
            $row['nb_rates'] = $this->rateService->countRatesForElement($image_id);

            $intro_vars['stats'] .= ', ' . sprintf($this->lang->t('Rated %d times, score : %.2f'), $row['nb_rates'], is_numeric($row['rating_score']) ? (float) $row['rating_score'] : 0.0);
        }

        $formats = EntityManagerFactory::build($conn)->getRepository(ImageEntity::class)
            ->findFormatsForImage(ImageId::from($image_id));

        if ($formats !== []) {
            $format_strings = [];

            foreach ($formats as $format) {
                $format_strings[] = sprintf('%s (%.2fMB)', $format->ext, ((float) ($format->filesize ?? 0)) / 1024.0);
            }

            $intro_vars['formats'] = $this->lang->t('Formats: %s', implode(', ', $format_strings));
        }

        $template->assign('INTRO', $intro_vars);

        $row_path = is_string($row['path']) ? $row['path'] : null;
        $picture_ext = $this->currentConfig->pictureExtensions();
        if (in_array(StringHelper::getExtension($row_path), $picture_ext, true)) {
            $template->assign('U_COI', $this->urlService->getRootUrl() . 'admin.php?page=picture_coi&amp;image_id=' . $image_id);
        }

        // image level options
        $selected_level = $pictureModifyRequest->postLevel ?? $row['level'];
        $template->assign(
            [
                'level_options' => PermissionService::getPrivacyLevelOptions($this->currentConfig, $this->lang),
                'level_options_selected' => [$selected_level],
            ]
        );

        // categories
        $related_categories = [];
        $related_categories_ids = [];

        foreach ($imageService->getCategoryLinksForImage(ImageId::from($image_id)) as $cat_row) {
            $raw_row_category_id = $cat_row['category_id'];
            $row_category_id = (is_int($raw_row_category_id) || is_string($raw_row_category_id)) ? (string) $raw_row_category_id : '';
            $row_uppercats = is_string($cat_row['uppercats']) ? $cat_row['uppercats'] : '';

            $name =
              $htmlRenderer->getCatDisplayNameCache(
                  $row_uppercats,
                  $this->urlService->getRootUrl() . 'admin.php?page=album-'
              );

            if ($row_category_id === $storage_category_id) {
                $template->assign('STORAGE_CATEGORY', $name);
            }

            $related_categories[$row_category_id] = [
                'name' => $name,
                'unlinkable' => $row_category_id !== $storage_category_id,
            ];
            $related_categories_ids[] = $row_category_id;
        }

        $template->assign('related_categories', $related_categories);
        $template->assign('related_categories_ids', $related_categories_ids);

        // jump to link
        //
        // 1. if an edit_context is available, we use it (without checking permissions)
        // 2. else if user level is higher than image level, randomly find an authorized category
        // 3. else no jumpto link

        // re-derived from $page['image'] rather than $row: $row still holds the
        // image row at this point, but its 'level' value may be stale after the
        // POST-handling branch above already reassigned $page['image'].
        $image_level = 0;
        if (is_array($page['image']) && is_numeric($page['image']['level'] ?? null)) {
            $image_level = (int) $page['image']['level'];
        }

        if ((bool) ($custom_context = $this->userService->getEditContext($image_id))) {
            $template->assign('U_JUMPTO', $this->urlService->makePictureUrl([
                'image_id' => $image_id,
            ]) . '/' . $custom_context);
        } elseif ($this->currentUser->get()->level >= $image_level) {
            $authorized_category_ids = array_map(
                strval(...),
                $imageService->getCategoryIdsForImage(ImageId::from($image_id))
            );

            $authorizeds = array_diff(
                $authorized_category_ids,
                explode(
                    ',',
                    new ForbiddenCategoriesCache($this->permissionService, CachePools::permissions())
                        ->getForUser($this->currentUser->get()->id->value, $this->currentUser->get()->status->value)
                )
            );

            if (count($authorizeds) > 0) {
                $authorizeds_values = array_values($authorizeds);
                $category = $authorizeds_values[random_int(0, count($authorizeds_values) - 1)];

                $cat_names_raw = $this->processCache->get('cat_names');
                $cat_names = is_array($cat_names_raw) ? $cat_names_raw : [];
                $url_img = $this->urlService->makePictureUrl(
                    [
                        'image_id' => $image_id,
                        'image_file' => $image_file,
                        'category' => $cat_names[$category] ?? null,
                    ]
                );

                $template->assign('U_JUMPTO', $url_img);
            }
        }

        // associate to albums
        $associated_albums = $imageService->getAssociatedCategoryIds(ImageId::from($image_id));

        $template->assign([
            'associated_albums' => $associated_albums,
            'represented_albums' => $represented_albums,
            'STORAGE_ALBUM' => $storage_category_id,
            'CACHE_KEYS' => AdminUiHelper::getAdminClientCacheKeys($this->urlService, ['tags', 'categories']),
            'PWG_TOKEN' => new CsrfService($this->currentConfig)
                ->getToken(),
        ]);

        $this->eventDispatcher->dispatchNotify(new LocEndPictureModify());

        // ----------------------------------------------------------- sending html code
        $template->assign_var_from_handle('ADMIN_CONTENT', 'picture_modify');
    }
}
