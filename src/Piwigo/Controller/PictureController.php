<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\CookieService;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Bootstrap\PageTail;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Caddie\CaddieService;
use Piwigo\Category\CategoryService;
use Piwigo\Category\Projection\CategoryIdNamePermalink;
use Piwigo\Comment\CommentEntity;
use Piwigo\Comment\CommentService;
use Piwigo\Common\Enum\Section;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Controller\Event\AllowIncrementElementHitCount;
use Piwigo\Controller\Event\GetElementMetadataAvailable;
use Piwigo\Controller\Event\PicturePageRendered;
use Piwigo\Controller\Event\PicturePageRendering;
use Piwigo\Controller\Event\PicturePicturesData;
use Piwigo\Controller\Event\RenderElementContent;
use Piwigo\Controller\Projection\CanonicalUrlPageContext;
use Piwigo\Controller\Projection\PictureContentView;
use Piwigo\Controller\Projection\PictureHeaderPageContext;
use Piwigo\Controller\Projection\PictureView;
use Piwigo\Controller\Projection\SlideshowView;
use Piwigo\Controller\Request\PictureRequest;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\DateHelper;
use Piwigo\Core\FilterState;
use Piwigo\Core\Lang;
use Piwigo\Core\LayoutState;
use Piwigo\Core\MailerInterface;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\StringHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\NoMatchSentinel;
use Piwigo\History\HistoryService;
use Piwigo\Html\Event\RenderElementDescription;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImagePathHelper;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\Projection\ImageFormat;
use Piwigo\Image\Projection\SrcImageInfo;
use Piwigo\Image\Projection\VisibleCategoryRow;
use Piwigo\Image\SrcImage;
use Piwigo\Lang\Translator;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Permission\PermissionService;
use Piwigo\Picture\Event\FilterPictureDisplayInfo;
use Piwigo\Picture\PictureCommentRenderer;
use Piwigo\Picture\PictureMetadataRenderer;
use Piwigo\Picture\PictureRateRenderer;
use Piwigo\Picture\Projection\MetadataPanel;
use Piwigo\Picture\Projection\PictureCommentsResult;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Rate\RateService;
use Piwigo\Section\SectionContext;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Section\SectionPopulator;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * Replaces picture.php -- the single-photo detail page. Its event handler
 * for rendering default picture content is registered via
 * add_event_handler()'s first-class-callable form
 * ($this->defaultPictureContent(...)); add_event_handler() accepts
 * `array{0: object|string, 1: string}|object|string`, so a bound instance
 * method works directly without a string-name registration.
 *
 * The "actions" switch (favorite/caddie/rate/comment moderation) is the
 * last place any exit()-capable call happens before the render body. Not
 * every case redirects, though: edit_comment falls through to the render
 * body without calling redirect() whenever no comment content was
 * submitted (i.e. just displaying the edit-comment form itself).
 * Picture\PictureCommentRenderer::render()'s own die() calls (reached
 * from within this body) throw ResponseReadyException.
 *
 * The render body is flat code directly in this method: it renders a
 * `PictureView`/`SlideshowView` via `Renderer::render()` in one shot --
 * `picture.latte`/`slideshow.latte` both declare `{layout 'layout.latte'}`,
 * so `PageHeaderRenderer::prepareContext()`/`PageTail::prepareContext()`
 * only prepare ambient context, and `Template::finalizeHtml()` produces
 * the final string -- the same mechanism
 * Controller\AboutController/PopuphelpController use.
 * $urlService/$configService keep their own local aliases, referenced
 * throughout the body, rather than $this->urlService/$this->configService
 * everywhere.
 *
 * Like GalleryController, Piwigo\Section\SectionPopulator::populate()
 * must run before check_status(): check_restrictions() below depends on
 * SectionContextRegistry::current()->category already being populated.
 */
final readonly class PictureController implements ControllerInterface
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private ConfigService $configService,
        private FilterState $filterState,
        private CurrentLogger $currentLogger,
        private SectionContextRegistry $sectionContextRegistry,
        private SessionService $sessionService,
        private EventDispatcher $eventDispatcher,
        private DeploymentPolicy $deploymentPolicy,
        private ImageStdParams $imageStdParams,
        private PageState $pageState,
        private LayoutState $layoutState,
        private CurrentUser $currentUser,
        private CurrentTemplate $currentTemplate,
        private MailerInterface $mailer,
        private EntityManagerInterface $entityManager,
        private SectionPopulator $sectionPopulator,
        private ActivityService $activityService,
        private RateService $rateService,
        private HistoryService $historyService,
        private PermissionService $permissionService,
        private CategoryService $categoryService,
        private TagService $tagService,
        private UserService $userService,
        private ImageService $imageService,
        private HtmlService $htmlService,
        private PictureRateRenderer $pictureRateRenderer,
        private CurrentConfig $currentConfig,
        private CsrfService $csrfService,
        private InputValidator $inputValidator,
        private Translator $translator,
        private Paths $paths,
        private Renderer $renderer,
    ) {}

    private function commentService(UrlServiceInterface $urlService): CommentService
    {
        return new CommentService($this->lang, $this->entityManager->getRepository(CommentEntity::class), new EphemeralKeyService($this->currentConfig), $this->mailer, $this->htmlService, $urlService, $this->eventDispatcher, $this->pageState, $this->currentUser, $this->currentConfig, new AccessLevelChecker($this->currentUser, $this->currentConfig));
    }

    /**
     * `U_ORIGINAL` -- the linked-original-file URL, shown only when a
     * real `element_url` exists and every displayed derivative differs
     * from the source file (`!$derivative->sameAsSource()`). Shared by
     * `defaultPictureContent()` (its own real owner) and `__invoke()`
     * (which needs the same value for `PictureView::$uOriginal` -- see
     * that class's own docblock for why).
     *
     * @param array{derivatives: array<string, DerivativeImage>, element_url?: string, ...} $element_info
     */
    private function computeUOriginal(array $element_info): ?string
    {
        if (! isset($element_info['element_url'])) {
            return null;
        }

        foreach ($element_info['derivatives'] as $type => $derivative) {
            if ($type === ImageStdParams::SQUARE || $type === ImageStdParams::THUMB) {
                continue;
            }
            if (! array_key_exists($type, $this->imageStdParams->getDefinedTypeMap())) {
                continue;
            }
            if ($derivative->sameAsSource()) {
                return null;
            }
        }

        return $element_info['element_url'];
    }

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $this->sectionPopulator
            ->populate();

        $template = $this->currentTemplate->get();

        // populate() always calls SectionContextRegistry::set() as the
        // very last thing it does, so this is guaranteed non-null by the
        // time this controller (populate()'s one other caller besides
        // GalleryController) runs -- a real guard, not dead code, since
        // the type itself is nullable.
        $section_context = $this->sectionContextRegistry->current();
        if (! $section_context instanceof SectionContext) {
            throw new RuntimeException('SectionContextRegistry::current() is null after SectionPopulator::populate()');
        }

        $this->userService->saveEditContext($section_context->sectionUrl, $section_context->imageId);

        $this->accessControl->checkStatus(AccessLevel::Guest);

        $page_category = $section_context->category;

        // access authorization check
        if ($page_category !== null) {
            $this->categoryService->checkRestrictions($page_category->id, $this->htmlService, $this->redirectService, $this->currentUser);
        }

        // $section_context->items is mutated in place below (best_rated
        // fallback) so it is kept as a local variable -- SectionContext
        // itself is a readonly snapshot, so the mutated list is
        // re-registered via withItems() for downstream readers of
        // SectionContextRegistry::current() (e.g. MenubarRenderer's
        // related-categories block) to see, further down.
        $items = $section_context->items;
        $rank_of = array_flip($items);

        $image_id = $section_context->imageId;
        $image_id = is_numeric($image_id) ? (int) $image_id : 0;
        $image_file = $section_context->imageFile;

        $user = $this->currentUser->get();

        // if this image_id doesn't correspond to this category, an error
        // message is displayed, and execution is stopped
        if (! isset($rank_of[$image_id])) {
            if ($image_id > 0) {
                $file_pattern = null;
            } else {// url given by file name
                assert($image_file !== null && $image_file !== '');
                $file_pattern = $image_file;
            }
            $row = $this->imageService->findByIdOrFilePattern($image_id, $file_pattern);
            if ($row === false) {// element does not exist
                $this->htmlService
                    ->pageNotFound(
                        $this->redirectService,
                        'The requested image does not exist',
                        $this->urlService->duplicateIndexUrl()
                    );
            }
            $user_level = $user->level;
            if ($row->level > $user_level) {
                $this->htmlService
                    ->accessDenied($this->redirectService);
            }

            $image_id = $row->id->value;
            $image_file = $row->file;
            if (! isset($rank_of[$image_id])) {// the image can still be non accessible (filter/cat perm) and/or not in the set
                // FilterState::visibleImages() always returns a string; if
                // the filter has not been initialized yet, there is no
                // restriction (fallback to ''). NoMatchSentinel::ID_STRING
                // means "the filter computed an empty visible-images list"
                // (see FilterService's own "Must be not empty" comment),
                // not a real id to match against, so it is excluded
                // explicitly alongside ''.
                $visible_images = $this->filterState->isInitialized() ? $this->filterState->visibleImages() : '';
                if ($visible_images !== '' && $visible_images !== NoMatchSentinel::ID_STRING &&
                  ! in_array((string) $image_id, explode(',', $visible_images), true)) {
                    $this->htmlService
                        ->pageNotFound(
                            $this->redirectService,
                            'The requested image is filtered',
                            $this->urlService->duplicateIndexUrl()
                        );
                }
                $page_section = $section_context->section;
                if ($page_section === Section::Categories and $page_category === null) {// flat view - all items
                    $this->htmlService
                        ->accessDenied($this->redirectService);
                } else {// try to see if we can access it differently
                    $accessible = $this->imageService->isImageAccessibleWithCondition(
                        ImageId::from($image_id),
                        $this->permissionService->getPermissionCriteria()
                    );
                    if (! $accessible) {
                        $this->htmlService
                            ->accessDenied($this->redirectService);
                    } else {
                        if ($page_section === Section::BestRated) {
                            $rank_of[$image_id] = count($items);
                            $items[] = $image_id;
                            $section_context = $section_context->withItems($items);
                            $this->sectionContextRegistry->set($section_context);
                        } else {
                            $url = $this->urlService->makePictureUrl(
                                [
                                    'image_id' => $image_id,
                                    'image_file' => $image_file,
                                    'section' => 'categories',
                                    'flat' => true,
                                ]
                            );
                            // The legacy equivalent's own raw
                            // set_status_header()+redirect_http() pair
                            // worked because legacy redirect_http() never
                            // re-issued the status line itself -- the
                            // earlier header() call stuck. This migration's
                            // Response-object model does re-issue it:
                            // RedirectServiceInterface::redirectHttp()'s own
                            // $status defaults to 302, and
                            // Http\ResponseEmitter::emit() always sends
                            // `header(..., true, $response->getStatusCode())`,
                            // which unconditionally overwrites whatever a
                            // prior setStatusHeader() call already sent --
                            // every recent_pics redirect came
                            // back 302, never the intended 301. Passing the
                            // real status straight into redirectHttp() (which
                            // already accepts one) is the fix; the separate
                            // setStatusHeader() call is dropped as dead
                            // weight, not left in as a no-op.
                            $this->redirectService->redirectHttp($url, $page_section === Section::RecentPics ? 301 : 302);
                        }
                    }
                }
            }
        }

        $pictureRequest = PictureRequest::fromGlobals($this->inputValidator);
        // Mutated below (the edit_comment case's own successful-submission
        // path) to reflect the original's unset($_POST['content']) --
        // read again much further down to decide whether to increment the
        // hit counter.
        $content_present = $pictureRequest->contentPresent;

        // There is cookie, so we must handle it at the beginning
        if ($pictureRequest->metadataPresent) {
            if (! $this->sessionService->isShowMetadataEnabled()) {
                $this->sessionService->setSessionVar('show_metadata', 1);
            } else {
                $this->sessionService->unsetSessionVar('show_metadata');
            }
        }

        // add default event handler for rendering element content
        $this->eventDispatcher->addTypedHandler(RenderElementContent::class, $this->defaultPictureContent(...));
        // add default event handler for rendering element description --
        // pwgNl2br() is reused across two different events (this one and
        // RenderCategoryDescription in RequestBootstrap.php), so this is a
        // thin adapter closure, leaving pwgNl2br() itself untouched.
        $this->eventDispatcher->addTypedHandler(
            RenderElementDescription::class,
            function (RenderElementDescription $e): RenderElementDescription {
                $result = $this->htmlService->pwgNl2br($e->elementDescription);
                $e->elementDescription = is_string($result) ? $result : '';

                return $e;
            },
        );

        $this->eventDispatcher->dispatch(new PicturePageRendering());

        // caching first_rank, last_rank, current_rank in the displayed
        // section. This should also help in readability.
        $first_rank = 0;
        $last_rank = count($items) - 1;
        // the isset($rank_of[$image_id]) checks above (falling through to
        // page_not_found()/access_denied() otherwise) already guarantee
        // this key exists by this point.
        $current_rank = $rank_of[$image_id];

        $previous_item = null;
        $first_item = null;
        if ($current_rank !== $first_rank) {
            // caching first & previous item : readability purpose
            $previous_item = $items[$current_rank - 1];
            $first_item = $items[$first_rank];
        }

        $next_item = null;
        $last_item = null;
        if ($current_rank !== $last_rank) {
            // caching next & last item : readability purpose
            $next_item = $items[$current_rank + 1];
            $last_item = $items[$last_rank];
        }

        $nb_image_page = $section_context->nbImagePage;
        $url_up = $this->urlService->duplicateIndexUrl(
            [
                'start' => floor($current_rank / $nb_image_page)
                  * (float) $nb_image_page,
            ],
            [
                'start',
            ]
        );

        $url_self = $this->urlService->duplicatePictureUrl();

        // Set by the 'edit_comment' action case below (only when
        // can_manage_comment('edit', ...) passes), threaded explicitly into
        // PictureCommentRenderer::render() further down -- see that
        // class's own docblock for the bare-scope bug this replaced.
        $edit_comment = null;

        /**
         * Actions are favorite adding, user comment deletion, setting the
         * picture as representative of the current category...
         *
         * Actions finish by a redirection
         */
        if ($pictureRequest->action !== null) {
            switch ($pictureRequest->action) {
                case 'add_to_favorites':

                    $this->userService->addFavorite($user->id, $image_id);

                    $this->redirectService->redirect($url_self);

                    // no break
                case 'remove_from_favorites':

                    $this->userService->removeFavorite($user->id, $image_id);

                    if ($section_context->section === Section::Favorites) {
                        $this->redirectService->redirect($url_up);
                    } else {
                        $this->redirectService->redirect($url_self);
                    }

                    // no break
                case 'set_as_representative':

                    if ($this->accessControl->isAdmin() and $page_category !== null) {
                        $representative_category_id = $page_category->id;
                        $this->categoryService->setRepresentativeImage($representative_category_id, $image_id);
                        $this->entityManager->clear();
                        $this->activityService->record('album', $representative_category_id, 'edit', [
                            'action' => $pictureRequest->action,
                            'image_id' => $image_id,
                        ]);

                        PermissionCacheInvalidator::invalidate();
                    }

                    $this->redirectService->redirect($url_self);

                    // no break
                case 'add_to_caddie':

                    CaddieService::fillCurrentUserCaddie([$image_id], $this->currentUser, $this->entityManager);
                    $this->redirectService->redirect($url_self);

                    // no break
                case 'rate':

                    $this->rateService
                        ->rate($image_id, $pictureRequest->rate, $this->entityManager);
                    $this->redirectService->redirect($url_self);

                    // no break
                case 'edit_comment':

                    $commentService = $this->commentService($this->urlService);
                    // check_input_parameter()-equivalent validation already ran
                    // inside PictureRequest::fromArrays() (gated on action ===
                    // 'edit_comment') -- it would have thrown otherwise.
                    assert($pictureRequest->commentToEdit !== null && is_numeric($pictureRequest->commentToEdit));
                    // false is unreachable here: $die_on_error defaults to
                    // true, and getCommentAuthorId() calls
                    // fatal_error() (never) in that case instead of
                    // returning
                    $author_id = $commentService->getCommentAuthorId(CommentId::from((int) $pictureRequest->commentToEdit));
                    assert($author_id !== false);

                    if ($this->accessControl->canManageComment('edit', $author_id)) {
                        // $_POST values are always strings (or arrays) --
                        // never a real PHP int/float/bool.
                        if ($pictureRequest->content !== null && $pictureRequest->content !== '' && $pictureRequest->content !== '0') {
                            $this->csrfService
                                ->checkOrFail($this->htmlService, $this->redirectService);
                            $comment_action = $commentService->updateComment(
                                [
                                    'comment_id' => $pictureRequest->commentToEdit,
                                    'image_id' => $image_id,
                                    'content' => $pictureRequest->content,
                                    'website_url' => $pictureRequest->websiteUrl,
                                ],
                                $pictureRequest->postKey
                            );

                            $perform_redirect = false;
                            switch ($comment_action) {
                                case 'moderate':
                                    if (! isset($_SESSION['page_infos']) or ! is_array($_SESSION['page_infos'])) {
                                        $_SESSION['page_infos'] = [];
                                    }
                                    $_SESSION['page_infos'][] = $this->lang->t('An administrator must authorize your comment before it is visible.');
                                    // no break
                                case 'validate':
                                    if (! isset($_SESSION['page_infos']) or ! is_array($_SESSION['page_infos'])) {
                                        $_SESSION['page_infos'] = [];
                                    }
                                    $_SESSION['page_infos'][] = $this->lang->t('Your comment has been registered');
                                    $perform_redirect = true;
                                    break;
                                case 'reject':
                                    if (! isset($_SESSION['page_errors']) or ! is_array($_SESSION['page_errors'])) {
                                        $_SESSION['page_errors'] = [];
                                    }
                                    $_SESSION['page_errors'][] = $this->lang->t('Your comment has NOT been registered because it did not pass the validation rules');
                                    break;
                                default:
                                    trigger_error('Invalid comment action ' . $comment_action, E_USER_WARNING);
                            }

                            if ($perform_redirect) {
                                $this->redirectService->redirect($url_self);
                            }
                            $content_present = false;
                        }

                        // check_input_parameter()/assert() above already
                        // proved comment_to_edit is numeric.
                        $edit_comment = CommentId::from((int) $pictureRequest->commentToEdit);
                    }
                    break;

                case 'delete_comment':

                    $this->csrfService
                        ->checkOrFail($this->htmlService, $this->redirectService);

                    $commentService = $this->commentService($this->urlService);

                    // check_input_parameter()-equivalent validation already ran
                    // inside PictureRequest::fromArrays() (gated on action ===
                    // 'delete_comment') -- it would have thrown otherwise.
                    assert($pictureRequest->commentToDelete !== null && is_numeric($pictureRequest->commentToDelete));

                    // false is unreachable here: see the edit_comment case
                    // above
                    $author_id = $commentService->getCommentAuthorId(CommentId::from((int) $pictureRequest->commentToDelete));
                    assert($author_id !== false);

                    if ($this->accessControl->canManageComment('delete', $author_id)) {
                        $commentService->deleteComment(CommentId::from((int) $pictureRequest->commentToDelete));
                    }

                    $this->redirectService->redirect($url_self);

                    // no break
                case 'validate_comment':

                    $this->csrfService
                        ->checkOrFail($this->htmlService, $this->redirectService);

                    $commentService = $this->commentService($this->urlService);

                    // check_input_parameter()-equivalent validation already ran
                    // inside PictureRequest::fromArrays() (gated on action ===
                    // 'validate_comment') -- it would have thrown otherwise.
                    assert($pictureRequest->commentToValidate !== null && is_numeric($pictureRequest->commentToValidate));

                    // false is unreachable here: see the edit_comment case
                    // above
                    $author_id = $commentService->getCommentAuthorId(CommentId::from((int) $pictureRequest->commentToValidate));
                    assert($author_id !== false);

                    if ($this->accessControl->canManageComment('validate', $author_id)) {
                        $commentService->validateComment(CommentId::from((int) $pictureRequest->commentToValidate));
                    }

                    $this->redirectService->redirect($url_self);

            }
        }

        $urlService = $this->urlService;
        $configService = $this->configService;
        // $title/$refresh/$url_link are set and read entirely within
        // this method (passed straight into PageHeaderRenderer::
        // render() below); no other file reads them. $url_self/$picture/
        // $related_categories are real bridges to PictureCommentRenderer/
        // PictureRateRenderer/PictureMetadataRenderer, threaded into
        // their own render() calls below as real parameters.
        $refresh = null;
        $url_link = null;
        $template = $this->currentTemplate->get();

        $inc_hit_count = ! $content_present;
        // don't increment counter if in the Mozilla Firefox prefetch
        $http_x_moz = $_SERVER['HTTP_X_MOZ'] ?? null;
        if (is_string($http_x_moz) and $http_x_moz === 'prefetch') {
            $inc_hit_count = false;
        } else {
            // don't increment counter if comming from the same picture
            // (actions)
            if ($this->sessionService->getRefererImageId() === $image_id) {
                $inc_hit_count = false;
            }
            $this->sessionService->setSessionVar('referer_image_id', $image_id);
        }

        // don't increment if adding a comment
        if ($this->eventDispatcher->dispatch(new AllowIncrementElementHitCount($inc_hit_count, ImageId::from($image_id)))->incHitCount) {
            $this->entityManager->getRepository(ImageEntity::class)
                ->incrementVisitCounter(ImageId::from($image_id));
        }

        $relatedCategoryRows = $this->imageService->getVisibleCategoriesForImage(
            ImageId::from($image_id),
            $this->permissionService->getPermissionCriteria()
        );
        $related_categories = $relatedCategoryRows;
        usort($related_categories, CategoryService::compareByGlobalRank(...));
        $picture = [];

        $ids = [(string) $image_id];
        if ($previous_item !== null) {
            $ids[] = (string) $previous_item;
            $ids[] = (string) $first_item;
        }
        if ($next_item !== null) {
            $ids[] = (string) $next_item;
            $ids[] = (string) $last_item;
        }

        foreach ($this->entityManager->getRepository(ImageEntity::class)->findByIds($ids) as $imageRow) {
            $row = $imageRow->toArray();
            if ($previous_item !== null and $imageRow->id->value === (int) $previous_item) {
                $i = 'previous';
            } elseif ($next_item !== null and $imageRow->id->value === (int) $next_item) {
                $i = 'next';
            } elseif ($first_item !== null and $imageRow->id->value === (int) $first_item) {
                $i = 'first';
            } elseif ($last_item !== null and $imageRow->id->value === (int) $last_item) {
                $i = 'last';
            } else {
                $i = 'current';
            }

            $row['src_image'] = new SrcImage(SrcImageInfo::fromRow($row));
            $row['derivatives'] = DerivativeImage::getAll($row['src_image']);

            $row['path_ext'] = strtolower(StringHelper::getExtension($row['path']));
            $row['file_ext'] = strtolower(StringHelper::getExtension($row['file']));

            if ($i === 'current') {
                $row['element_path'] = ImagePathHelper::getElementPath($imageRow->path, $urlService, $this->paths);

                $row_id = $row['id'];

                if ($row['src_image']->isOriginal()) {// we have a photo
                    if ($this->currentUser->get()->enabledHigh) {
                        $row['element_url'] = $row['src_image']->getUrl();
                        $row['download_url'] = $urlService->getActionUrl($row_id, 'e', true);
                    }
                } else { // not a pic - need download link
                    $row['element_url'] = $urlService->getElementUrl($row);
                    $row['download_url'] = $urlService->getActionUrl($row_id, 'e', true);
                }
            }

            $row['url'] = $urlService->duplicatePictureUrl(
                [
                    'image_id' => $row['id'],
                    'image_file' => $row['file'],
                ],
                [
                    'start',
                ]
            );

            $picture[$i] = $row;
            $picture[$i]['TITLE'] = $this->htmlService->renderElementName($row);
            $picture[$i]['TITLE_ESC'] = str_replace('"', '&quot;', $picture[$i]['TITLE']);

            if ($i === 'previous' and (string) $previous_item === (string) $first_item) {
                $picture['first'] = $picture[$i];
            }
            if ($i === 'next' and (string) $next_item === (string) $last_item) {
                // $picture[$i] (== $picture['next']) was set a few
                // lines above, this same iteration ($i doesn't change
                // within one iteration)
                assert(isset($picture[$i]));
                $picture['last'] = $picture[$i];
            }
        }

        $slideshow_params = [];
        $slideshow_url_params = [];

        if ($pictureRequest->slideshowPresent) {
            $slideshow = true;
            $this->layoutState->setMetaRobots([
                'noindex' => 1,
                'nofollow' => 1,
            ]);

            $slideshow_params = $this->imageService
                ->decodeSlideshowParams($pictureRequest->slideshow);
            $slideshow_url_params['slideshow'] = $this->imageService->encodeSlideshowParams($slideshow_params);

            if ((bool) $slideshow_params['play']) {
                $id_pict_redirect = '';
                if ($next_item !== null) {
                    $id_pict_redirect = 'next';
                } else {
                    if ((bool) $slideshow_params['repeat'] and $first_item !== null) {
                        $id_pict_redirect = 'first';
                    }
                }

                if ($id_pict_redirect !== '' and isset($picture[$id_pict_redirect])) {
                    // $refresh, $url_link and $title are required for
                    // creating an automated refresh page in
                    // header.latte
                    $refresh = $slideshow_params['period'];
                    // Plain '&', not the default '&amp;' -- this feeds
                    // page_refresh['U_REFRESH'], printed with no
                    // |noescape at layout.latte/redirect.latte (P44-C):
                    // Latte's own auto-escape now does the encoding once,
                    // at print time, so pre-encoding '&' here would
                    // double-escape it.
                    $url_link = $urlService->addUrlParams(
                        $picture[$id_pict_redirect]['url'],
                        $slideshow_url_params,
                        argSeparator: '&'
                    );
                }
            }
        } else {
            $slideshow = false;
        }
        // $image_id is always in $ids (see the query above) and
        // always hits the while loop's final `else { $i =
        // 'current'; }` branch
        assert(isset($picture['current']));
        $title = $picture['current']['TITLE'];
        $title_nb = ($current_rank + 1) . '/' . count($items);

        // metadata
        $url_metadata = $urlService->duplicatePictureUrl();
        $url_metadata = $urlService->addUrlParams($url_metadata, [
            'metadata' => null,
        ]);

        // do we have a plugin that can show metadata for something
        // else than images?
        $metadata_showable = $this->eventDispatcher->dispatch(new GetElementMetadataAvailable(
            (
                ($this->currentConfig->showExif or $this->currentConfig->showIptc)
                and ! $picture['current']['src_image']->isMimetype()
            ),
            $picture['current']
        ))->available;

        if ($pictureRequest->metadataPresent) {
            $this->layoutState->setMetaRobots([
                'noindex' => 1,
                'nofollow' => 1,
            ]);
        }

        $this->layoutState->setBodyId('thePicturePage');

        // allow plugins to change what we computed before passing data
        // to template
        /**
         * PicturePicturesData::$picture is deliberately loose
         * (array<mixed>, matching the reference) -- restate the shape a
         * well-behaved plugin is expected to preserve: one images-table
         * row (matching Image\Projection\Image::toArray()'s own real,
         * authoritative return shape -- id/hit/width/height/filesize are
         * genuinely typed int|null there, not the string|null a raw DB
         * row would have; this docblock previously claimed the latter,
         * a stale mismatch real enough to hide a live bug: PictureView
         * ::$infoVisits expects a string, and PHPStan trusted this
         * docblock's wrong `hit: string` claim instead of flagging the
         * real int flowing in uncast) per navigation slot, plus the
         * computed fields set on $row above.
         *
         * @var array<string, array{
         *     id: int,
         *     file: string,
         *     path: string,
         *     comment: string|null,
         *     author: string|null,
         *     date_creation: string|null,
         *     date_available: string|null,
         *     width: int|null,
         *     height: int|null,
         *     hit: int,
         *     filesize: int|null,
         *     src_image: SrcImage,
         *     derivatives: array<string, DerivativeImage>,
         *     url: string,
         *     download_url?: string,
         *     TITLE: string,
         *     TITLE_ESC: string,
         *     ...
         * }> $picture
         */
        $picture = $this->eventDispatcher->dispatch(new PicturePicturesData($picture))
            ->picture;

        $nav = [];
        foreach (['first', 'previous', 'next', 'last', 'current'] as $which_image) {
            if (isset($picture[$which_image])) {
                $nav[$which_image] = array_merge(
                    $picture[$which_image],
                    [
                        // Params slideshow was transmit to
                        // navigation buttons
                        'U_IMG' => $urlService->addUrlParams(
                            $picture[$which_image]['url'],
                            $slideshow_url_params
                        ),
                    ]
                );
            }
        }
        $download_url = $picture['current']['download_url'] ?? null;
        $download_url_present = is_string($download_url) && $download_url !== '' && $download_url !== '0';
        /** @var list<array<string, mixed>> $formats */
        $formats = [];
        if ($this->currentConfig->pictureDownloadIcon and $download_url_present and $this->currentUser->get()->enabledHigh) {
            if ($this->currentConfig->isFormatsEnabled) {
                $picture_id = $picture['current']['id'];
                $formats = array_map(
                    static fn (ImageFormat $format): array => $format->toArray(),
                    $this->entityManager->getRepository(ImageEntity::class)
                        ->findFormatsForImage(ImageId::from($picture_id))
                );

                // let's add the original as a format among others. It
                // will just have a specific download URL
                array_unshift(
                    $formats,
                    [
                        'download_url' => $download_url,
                        'ext' => StringHelper::getExtension($picture['current']['file']),
                        'filesize' => $picture['current']['filesize'],
                    ]
                );

                foreach ($formats as &$format) {
                    // array_unshift() above prepends a differently-shaped
                    // literal (only download_url/ext/filesize) onto the
                    // Projection\ImageFormat::toArray() rows (format_id/
                    // image_id/ext/filesize) -- narrow explicitly rather
                    // than trust a per-key type across that mix.
                    if (! isset($format['download_url'])) {
                        $format['download_url'] = 'action.php?format=' . $format['format_id'] . '&amp;download';
                    }

                    $format_ext = $format['ext'];
                    $format['label'] = strtoupper($format_ext);
                    $lang_key = 'format ' . strtoupper($format_ext);
                    if ($this->lang->has($lang_key)) {
                        $format['label'] = $this->lang->t($lang_key);
                    }

                    $format_filesize = $format['filesize'];
                    $format_filesize = is_numeric($format_filesize) ? (float) $format_filesize : 0.0;
                    $format['filesize'] = sprintf('%.1fMB', $format_filesize / 1024.0);
                }
                unset($format);
            }
        }

        // U_DOWNLOAD/formats are picture.latte's own $current.U_DOWNLOAD/
        // $current.formats -- merged straight into $nav['current'] (built
        // above, always set whenever $download_url_present can be true,
        // since that itself reads $picture['current']) rather than through
        // a separate Template::append('current', ..., merge: true) call,
        // now that PictureView owns 'current' entirely via navCurrent.
        if ($this->currentConfig->pictureDownloadIcon and $download_url_present and $this->currentUser->get()->enabledHigh) {
            $nav['current']['U_DOWNLOAD'] = $download_url;

            if ($this->currentConfig->isFormatsEnabled) {
                $nav['current']['formats'] = $formats;
            }
        }

        $u_slideshow_stop = null;
        $u_slideshow_start = null;

        if ($slideshow) {
            $tpl_slideshow = [];

            // slideshow end
            $u_slideshow_stop = $picture['current']['url'];

            foreach (['repeat', 'play'] as $p) {
                $var_name =
                  'U_'
                  . ((bool) ($slideshow_params[$p] ?? null) ? 'STOP_' : 'START_')
                  . strtoupper($p);

                $tpl_slideshow[$var_name] =
                      $urlService->addUrlParams(
                          $picture['current']['url'],
                          [
                              'slideshow' => $this->imageService
                                  ->encodeSlideshowParams(
                                      array_merge(
                                          $slideshow_params,
                                          [
                                              $p => ! (bool) ($slideshow_params[$p] ?? null),
                                          ]
                                      )
                                  ),
                          ]
                      );
            }

            foreach (['dec', 'inc'] as $op) {
                // decodeSlideshowParams()/correctSlideshowParams()
                // only declare @return array<string, mixed>; 'period'
                // is always numeric in practice (int default, or a
                // preg-captured \d+ string).
                $current_period = $slideshow_params['period'];
                $current_period = is_numeric($current_period) ? (int) $current_period : 0;
                $slideshow_period_step = $this->currentConfig->slideshowPeriodStep;
                $new_period = $current_period + ((($op === 'dec') ? -1 : 1) * $slideshow_period_step);
                $new_slideshow_params =
                  $this->imageService
                      ->correctSlideshowParams(
                          array_merge(
                              $slideshow_params,
                              [
                                  'period' => $new_period,
                              ]
                          )
                      );

                if ($new_slideshow_params['period'] === $new_period) {
                    $var_name = 'U_' . strtoupper($op) . '_PERIOD';
                    $tpl_slideshow[$var_name] =
                          $urlService->addUrlParams(
                              $picture['current']['url'],
                              [
                                  'slideshow' => $this->imageService
                                      ->encodeSlideshowParams($new_slideshow_params),
                              ]
                          );
                }
            }
            $slideshow_nav = $tpl_slideshow;
        } elseif ($this->currentConfig->pictureSlideshowIcon) {
            $u_slideshow_start = $urlService->addUrlParams(
                $picture['current']['url'],
                [
                    'slideshow' => '',
                ]
            );
        }

        $section_title = $section_context->sectionTitle;
        $photo = $title_nb;
        $is_home = $section_context->section === Section::Categories && $page_category === null;
        $level_separator = $this->currentConfig->levelSeparator;
        $display_nav_buttons = $this->currentConfig->pictureNavigationIcons;
        $display_nav_thumb = $this->currentConfig->pictureNavigationThumb;

        $u_metadata = null;
        if ($this->currentConfig->pictureMetadataIcon) {
            $u_metadata = $url_metadata;
        }

        // admin links
        $u_set_as_representative = null;
        $u_photo_admin = null;
        $u_caddie = null;

        if ($this->accessControl->isAdmin()) {
            if ($page_category !== null and $this->currentConfig->pictureRepresentativeIcon) {
                $u_set_as_representative = $urlService->addUrlParams(
                    $url_self,
                    [
                        'action' => 'set_as_representative',
                    ]
                );
            }

            if ($this->currentConfig->pictureEditIcon) {
                $u_photo_admin = $urlService->getRootUrl() . 'admin.php?page=photo-' . $image_id;
            }

            if ($this->currentConfig->pictureCaddieIcon) {
                $u_caddie = $urlService->addUrlParams($url_self, [
                    'action' => 'add_to_caddie',
                ]);
            }

        }

        // favorite manipulation
        $favorite = null;
        if (! $this->accessControl->isAGuest() and $this->currentConfig->pictureFavoriteIcon) {
            // verify if the picture is already in the favorite of the
            // user
            $is_favorite = $this->userService->isFavorite($user->id, $image_id);

            $favorite = [
                'IS_FAVORITE' => $is_favorite,
                'U_FAVORITE' => $urlService->addUrlParams(
                    $url_self,
                    [
                        'action' => ! $is_favorite ? 'add_to_favorites' : 'remove_from_favorites',
                    ]
                ),
            ];
        }

        $info_author = null;
        $info_creation_date = null;
        $info_dimensions = null;
        $info_filesize = null;
        $comment_img = null;

        // legend
        $current_comment = $picture['current']['comment'] ?? null;
        if (is_string($current_comment) && $current_comment !== '' && $current_comment !== '0') {
            $descriptionEvent = $this->eventDispatcher->dispatch(new RenderElementDescription($current_comment, 'picture_page_element_description'));
            $comment_img = $descriptionEvent->elementDescription;
        }

        // author
        $current_author = $picture['current']['author'] ?? null;
        if (is_string($current_author) && $current_author !== '' && $current_author !== '0') {
            $info_author = $picture['current']['author'];
        }

        // creation date
        $date_creation = $picture['current']['date_creation'] ?? null;
        if (is_string($date_creation) && $date_creation !== '' && $date_creation !== '0') {
            $val = DateHelper::formatDate($date_creation);
            $url = $urlService->makeIndexUrl(
                [
                    'chronology_field' => 'created',
                    'chronology_style' => 'monthly',
                    'chronology_view' => 'list',
                    'chronology_date' => explode('-', substr($date_creation, 0, 10)),
                ]
            );
            $info_creation_date =
              '<a href="' . $url . '" rel="nofollow">' . $val . '</a>';
        }

        // date of availability -- date_available is nullable (a photo can
        // simply lack EXIF/IPTC date info); PictureView::$infoPostedDate
        // stays a required string (this info row is unconditional in
        // picture.latte, unlike the optional INFO_CREATION_DATE above), so
        // fall back to an empty string instead of feeding formatDate()/
        // substr() a null. $picture['current'] comes from a raw DBAL row
        // here, not ImageEntity, so this was never protected by the former
        // Db\Type\GracefulSqlDateTimeType anyway -- the only other
        // historical risk (a raw MySQL zero-date sentinel) is closed at
        // the write side instead, by Db\DbConnection::SQL_MODE's
        // NO_ZERO_DATE/NO_ZERO_IN_DATE.
        $date_available = $picture['current']['date_available'];
        if (is_string($date_available) && $date_available !== '' && $date_available !== '0') {
            $val = DateHelper::formatDate($date_available);
            $url = $urlService->makeIndexUrl(
                [
                    'chronology_field' => 'posted',
                    'chronology_style' => 'monthly',
                    'chronology_view' => 'list',
                    'chronology_date' => explode('-', substr($date_available, 0, 10)),
                ]
            );
            $info_posted_date = '<a href="' . $url . '" rel="nofollow">' . $val . '</a>';
        } else {
            $info_posted_date = '';
        }

        // size in pixels
        if ($picture['current']['src_image']->isOriginal() and isset($picture['current']['width'])) {
            $info_dimensions =
              $picture['current']['width'] . '*' . $picture['current']['height'];
        }

        // filesize
        $current_filesize = $picture['current']['filesize'] ?? null;
        if (is_numeric($current_filesize) && (float) $current_filesize !== 0.0) {
            $info_filesize = $this->lang->t('%d Kb', $picture['current']['filesize']);
        }

        // number of visits -- ImageEntity::$hit is a real int
        // (src/Piwigo/Image/ImageEntity.php:100); PictureView::
        // $infoVisits is a pre-formatted display string like its
        // info_dimensions/info_filesize siblings above, so this needs
        // the same explicit stringification they get implicitly from
        // their own string-returning sources (a real picture.php request
        // once threw "PicturePageContext::__construct(): Argument #27
        // ($infoVisits) must be of type string, int given" before this
        // cast existed).
        $info_visits = (string) $picture['current']['hit'];

        // file
        $info_file = $picture['current']['file'];

        $display_info = $this->currentConfig->pictureInformations;
        $display_info = $this->eventDispatcher->dispatch(new FilterPictureDisplayInfo($display_info, $image_id))
            ->displayInfo;

        // related tags
        $tags = $this->tagService
            ->getCommonTags([$image_id], -1, $this->htmlService);
        $related_tags = [];
        foreach ($tags as $tag) {
            $related_tags[] = array_merge(
                $tag,
                [
                    'URL' => $urlService->makeIndexUrl(
                        [
                            'tags' => [$tag],
                        ]
                    ),
                    'U_TAG_IMAGE' => $urlService->duplicatePictureUrl(
                        [
                            'section' => 'tags',
                            'tags' => [$tag],
                        ]
                    ),
                ]
            );
        }

        // related categories
        //
        // VisibleCategoryRow::$id is a real int, never a string. The old
        // code left $related_cat0_id at that native int type but
        // force-cast $page_category['id'] to string before comparing them
        // with strict `===` -- `5 === "5"` is always false, so this
        // "single category, no need to go to db" fast path could never
        // actually be taken through any real request; every view silently
        // fell through to the else branch's extra SQL query below, even
        // for the common case of a photo viewed via its own single album.
        // VisibleCategoryRow::$id and CategoryInfo::$id are both real ints,
        // never strings -- $page_category_id_for_compare used to be cast
        // from a $page_category['id'] array read via is_numeric(), the
        // same real int now available directly off the object.
        $related_cat0_id = ($related_categories[0] ?? null)?->id;
        $page_category_id_for_compare = $page_category?->id;
        $related_categories_display = [];
        if (count($related_categories) === 1 and
            $page_category !== null and
            $related_cat0_id !== null and $related_cat0_id === $page_category_id_for_compare) { // no need to go to db, we have all the info
            // Mirrors the narrowing in include/functions_html.inc.php
            // (get_cat_display_name_from_id()) for the same
            // 'upper_names' shape.
            $related_categories_display[] = $this->htmlService
                ->getCatDisplayName(array_map(
                    static fn (CategoryIdNamePermalink $row): array => $row->toArray(),
                    $page_category->upperNames
                ));
        } else { // use only 1 sql query to get names for all related categories
            $ids = [];
            foreach ($related_categories as $category) {// add all uppercats to $ids
                $ids = array_merge($ids, explode(',', $category->uppercats));
            }
            $ids = array_unique($ids);
            $cat_map = $this->categoryService->getNamesByIds(array_values(array_map(intval(...), $ids)));
            foreach ($related_categories as $category) {
                $cats = [];
                foreach (explode(',', $category->uppercats) as $id) {
                    $cats[] = $cat_map[$id];
                }
                $related_categories_display[] = $this->htmlService->getCatDisplayName($cats);
            }
        }

        $pdf_nb_pages = null;

        if (in_array(strtolower(StringHelper::getExtension($picture['current']['file'])), ['pdf'], true)) {
            // $picture['current']['path'] is the raw images.path
            // column, root-relative (e.g. 'upload/2026/07/x.pdf'),
            // same as every other real filesystem read of this
            // column elsewhere in this codebase (e.g. SrcImage::
            // getPath()'s own `self::paths()->root .
            // $this->rel_path`). countPdfPages() calls is_file()/
            // is_readable() on whatever path it's given with no
            // root of its own, so passing the bare relative path
            // resolves against the PHP process's cwd -- the
            // directory of the executing front-controller script
            // (public/) under a real Apache/mod_php request, one
            // level below the real upload root -- silently
            // returning false (never a real page count) on every
            // live request.
            $pdf_nb_pages = $this->imageService
                ->countPdfPages($this->paths->root . $picture['current']['path']);
        }

        // maybe someone wants a special display (call it before
        // page_header so that they can add stylesheets)
        $contentEvent = $this->eventDispatcher->dispatch(new RenderElementContent('', $picture['current']));
        $element_content = $contentEvent->content;

        $u_prefetch = null;

        $http_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $http_user_agent = is_string($http_user_agent) ? $http_user_agent : '';
        if (isset($picture['next'])
            and $picture['next']['src_image']->isOriginal()
            and $template->getTemplateVars('U_PREFETCH') === null
            and ! str_contains($http_user_agent, 'Chrome/')) {
            $prefetch_deriv_type = $this->sessionService->getPictureDeriv() ?? $this->currentConfig->derivativeDefaultSize;
            $u_prefetch = $picture['next']['derivatives'][$prefetch_deriv_type]->getUrl();
        }

        $u_canonical = $urlService->makePictureUrl(
            [
                'image_id' => $picture['current']['id'],
                'image_file' => $picture['current']['file'],
            ]
        );

        // header.latte renders this before PictureView is ever
        // constructed -- see CanonicalUrlPageContext's own docblock.
        $template->assignContext(new CanonicalUrlPageContext($u_canonical));
        $template->assignContext(new PictureHeaderPageContext(
            navFirst: $nav['first'] ?? null,
            navPrevious: $nav['previous'] ?? null,
            navNext: $nav['next'] ?? null,
            navLast: $nav['last'] ?? null,
            uUp: $url_up,
            commentImg: $comment_img,
            infoFile: $info_file,
            uPrefetch: $u_prefetch,
            relatedTags: $related_tags !== [] ? $related_tags : null,
        ));

        $rateResult = $this->pictureRateRenderer
            ->render($image_id, $urlService, $picture, $url_self);
        $commentsResult = PictureCommentsResult::empty();
        if ($this->currentConfig->activateComments) {
            $commentsResult = new PictureCommentRenderer()
                ->render($this->lang, new AccessLevelChecker($this->currentUser, $this->currentConfig), $edit_comment, $image_id, $section_context->start, $urlService, array_map(static fn (VisibleCategoryRow $row): array => $row->toArray(), $related_categories), $url_self, $this->sessionService, $this->eventDispatcher, $this->pageState, $this->currentUser, $this->currentConfig, $this->csrfService, $this->mailer, $this->htmlService, $this->entityManager, $this->renderer);
        }
        $metadata = null;
        if ($metadata_showable and $this->sessionService->isShowMetadataEnabled()) {
            $metadata = new PictureMetadataRenderer()
                ->render($this->lang, $picture, $this->currentLogger, $this->eventDispatcher, $this->currentConfig, $this->currentUser, $this->sessionService, $this->paths, $this->entityManager);
        }

        // include menubar
        $themeconf = $template->getTemplateVars('themeconf');
        $themeconf = is_array($themeconf) ? $themeconf : [];
        if ($this->currentConfig->pictureMenu and (! isset($themeconf['hide_menu_on']) or ! is_array($themeconf['hide_menu_on']) or ! in_array('thePicturePage', $themeconf['hide_menu_on'], true))) {
            new MenubarRenderer()
                ->render($this->lang, new AccessLevelChecker($this->currentUser, $this->currentConfig), $urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, $this->deploymentPolicy, $this->currentUser, $this->currentTemplate, $this->currentConfig, $this->eventDispatcher, $this->translator, $this->currentLogger, $this->permissionService, $this->entityManager, $this->renderer);
        }

        // The slideshow branch above may have set $refresh/$url_link
        // (auto-advance meta refresh); same null-unless-numeric narrowing
        // the deleted include/page_header.php seam applied.
        $refresh_str = isset($refresh) && is_numeric($refresh) ? (string) $refresh : null;
        /** @var string|null $url_link */
        new PageHeaderRenderer()
            ->prepareContext($title, $this->eventDispatcher, $this->layoutState, $this->currentTemplate, $this->currentConfig, $refresh_str, $url_link);
        $this->eventDispatcher->dispatch(new PicturePageRendered($image_id));
        $this->htmlService
            ->flushPageMessages();

        // $picture['current']['element_url'], when present, is always a
        // string (assigned only from SrcImage::getUrl()/UrlServiceInterface::
        // getElementUrl(), both string-returning) -- but that fact isn't
        // visible to static analysis through $row's own progressive,
        // conditional mutation inside the findByIds() loop above.
        $current_element_url = $picture['current']['element_url'] ?? null;
        $u_original = $this->computeUOriginal(is_string($current_element_url)
            ? [
                'derivatives' => $picture['current']['derivatives'],
                'element_url' => $current_element_url,
            ]
            : [
                'derivatives' => $picture['current']['derivatives'],
            ]);

        $commonPictureViewArgs = [
            'navFirst' => $nav['first'] ?? null,
            'navPrevious' => $nav['previous'] ?? null,
            'navNext' => $nav['next'] ?? null,
            'navLast' => $nav['last'] ?? null,
            'navCurrent' => $nav['current'] ?? null,
            'uSlideshowStop' => $u_slideshow_stop,
            'slideshowNav' => $slideshow_nav ?? null,
            'uSlideshowStart' => $u_slideshow_start,
            'sectionTitle' => $section_title,
            'photo' => $photo,
            'isHome' => $is_home,
            'levelSeparator' => $level_separator,
            'uUp' => $url_up,
            'displayNavButtons' => $display_nav_buttons,
            'displayNavThumb' => $display_nav_thumb,
            'uMetadata' => $u_metadata,
            'uSetAsRepresentative' => $u_set_as_representative,
            'uPhotoAdmin' => $u_photo_admin,
            'uCaddie' => $u_caddie,
            'favorite' => $favorite,
            'commentImg' => $comment_img,
            'infoAuthor' => $info_author,
            'infoCreationDate' => $info_creation_date,
            'infoPostedDate' => $info_posted_date,
            'infoDimensions' => $info_dimensions,
            'infoFilesize' => $info_filesize,
            'infoVisits' => $info_visits,
            'infoFile' => $info_file,
            'displayInfo' => $display_info,
            'pdfNbPages' => $pdf_nb_pages,
            'elementContent' => $element_content,
            'uPrefetch' => $u_prefetch,
            'relatedTags' => $related_tags !== [] ? $related_tags : null,
            'relatedCategories' => $related_categories_display !== [] ? $related_categories_display : null,
            'csrfToken' => $this->csrfService->getToken(),
            'cookiePath' => new CookieService()
                ->cookiePath(),
            'uOriginal' => $u_original,
            'metadata' => $metadata !== null ? array_map(static fn (MetadataPanel $panel): array => $panel->toArray(), $metadata) : null,
            'rateSummary' => $rateResult->rateSummary,
            'rating' => $rateResult->rating,
            'commentsOrderUrl' => $commentsResult->commentsOrderUrl,
            'commentsOrderTitle' => $commentsResult->commentsOrderTitle,
            'commentCount' => $commentsResult->commentCount,
            'commentsNavbar' => $commentsResult->commentsNavbar,
            'comments' => $commentsResult->comments,
            'commentAdd' => $commentsResult->commentAdd,
            'commentList' => $commentsResult->commentList,
        ];

        $current_image_id = $picture['current']['id'];
        $this->historyService
            ->logVisit(
                $current_image_id,
                'picture',
                section: $section_context->section->value,
                categoryId: $section_context->category?->id,
                tagIds: $section_context->tagIds,
            );

        PageTail::prepareContext();

        if ($slideshow and $this->currentConfig->lightSlideshow) {
            $html = $this->renderer->render(new SlideshowView(...$commonPictureViewArgs));
        } else {
            $html = $this->renderer->render(new PictureView(...$commonPictureViewArgs, rootUrl: $this->urlService->getRootUrl(), pluginPictureButtons: $template->pictureButtons(), pluginPictureActions: $template->pictureActions(), pluginPictureInfoRows: $template->pictureInfoRows()));
        }
        $body = $template->finalizeHtml((string) $html);

        return ResponseFactory::html($body);
    }

    /**
     * $event->currentPicture is always $picture['current'] (see the
     * dispatch() call near the end of __invoke()) -- 'derivatives' is
     * populated by DerivativeImage::getAll()
     * (src/Piwigo/Image/DerivativeImage.php), keyed by the IMG_* type
     * strings. `RenderElementContent::$currentPicture` itself stays
     * generically typed (it's a general-purpose event class); this method,
     * as the one real consumer of that shape, narrows it locally instead.
     */
    private function defaultPictureContent(RenderElementContent $event): RenderElementContent
    {
        if ($event->content !== '') {// someone hooked us - so we skip;
            return $event;
        }

        /**
         * @var array{
         *     file: string,
         *     derivatives: array<string, DerivativeImage>,
         *     element_url?: string,
         *     ...
         * } $element_info
         */
        $element_info = $event->currentPicture;

        if (isset($_COOKIE['picture_deriv'])) {
            if (is_string($_COOKIE['picture_deriv'])
                and array_key_exists($_COOKIE['picture_deriv'], $this->imageStdParams->getDefinedTypeMap())) {
                $this->sessionService->setSessionVar('picture_deriv', $_COOKIE['picture_deriv']);
            }
            setcookie('picture_deriv', '', [
                'expires' => 0,
                'path' => new CookieService()
                    ->cookiePath(),
            ]);
        }
        $deriv_type = $this->sessionService->getPictureDeriv() ?? $this->currentConfig->derivativeDefaultSize;
        $selected_derivative = $element_info['derivatives'][$deriv_type];

        $unique_derivatives = [];
        $added = [];
        foreach ($element_info['derivatives'] as $type => $derivative) {
            if ($type === ImageStdParams::SQUARE || $type === ImageStdParams::THUMB) {
                continue;
            }
            if (! array_key_exists($type, $this->imageStdParams->getDefinedTypeMap())) {
                continue;
            }
            $url = $derivative->getUrl();
            if (isset($added[$url])) {
                continue;
            }
            $added[$url] = 1;

            // in case we do not display the sizes icon, we only add the
            // selected size to unique_derivatives
            if ($this->currentConfig->pictureSizesIcon or $type === $deriv_type) {
                $unique_derivatives[$type] = $derivative;
            }
        }

        // $element_info['element_url'], when present, is always a string --
        // see __invoke()'s own identical narrowing above for why static
        // analysis can't see that through $element_info's own progressive,
        // conditional construction.
        $element_url = $element_info['element_url'] ?? null;
        $u_original = $this->computeUOriginal(is_string($element_url)
            ? [
                'derivatives' => $element_info['derivatives'],
                'element_url' => $element_url,
            ]
            : [
                'derivatives' => $element_info['derivatives'],
            ]);

        $pdf_viewer_filesize_threshold = null;
        if (in_array(strtolower(StringHelper::getExtension($element_info['file'])), ['pdf'], true)) {
            $pdf_viewer_filesize_threshold = $this->currentConfig->pdfViewerFilesizeThreshold * 1024;
        }

        $pictureContentView = new PictureContentView(
            uOriginal: $u_original,
            altImg: $element_info['file'],
            cookiePath: new CookieService()
                ->cookiePath(),
            pdfViewerFilesizeThreshold: $pdf_viewer_filesize_threshold,
            rootUrl: $this->urlService->getRootUrl(),
            iconDir: $this->currentTemplate->get()
                ->themeConf('icon_dir'),
            current: [
                ...$element_info,
                'selected_derivative' => $selected_derivative,
                'unique_derivatives' => $unique_derivatives,
            ],
        );
        $event->content = (string) $this->renderer->render($pictureContentView);

        return $event;
    }
}
