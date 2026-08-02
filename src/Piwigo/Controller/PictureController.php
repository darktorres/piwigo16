<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Doctrine\DBAL\Connection;
use Piwigo\Auth\CookieService;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentService;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Config\ConfigService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Event\Location\LocBeginPicture;
use Piwigo\Event\Location\LocEndPicture;
use Piwigo\Event\Picture\AllowIncrementElementHitCount;
use Piwigo\Event\Picture\GetElementMetadataAvailable;
use Piwigo\Event\Picture\PicturePicturesData;
use Piwigo\Event\Picture\RenderElementContent;
use Piwigo\Event\Picture\RenderElementDescription;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Permission\PermissionService;
use Piwigo\Picture\PictureCommentRenderer;
use Piwigo\Picture\PictureMetadataRenderer;
use Piwigo\Picture\PictureRateRenderer;
use Piwigo\Section\SectionContext;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagService;
use Piwigo\Template\Template;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces picture.php -- the single-photo detail page, largest and most
 * intricate controller this phase. The legacy file's own top-level
 * default_picture_content() becomes a private method registered via
 * add_event_handler()'s first-class-callable form ($this->
 * defaultPictureContent(...)) instead of the string-name form -- confirmed
 * add_event_handler() accepts `array{0: object|string, 1: string}|object|string`
 * (a Closure is an object), same conversion precedent as
 * Piwigo\Admin\Integrity\C13yInternal.php's own handler registrations.
 *
 * The "actions" switch (favorite/caddie/rate/comment moderation) is the
 * last place any exit()-capable call happens before the render body
 * (confirmed via a full-file grep for redirect()/redirect_http()/
 * page_not_found()/access_denied()/die()/exit -- every hit above the
 * "number of hits" section already throws ResponseReadyException via
 * RedirectService, unrelated to Workstream C3c below). Not every case
 * redirects, though: edit_comment falls through to the render body
 * without calling redirect() whenever no comment content was submitted
 * (i.e. just displaying the edit-comment form itself).
 *
 * Workstream C3c: converted off LegacyRenderCapture's ob_start()/
 * ob_get_contents() capture -- the render body that used to be a separate
 * static closure (passed to LegacyRenderCapture::capture(), its own
 * use()-list threading every needed variable in by value) is now flat code
 * directly in this method, same "parse($handle, false) accumulates into
 * Template's own buffer, PageTail::renderToString() drains it as one
 * string" mechanism Controller\AboutController/PopuphelpController already
 * use. $urlService/$configService keep their own local aliases (still
 * referenced throughout the body) rather than being renamed to
 * $this->urlService/$this->configService everywhere -- a smaller, safer
 * diff for a ~750-line method. Picture\PictureCommentRenderer::render()'s
 * own 2 die() calls (reached from within this body) now throw
 * ResponseReadyException too -- see that class's own docblock.
 *
 * Like GalleryController, Piwigo\Section\SectionPopulator::populate() (P23
 * batch 4d) must run before check_status() -- check_restrictions() below
 * depends on SectionContextRegistry::current()->category already being
 * populated.
 */
final class PictureController implements ControllerInterface
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
    ) {}

    private static function permissionService(): PermissionService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::permissionService();
    }

    private static function categoryService(): CategoryService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::categoryService();
    }

    private static function tagService(): TagService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::tagService();
    }

    private static function userService(): UserService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::userService();
    }

    private static function imageService(): ImageService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::imageService();
    }

    private static function commentService(Connection $conn, UrlServiceInterface $urlService): CommentService
    {
        return new CommentService(\Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Comment\CommentEntity::class), new EphemeralKeyService(), \Piwigo\Bootstrap\PresentationAccessor::mailService(), \Piwigo\Bootstrap\PresentationAccessor::htmlService(), $urlService);
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // A single connection for the whole request -- mirrors the
        // legacy single-global-mysqli-connection model this migration
        // restores, and avoids the needless-reconnection pattern found
        // in earlier construction-chain debt (Phase 1d finding).
        $conn = DbConnection::build();
        \Piwigo\Bootstrap\ExtendedDomainAccessor::sectionPopulator()
            ->populate();

        $template = \Piwigo\Template\CurrentTemplate::get();

        // Legacy Coupling Retirement Track A batch A5.2e: populate() always
        // calls SectionContextRegistry::set() as the very last thing it
        // does, so this is guaranteed non-null by the time this controller
        // (its one real caller besides GalleryController) runs -- a real
        // guard, not dead code, since the type itself is nullable.
        $section_context = SectionContextRegistry::current();
        if (! $section_context instanceof SectionContext) {
            throw new \RuntimeException('SectionContextRegistry::current() is null after SectionPopulator::populate()');
        }

        self::userService()->saveEditContext($section_context->sectionUrl, $section_context->imageId);

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Guest);

        $page_category = $section_context->category;

        // access authorization check
        if ($page_category !== null) {
            $category_id = $page_category['id'] ?? null;
            self::categoryService()->checkRestrictions(is_numeric($category_id) ? (int) $category_id : 0, \Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->redirectService);
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

        $currentUser = \Piwigo\Users\CurrentUser::get();

        // if this image_id doesn't correspond to this category, an error
        // message is displayed, and execution is stopped
        if (! isset($rank_of[$image_id])) {
            if ($image_id > 0) {
                $escaped_image_file = null;
            } else {// url given by file name
                assert($image_file !== null && $image_file !== '');
                $escaped_image_file = str_replace(['_', '%'], ['/_', '/%'], $image_file);
            }
            $row = self::imageService()->findByIdOrFilePattern($image_id, $escaped_image_file);
            if ($row === false) {// element does not exist
                \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                    ->pageNotFound(
                        $this->redirectService,
                        'The requested image does not exist',
                        $this->urlService->duplicateIndexUrl()
                    );
            }
            $row_level = $row['level'];
            $user_level = $currentUser->level;
            if ((is_numeric($row_level) ? (int) $row_level : 0) > $user_level) {
                \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                    ->accessDenied($this->redirectService);
            }

            $row_id = $row['id'];
            assert(is_string($row_id)); // images.id is the NOT NULL primary key
            $image_id = (int) $row_id;
            $row_file = $row['file'];
            $image_file = is_string($row_file) ? $row_file : null;
            if (! isset($rank_of[$image_id])) {// the image can still be non accessible (filter/cat perm) and/or not in the set
                // Phase 2 global-residual sweep: retargeted from `global
                // $filter;` onto Piwigo\Core\FilterState, preserving the
                // same lenient "not initialized yet -> no restriction"
                // fallback the old `?? null` had. FilterState::
                // visibleImages() always returns a real string (the old
                // raw global's occasional `-1` int sentinel is now the
                // string '-1'); the old is_string() check implicitly
                // excluded that sentinel by type, so exclude it
                // explicitly here to preserve the exact same behavior --
                // '-1' means "the filter computed an empty visible-images
                // list" (see FilterService's own "Must be not empty"
                // comment), not a real id to match against.
                $visible_images = \Piwigo\Core\FilterState::isInitialized() ? \Piwigo\Core\FilterState::visibleImages() : '';
                if ($visible_images !== '' && $visible_images !== '-1' &&
                  ! in_array((string) $image_id, explode(',', $visible_images), true)) {
                    \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                        ->pageNotFound(
                            $this->redirectService,
                            'The requested image is filtered',
                            $this->urlService->duplicateIndexUrl()
                        );
                }
                $page_section = $section_context->section;
                if ($page_section === 'categories' and $page_category === null) {// flat view - all items
                    \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                        ->accessDenied($this->redirectService);
                } else {// try to see if we can access it differently
                    $accessible = self::imageService()->isImageAccessibleWithCondition(
                        $image_id,
                        self::permissionService()->getSqlConditionFandFAsCondition([
                            'forbidden_categories' => 'category_id',
                        ])
                    );
                    if (! $accessible) {
                        \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                            ->accessDenied($this->redirectService);
                    } else {
                        if ($page_section === 'best_rated') {
                            $rank_of[$image_id] = count($items);
                            $items[] = $image_id;
                            $section_context = $section_context->withItems($items);
                            SectionContextRegistry::set($section_context);
                        } else {
                            $url = $this->urlService->makePictureUrl(
                                [
                                    'image_id' => $image_id,
                                    'image_file' => $image_file,
                                    'section' => 'categories',
                                    'flat' => true,
                                ]
                            );
                            // Real bug, found while adding coverage for this
                            // branch: the legacy equivalent's own raw
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
                            // confirmed live, every recent_pics redirect came
                            // back 302, never the intended 301. Passing the
                            // real status straight into redirectHttp() (which
                            // already accepts one) is the fix; the separate
                            // setStatusHeader() call is dropped as dead
                            // weight, not left in as a no-op.
                            $this->redirectService->redirectHttp($url, $page_section === 'recent_pics' ? 301 : 302);
                        }
                    }
                }
            }
        }

        $pictureRequest = Request\PictureRequest::fromGlobals();
        // Mutated below (the edit_comment case's own successful-submission
        // path) to reflect the original's unset($_POST['content']) --
        // read again much further down to decide whether to increment the
        // hit counter.
        $content_present = $pictureRequest->contentPresent;

        // There is cookie, so we must handle it at the beginning
        if ($pictureRequest->metadataPresent) {
            if (SessionService::get()->getSessionVar('show_metadata') === null) {
                SessionService::get()->setSessionVar('show_metadata', 1);
            } else {
                SessionService::get()->unsetSessionVar('show_metadata');
            }
        }

        // add default event handler for rendering element content
        \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(RenderElementContent::class, $this->defaultPictureContent(...));
        // add default event handler for rendering element description --
        // pwgNl2br() is reused across two different events (this one and
        // RenderCategoryDescription in RequestBootstrap.php), so this is a
        // thin adapter closure, leaving pwgNl2br() itself untouched.
        \Piwigo\PluginConfig\EventDispatcher::get()->addTypedHandler(
            RenderElementDescription::class,
            static function (RenderElementDescription $e): RenderElementDescription {
                $result = \Piwigo\Bootstrap\PresentationAccessor::htmlService()->pwgNl2br($e->elementDescription);
                $e->elementDescription = is_string($result) ? $result : '';

                return $e;
            },
        );

        \Piwigo\PluginConfig\EventDispatcher::get()->dispatchNotify(new LocBeginPicture());

        // +-----------------------------------------------------------------+
        // |                            initialization                        |
        // +-----------------------------------------------------------------+

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

        // +-----------------------------------------------------------------+
        // |                                actions                           |
        // +-----------------------------------------------------------------+

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

                    self::userService()->addFavorite($currentUser->id, $image_id);

                    $this->redirectService->redirect($url_self);

                    // no break
                case 'remove_from_favorites':

                    self::userService()->removeFavorite($currentUser->id, $image_id);

                    if ($section_context->section === 'favorites') {
                        $this->redirectService->redirect($url_up);
                    } else {
                        $this->redirectService->redirect($url_self);
                    }

                    // no break
                case 'set_as_representative':

                    if (\Piwigo\Auth\AccessControl::isAdmin() and $page_category !== null) {
                        $representative_category_id = $page_category['id'] ?? null;
                        $representative_category_id = is_numeric($representative_category_id) ? (int) $representative_category_id : 0;
                        self::categoryService()->setRepresentativeImage($representative_category_id, $image_id);
                        \Piwigo\Bootstrap\InfrastructureAccessor::entityManager()->clear();
                        \Piwigo\Bootstrap\ExtendedDomainAccessor::activityService()->record('album', $representative_category_id, 'edit', [
                            'action' => $pictureRequest->action,
                            'image_id' => $image_id,
                        ]);

                        PermissionCacheInvalidator::invalidate();
                    }

                    $this->redirectService->redirect($url_self);

                    // no break
                case 'add_to_caddie':

                    \Piwigo\Caddie\CaddieService::fillCurrentUserCaddie([$image_id]);
                    $this->redirectService->redirect($url_self);

                    // no break
                case 'rate':

                    \Piwigo\Bootstrap\ExtendedDomainAccessor::rateService()
                        ->rate($image_id, $pictureRequest->rate);
                    $this->redirectService->redirect($url_self);

                    // no break
                case 'edit_comment':

                    $commentService = self::commentService($conn, $this->urlService);
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

                    if (\Piwigo\Auth\AccessControl::canManageComment('edit', $author_id)) {
                        // $_POST values are always strings (or arrays) --
                        // never a real PHP int/float/bool.
                        if ($pictureRequest->content !== null && $pictureRequest->content !== '' && $pictureRequest->content !== '0') {
                            new \Piwigo\Csrf\CsrfService()
                                ->checkOrFail(\Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->redirectService);
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
                                    $_SESSION['page_infos'][] = Lang::t('An administrator must authorize your comment before it is visible.');
                                    // no break
                                case 'validate':
                                    if (! isset($_SESSION['page_infos']) or ! is_array($_SESSION['page_infos'])) {
                                        $_SESSION['page_infos'] = [];
                                    }
                                    $_SESSION['page_infos'][] = Lang::t('Your comment has been registered');
                                    $perform_redirect = true;
                                    break;
                                case 'reject':
                                    if (! isset($_SESSION['page_errors']) or ! is_array($_SESSION['page_errors'])) {
                                        $_SESSION['page_errors'] = [];
                                    }
                                    $_SESSION['page_errors'][] = Lang::t('Your comment has NOT been registered because it did not pass the validation rules');
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

                    new \Piwigo\Csrf\CsrfService()
                        ->checkOrFail(\Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->redirectService);

                    $commentService = self::commentService($conn, $this->urlService);

                    // check_input_parameter()-equivalent validation already ran
                    // inside PictureRequest::fromArrays() (gated on action ===
                    // 'delete_comment') -- it would have thrown otherwise.
                    assert($pictureRequest->commentToDelete !== null && is_numeric($pictureRequest->commentToDelete));

                    // false is unreachable here: see the edit_comment case
                    // above
                    $author_id = $commentService->getCommentAuthorId(CommentId::from((int) $pictureRequest->commentToDelete));
                    assert($author_id !== false);

                    if (\Piwigo\Auth\AccessControl::canManageComment('delete', $author_id)) {
                        $commentService->deleteComment(CommentId::from((int) $pictureRequest->commentToDelete));
                    }

                    $this->redirectService->redirect($url_self);

                    // no break
                case 'validate_comment':

                    new \Piwigo\Csrf\CsrfService()
                        ->checkOrFail(\Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->redirectService);

                    $commentService = self::commentService($conn, $this->urlService);

                    // check_input_parameter()-equivalent validation already ran
                    // inside PictureRequest::fromArrays() (gated on action ===
                    // 'validate_comment') -- it would have thrown otherwise.
                    assert($pictureRequest->commentToValidate !== null && is_numeric($pictureRequest->commentToValidate));

                    // false is unreachable here: see the edit_comment case
                    // above
                    $author_id = $commentService->getCommentAuthorId(CommentId::from((int) $pictureRequest->commentToValidate));
                    assert($author_id !== false);

                    if (\Piwigo\Auth\AccessControl::canManageComment('validate', $author_id)) {
                        $commentService->validateComment(CommentId::from((int) $pictureRequest->commentToValidate));
                    }

                    $this->redirectService->redirect($url_self);

            }
        }

        $urlService = $this->urlService;
        $configService = $this->configService;
        // $title/$refresh/$url_link are set and read entirely within
        // this method (passed straight into PageHeaderRenderer::
        // render() below) -- confirmed via grep that no other file
        // reads $GLOBALS['title']/['refresh']/['url_link'], unlike
        // $url_self/$picture/$related_categories, real bridges to
        // PictureCommentRenderer/PictureRateRenderer/
        // PictureMetadataRenderer -- threaded into their own render()
        // calls below as real parameters (Legacy Coupling Retirement
        // Phase 8, 8g), formerly `global`.
        $refresh = null;
        $url_link = null;
        $template = \Piwigo\Template\CurrentTemplate::get();

        // ---------- incrementation of the number of hits
        $inc_hit_count = ! $content_present;
        // don't increment counter if in the Mozilla Firefox prefetch
        $http_x_moz = $_SERVER['HTTP_X_MOZ'] ?? null;
        if (is_string($http_x_moz) and $http_x_moz === 'prefetch') {
            $inc_hit_count = false;
        } else {
            // don't increment counter if comming from the same picture
            // (actions)
            $referer_image_id = SessionService::get()->getSessionVar('referer_image_id', 0);
            if (is_numeric($referer_image_id) and (int) $referer_image_id === $image_id) {
                $inc_hit_count = false;
            }
            SessionService::get()->setSessionVar('referer_image_id', $image_id);
        }

        // don't increment if adding a comment
        if (\Piwigo\PluginConfig\EventDispatcher::get()->dispatchChange(new AllowIncrementElementHitCount($inc_hit_count, $image_id))->incHitCount) {
            \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Image\ImageEntity::class)
                ->incrementVisitCounter($image_id);
        }

        // -------------------------------------------------- related categories
        // Row shape is mixed, not uniformly string|null -- see
        // PictureCommentRenderer::render()'s own param docblock for the
        // real per-column breakdown.
        $related_categories = self::imageService()->getVisibleCategoriesForImage(
            $image_id,
            self::permissionService()->getSqlConditionFandFAsCondition([
                'forbidden_categories' => 'id',
                'visible_categories' => 'id',
            ])
        );
        usort($related_categories, CategoryService::compareByGlobalRank(...));
        // ---------------------- first, prev, current, next & last picture management
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

        foreach (\Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Image\ImageEntity::class)->findByIds($ids) as $imageRow) {
            $row = $imageRow->toArray();
            if ($previous_item !== null and $imageRow->id === (int) $previous_item) {
                $i = 'previous';
            } elseif ($next_item !== null and $imageRow->id === (int) $next_item) {
                $i = 'next';
            } elseif ($first_item !== null and $imageRow->id === (int) $first_item) {
                $i = 'first';
            } elseif ($last_item !== null and $imageRow->id === (int) $last_item) {
                $i = 'last';
            } else {
                $i = 'current';
            }

            $row['src_image'] = new SrcImage($row);
            $row['derivatives'] = DerivativeImage::get_all($row['src_image']);

            $row['path_ext'] = strtolower(\Piwigo\Core\StringHelper::getExtension($row['path']));
            $row['file_ext'] = strtolower(\Piwigo\Core\StringHelper::getExtension($row['file']));

            if ($i === 'current') {
                $row['element_path'] = \Piwigo\Image\ImagePathHelper::getElementPath($row, $urlService);

                $row_id = $row['id'];

                if ($row['src_image']->is_original()) {// we have a photo
                    if (\Piwigo\Users\CurrentUser::get()->enabledHigh) {
                        $row['element_url'] = $row['src_image']->get_url();
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
            $picture[$i]['TITLE'] = \Piwigo\Bootstrap\PresentationAccessor::htmlService()->renderElementName($row);
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
            \Piwigo\Core\PageState::current()->setMetaRobots([
                'noindex' => 1,
                'nofollow' => 1,
            ]);

            $slideshow_params = self::imageService()
                ->decodeSlideshowParams($pictureRequest->slideshow);
            $slideshow_url_params['slideshow'] = self::imageService()->encodeSlideshowParams($slideshow_params);

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
                    // header.tpl
                    $refresh = $slideshow_params['period'];
                    $url_link = $urlService->addUrlParams(
                        $picture[$id_pict_redirect]['url'],
                        $slideshow_url_params
                    );
                }
            }
        } else {
            $slideshow = false;
        }
        if ($slideshow and \Piwigo\Config\CurrentConfig::lightSlideshow()) {
            $template->set_filenames([
                'slideshow' => 'slideshow.tpl',
            ]);
        } else {
            $template->set_filenames([
                'picture' => 'picture.tpl',
            ]);
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
        $metadata_showable = \Piwigo\PluginConfig\EventDispatcher::get()->dispatchChange(new GetElementMetadataAvailable(
            (
                (\Piwigo\Config\CurrentConfig::showExif() or \Piwigo\Config\CurrentConfig::showIptc())
                and ! $picture['current']['src_image']->is_mimetype()
            ),
            $picture['current']
        ))->available;

        if ($pictureRequest->metadataPresent) {
            \Piwigo\Core\PageState::current()->setMetaRobots([
                'noindex' => 1,
                'nofollow' => 1,
            ]);
        }

        \Piwigo\Core\PageState::current()->setBodyId('thePicturePage');

        // allow plugins to change what we computed before passing data
        // to template
        /**
         * PicturePicturesData::$picture is deliberately loose
         * (array<mixed>, matching the reference) -- restate the shape a
         * well-behaved plugin is expected to preserve: one images-table
         * row (string|null columns) per navigation slot, plus the
         * computed fields set on $row above.
         *
         * @var array<string, array{
         *     id: string,
         *     file: string,
         *     path: string,
         *     comment: string|null,
         *     author: string|null,
         *     date_creation: string|null,
         *     date_available: string,
         *     width: string|null,
         *     height: string|null,
         *     hit: string,
         *     filesize: string|null,
         *     src_image: SrcImage,
         *     derivatives: array<string, DerivativeImage>,
         *     url: string,
         *     download_url?: string,
         *     TITLE: string,
         *     TITLE_ESC: string,
         *     ...
         * }> $picture
         */
        $picture = \Piwigo\PluginConfig\EventDispatcher::get()->dispatchChange(new PicturePicturesData($picture))->picture;

        // ---------------------------------------------------- navigation management
        foreach (['first', 'previous', 'next', 'last', 'current'] as $which_image) {
            if (isset($picture[$which_image])) {
                $template->assign(
                    $which_image,
                    array_merge(
                        $picture[$which_image],
                        [
                            // Params slideshow was transmit to
                            // navigation buttons
                            'U_IMG' => $urlService->addUrlParams(
                                $picture[$which_image]['url'],
                                $slideshow_url_params
                            ),
                        ]
                    )
                );
            }
        }
        $download_url = $picture['current']['download_url'] ?? null;
        $download_url_present = is_string($download_url) && $download_url !== '' && $download_url !== '0';
        if (\Piwigo\Config\CurrentConfig::pictureDownloadIcon() and $download_url_present and \Piwigo\Users\CurrentUser::get()->enabledHigh) {
            $template->append('current', [
                'U_DOWNLOAD' => $download_url,
            ], true);

            if (\Piwigo\Config\CurrentConfig::isFormatsEnabled()) {
                $picture_id = $picture['current']['id'];
                $picture_id = is_numeric($picture_id) ? (int) $picture_id : 0;
                $formats = array_map(
                    static fn (\Piwigo\Image\Projection\ImageFormat $format): array => $format->toArray(),
                    \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Image\ImageEntity::class)
                        ->findFormatsForImage($picture_id)
                );

                // let's add the original as a format among others. It
                // will just have a specific download URL
                array_unshift(
                    $formats,
                    [
                        'download_url' => $download_url,
                        'ext' => \Piwigo\Core\StringHelper::getExtension($picture['current']['file']),
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
                    if (\Piwigo\Core\Lang::has($lang_key)) {
                        $format['label'] = \Piwigo\Core\Lang::t($lang_key);
                    }

                    $format_filesize = $format['filesize'];
                    $format_filesize = is_numeric($format_filesize) ? (float) $format_filesize : 0.0;
                    $format['filesize'] = sprintf('%.1fMB', $format_filesize / 1024.0);
                }
                unset($format);
                $template->append('current', [
                    'formats' => $formats,
                ], true);
            }
        }

        if ($slideshow) {
            $tpl_slideshow = [];

            // slideshow end
            $template->assign(
                [
                    'U_SLIDESHOW_STOP' => $picture['current']['url'],
                ]
            );

            foreach (['repeat', 'play'] as $p) {
                $var_name =
                  'U_'
                  . ((bool) ($slideshow_params[$p] ?? null) ? 'STOP_' : 'START_')
                  . strtoupper($p);

                $tpl_slideshow[$var_name] =
                      $urlService->addUrlParams(
                          $picture['current']['url'],
                          [
                              'slideshow' => self::imageService()
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
                $slideshow_period_step = \Piwigo\Config\CurrentConfig::slideshowPeriodStep();
                $new_period = $current_period + ((($op === 'dec') ? -1 : 1) * $slideshow_period_step);
                $new_slideshow_params =
                  self::imageService()
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
                                  'slideshow' => self::imageService()
                                      ->encodeSlideshowParams($new_slideshow_params),
                              ]
                          );
                }
            }
            $template->assign('slideshow', $tpl_slideshow);
        } elseif (\Piwigo\Config\CurrentConfig::pictureSlideShowIcon()) {
            $template->assign(
                [
                    'U_SLIDESHOW_START' => $urlService->addUrlParams(
                        $picture['current']['url'],
                        [
                            'slideshow' => '',
                        ]
                    ),
                ]
            );
        }

        $template->assign(
            [
                'SECTION_TITLE' => $section_context->sectionTitle,
                'PHOTO' => $title_nb,
                'IS_HOME' => ($section_context->section === 'categories' and $page_category === null),

                'LEVEL_SEPARATOR' => \Piwigo\Config\CurrentConfig::levelSeparator(),

                'U_UP' => $url_up,
                'DISPLAY_NAV_BUTTONS' => \Piwigo\Config\CurrentConfig::pictureNavigationIcons(),
                'DISPLAY_NAV_THUMB' => \Piwigo\Config\CurrentConfig::pictureNavigationThumb(),
            ]
        );

        if (\Piwigo\Config\CurrentConfig::pictureMetadataIcon()) {
            $template->assign('U_METADATA', $url_metadata);
        }

        // -------------------------------------------------- upper menu management

        // admin links
        if (\Piwigo\Auth\AccessControl::isAdmin()) {
            if ($page_category !== null and \Piwigo\Config\CurrentConfig::pictureRepresentativeIcon()) {
                $template->assign(
                    [
                        'U_SET_AS_REPRESENTATIVE' => $urlService->addUrlParams(
                            $url_self,
                            [
                                'action' => 'set_as_representative',
                            ]
                        ),
                    ]
                );
            }

            if (\Piwigo\Config\CurrentConfig::pictureEditIcon()) {
                $template->assign('U_PHOTO_ADMIN', $urlService->getRootUrl() . 'admin.php?page=photo-' . $image_id);
            }

            if (\Piwigo\Config\CurrentConfig::pictureCaddieIcon()) {
                $template->assign(
                    'U_CADDIE',
                    $urlService->addUrlParams($url_self, [
                        'action' => 'add_to_caddie',
                    ])
                );
            }

        }

        // favorite manipulation
        if (! \Piwigo\Auth\AccessControl::isAGuest() and \Piwigo\Config\CurrentConfig::pictureFavoriteIcon()) {
            // verify if the picture is already in the favorite of the
            // user
            $is_favorite = self::userService()->isFavorite($currentUser->id, $image_id);

            $template->assign(
                'favorite',
                [
                    'IS_FAVORITE' => $is_favorite,
                    'U_FAVORITE' => $urlService->addUrlParams(
                        $url_self,
                        [
                            'action' => ! $is_favorite ? 'add_to_favorites' : 'remove_from_favorites',
                        ]
                    ),
                ]
            );
        }

        // ------------------------------------------------------ picture information
        // $infos was previously used without initialization (relying
        // on PHP's implicit array creation on first offset write) --
        // make it explicit.
        $infos = [];

        // legend
        $current_comment = $picture['current']['comment'] ?? null;
        if (is_string($current_comment) && $current_comment !== '' && $current_comment !== '0') {
            $descriptionEvent = \Piwigo\PluginConfig\EventDispatcher::get()->dispatchChange(new RenderElementDescription($current_comment, 'picture_page_element_description'));
            $template->assign('COMMENT_IMG', $descriptionEvent->elementDescription);
        }

        // author
        $current_author = $picture['current']['author'] ?? null;
        if (is_string($current_author) && $current_author !== '' && $current_author !== '0') {
            $infos['INFO_AUTHOR'] = $picture['current']['author'];
        }

        // creation date
        $date_creation = $picture['current']['date_creation'] ?? null;
        if (is_string($date_creation) && $date_creation !== '' && $date_creation !== '0') {
            $val = \Piwigo\Core\DateHelper::formatDate($date_creation);
            $url = $urlService->makeIndexUrl(
                [
                    'chronology_field' => 'created',
                    'chronology_style' => 'monthly',
                    'chronology_view' => 'list',
                    'chronology_date' => explode('-', substr($date_creation, 0, 10)),
                ]
            );
            $infos['INFO_CREATION_DATE'] =
              '<a href="' . $url . '" rel="nofollow">' . $val . '</a>';
        }

        // date of availability
        $val = \Piwigo\Core\DateHelper::formatDate($picture['current']['date_available']);
        $url = $urlService->makeIndexUrl(
            [
                'chronology_field' => 'posted',
                'chronology_style' => 'monthly',
                'chronology_view' => 'list',
                'chronology_date' => explode(
                    '-',
                    substr($picture['current']['date_available'], 0, 10)
                ),
            ]
        );
        $infos['INFO_POSTED_DATE'] = '<a href="' . $url . '" rel="nofollow">' . $val . '</a>';

        // size in pixels
        if ($picture['current']['src_image']->is_original() and isset($picture['current']['width'])) {
            $infos['INFO_DIMENSIONS'] =
              $picture['current']['width'] . '*' . $picture['current']['height'];
        }

        // filesize
        $current_filesize = $picture['current']['filesize'] ?? null;
        if (is_numeric($current_filesize) && (float) $current_filesize !== 0.0) {
            $infos['INFO_FILESIZE'] = Lang::t('%d Kb', $picture['current']['filesize']);
        }

        // number of visits
        $infos['INFO_VISITS'] = $picture['current']['hit'];

        // file
        $infos['INFO_FILE'] = $picture['current']['file'];

        $template->assign($infos);
        $template->assign('display_info', \Piwigo\Config\CurrentConfig::pictureInformations());

        // related tags
        $tags = self::tagService()
            ->getCommonTags([$image_id], -1, \Piwigo\Bootstrap\PresentationAccessor::htmlService());
        if ($tags !== []) {
            foreach ($tags as $tag) {
                $template->append(
                    'related_tags',
                    array_merge(
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
                    )
                );
            }
        }

        // related categories
        //
        // Real bug, found while adding coverage for this branch:
        // findVisibleCategoriesForImage()'s own 'id' column comes back as a
        // native PHP int (DbConnection::params()'s own
        // MYSQLI_OPT_INT_AND_FLOAT_NATIVE => true, confirmed live), never a
        // string. The old code left $related_cat0_id at that native int
        // type but force-cast $page_category['id'] to string before
        // comparing them with strict `===` -- `5 === "5"` is always false,
        // so this "single category, no need to go to db" fast path could
        // never actually be taken through any real request; every view
        // silently fell through to the else branch's extra SQL query
        // below, even for the common case of a photo viewed via its own
        // single album. Normalizing both sides to int|null (matching this
        // file's own is_numeric()-then-cast idiom used everywhere else,
        // e.g. $category_id above) makes the comparison type-consistent
        // and the fast path reachable again.
        $related_cat0_id = $related_categories[0]['id'] ?? null;
        $related_cat0_id = is_numeric($related_cat0_id) ? (int) $related_cat0_id : null;
        $page_category_id_for_compare = $page_category !== null && is_numeric($page_category['id'] ?? null) ? (int) $page_category['id'] : null;
        if (count($related_categories) === 1 and
            $page_category !== null and
            $related_cat0_id !== null and $related_cat0_id === $page_category_id_for_compare) { // no need to go to db, we have all the info
            // Mirrors the narrowing in include/functions_html.inc.php
            // (get_cat_display_name_from_id()) for the same
            // 'upper_names' shape.
            $upper_names = $page_category['upper_names'] ?? [];
            $upper_names = is_array($upper_names) ? $upper_names : [];
            /** @var array<int, array<string, mixed>> $upper_names */
            $template->append(
                'related_categories',
                \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                    ->getCatDisplayName($upper_names)
            );
        } else { // use only 1 sql query to get names for all related categories
            $ids = [];
            foreach ($related_categories as $category) {// add all uppercats to $ids
                $categoryUppercats = $category['uppercats'];
                $ids = array_merge($ids, explode(',', is_scalar($categoryUppercats) ? (string) $categoryUppercats : ''));
            }
            $ids = array_unique($ids);
            $cat_map = self::categoryService()->getNamesByIds(array_values(array_map(intval(...), $ids)));
            foreach ($related_categories as $category) {
                $cats = [];
                $categoryUppercats = $category['uppercats'];
                foreach (explode(',', is_scalar($categoryUppercats) ? (string) $categoryUppercats : '') as $id) {
                    $cats[] = $cat_map[$id];
                }
                $template->append('related_categories', \Piwigo\Bootstrap\PresentationAccessor::htmlService()->getCatDisplayName($cats));
            }
        }

        if (in_array(strtolower(\Piwigo\Core\StringHelper::getExtension($picture['current']['file'])), ['pdf'], true)) {
            $pdf_viewer_filesize_threshold = \Piwigo\Config\CurrentConfig::pdfViewerFilesizeThreshold();
            $template->assign(
                [
                    'PDF_VIEWER_FILESIZE_THRESHOLD' => $pdf_viewer_filesize_threshold * 1024,
                    // Real bug, found while adding coverage for this branch:
                    // $picture['current']['path'] is the raw images.path
                    // column, root-relative (e.g. 'upload/2026/07/x.pdf'),
                    // same as every other real filesystem read of this
                    // column elsewhere in this codebase (e.g. SrcImage::
                    // get_path()'s own `CurrentPaths::get()->root .
                    // $this->rel_path`). countPdfPages() calls is_file()/
                    // is_readable() on whatever path it's given with no
                    // root of its own, so passing the bare relative path
                    // resolves against the PHP process's cwd -- the
                    // directory of the executing front-controller script
                    // (public/) under a real Apache/mod_php request, one
                    // level below the real upload root -- silently
                    // returning false (never a real page count) on every
                    // live request; confirmed live via a real PDF upload.
                    'PDF_NB_PAGES' => self::imageService()
                        ->countPdfPages(\Piwigo\Core\CurrentPaths::get()->root . $picture['current']['path']),
                ]
            );
        }

        // maybe someone wants a special display (call it before
        // page_header so that they can add stylesheets)
        $contentEvent = \Piwigo\PluginConfig\EventDispatcher::get()->dispatchChange(new RenderElementContent('', $picture['current']));
        $template->assign('ELEMENT_CONTENT', $contentEvent->content);

        $http_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $http_user_agent = is_string($http_user_agent) ? $http_user_agent : '';
        if (isset($picture['next'])
            and $picture['next']['src_image']->is_original()
            and $template->get_template_vars('U_PREFETCH') === null
            and ! str_contains($http_user_agent, 'Chrome/')) {
            $prefetch_deriv_type = SessionService::get()->getSessionVar('picture_deriv', \Piwigo\Config\CurrentConfig::derivativeDefaultSize());
            if (! is_string($prefetch_deriv_type)) {
                $prefetch_deriv_type = ImageStdParams::MEDIUM;
            }
            $template->assign(
                'U_PREFETCH',
                $picture['next']['derivatives'][$prefetch_deriv_type]->get_url()
            );
        }

        $template->assign(
            'U_CANONICAL',
            $urlService->makePictureUrl(
                [
                    'image_id' => $picture['current']['id'],
                    'image_file' => $picture['current']['file'],
                ]
            )
        );

        // +-------------------------------------------------------------+
        // |                          sub pages                           |
        // +-------------------------------------------------------------+

        \Piwigo\Bootstrap\PresentationAccessor::pictureRateRenderer()
            ->render($image_id, $urlService, $picture, $url_self);
        if (\Piwigo\Config\CurrentConfig::activateComments()) {
            new PictureCommentRenderer()
                ->render($edit_comment, $image_id, $section_context->start, $urlService, $related_categories, $url_self);
        }
        if ($metadata_showable and SessionService::get()->getSessionVar('show_metadata') !== null) {
            new PictureMetadataRenderer()
                ->render($picture);
        }

        // include menubar
        $themeconf = $template->get_template_vars('themeconf');
        $themeconf = is_array($themeconf) ? $themeconf : [];
        if (\Piwigo\Config\CurrentConfig::pictureMenu() and (! isset($themeconf['hide_menu_on']) or ! is_array($themeconf['hide_menu_on']) or ! in_array('thePicturePage', $themeconf['hide_menu_on'], true))) {
            new MenubarRenderer()
                ->render($urlService);
        }

        // The slideshow branch above may have set $refresh/$url_link
        // (auto-advance meta refresh); same null-unless-numeric narrowing
        // the deleted include/page_header.php seam applied.
        $refresh_str = isset($refresh) && is_numeric($refresh) ? (string) $refresh : null;
        /** @var string|null $url_link */
        new \Piwigo\Page\PageHeaderRenderer()
            ->render($title, $refresh_str, $url_link ?? null);
        \Piwigo\PluginConfig\EventDispatcher::get()->dispatchNotify(new LocEndPicture());
        \Piwigo\Bootstrap\PresentationAccessor::htmlService()
            ->flushPageMessages();
        if ($slideshow and \Piwigo\Config\CurrentConfig::lightSlideshow()) {
            $template->parse('slideshow', false);
        } else {
            $template->parse_picture_buttons();
            $template->parse('picture', false);
        }
        // -------------------------------------------------- log informations
        $current_image_id = $picture['current']['id'];
        \Piwigo\Bootstrap\ExtendedDomainAccessor::historyService()
            ->logVisit(
                is_numeric($current_image_id) ? (int) $current_image_id : null,
                'picture',
                section: $section_context->section,
                category: $section_context->category,
                tagIds: $section_context->tagIds,
            );

        $body = \Piwigo\Bootstrap\PageTail::renderToString();

        return ResponseFactory::html($body);
    }

    /**
     * $event->currentPicture is always $picture['current'] (see the
     * dispatchChange() call near the end of __invoke()) -- 'derivatives' is
     * populated by DerivativeImage::get_all()
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
                and array_key_exists($_COOKIE['picture_deriv'], ImageStdParams::get_defined_type_map())) {
                SessionService::get()->setSessionVar('picture_deriv', $_COOKIE['picture_deriv']);
            }
            setcookie('picture_deriv', '', [
                'expires' => 0,
                'path' => new CookieService()
                    ->cookiePath(),
            ]);
        }
        $deriv_type = SessionService::get()->getSessionVar('picture_deriv', \Piwigo\Config\CurrentConfig::derivativeDefaultSize());
        if (! is_string($deriv_type)) {
            $deriv_type = ImageStdParams::MEDIUM;
        }
        $selected_derivative = $element_info['derivatives'][$deriv_type];

        $unique_derivatives = [];
        $show_original = isset($element_info['element_url']);
        $added = [];
        foreach ($element_info['derivatives'] as $type => $derivative) {
            if ($type === ImageStdParams::SQUARE || $type === ImageStdParams::THUMB) {
                continue;
            }
            if (! array_key_exists($type, ImageStdParams::get_defined_type_map())) {
                continue;
            }
            $url = $derivative->get_url();
            if (isset($added[$url])) {
                continue;
            }
            $added[$url] = 1;
            $show_original = $show_original && ! ($derivative->same_as_source());

            // in case we do not display the sizes icon, we only add the
            // selected size to unique_derivatives
            if (\Piwigo\Config\CurrentConfig::pictureSizesIcon() or $type === $deriv_type) {
                $unique_derivatives[$type] = $derivative;
            }
        }

        $template = \Piwigo\Template\CurrentTemplate::get();

        if ($show_original and isset($element_info['element_url'])) {
            $template->assign('U_ORIGINAL', $element_info['element_url']);
        }

        $template->append('current', [
            'selected_derivative' => $selected_derivative,
            'unique_derivatives' => $unique_derivatives,
        ], true);

        $template->set_filenames(
            [
                'default_content' => 'picture_content.tpl',
            ]
        );

        $template->assign(
            [
                'ALT_IMG' => $element_info['file'],
                'COOKIE_PATH' => new CookieService()
                    ->cookiePath(),
            ]
        );
        $event->content = $template->parse('default_content', true);

        return $event;
    }
}
