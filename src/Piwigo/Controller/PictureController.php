<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Doctrine\DBAL\Connection;
use Latte\Runtime\Html;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentService;
use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\DateService;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\Tables;
use Piwigo\Exception\NotFoundException;
use Piwigo\Filter\FilterContextRegistry;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\SrcImage;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Picture\PictureCommentRenderer;
use Piwigo\Picture\PictureContentRenderer;
use Piwigo\Picture\PictureContext;
use Piwigo\Picture\PictureContextRegistry;
use Piwigo\Picture\PictureMetadataRenderer;
use Piwigo\Picture\PictureRateRenderer;
use Piwigo\Picture\PictureService;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Rate\RateService;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Section\SectionInitializer;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagService;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles the single-image page (/picture/{rest}).
 * Corresponds to the former picture.php entry-point.
 */
final readonly class PictureController implements ControllerInterface
{
    public function __construct(
        private Connection $conn,
        private CategoryRepository $categoryRepository,
        private CategoryService $categoryService,
        private CommentService $commentService,
        private DateService $dateService,
        private HtmlService $htmlService,
        private ImageRepository $imageRepository,
        private MenubarRenderer $menubarRenderer,
        private PermissionService $permissionService,
        private PictureCommentRenderer $pictureCommentRenderer,
        private PictureMetadataRenderer $pictureMetadataRenderer,
        private PictureRateRenderer $pictureRateRenderer,
        private PictureService $pictureService,
        private RateService $rateService,
        private SectionInitializer $sectionInitializer,
        private SessionService $sessionService,
        private StringUtil $stringUtil,
        private TagService $tagService,
        private UrlGenerator $urlGenerator,
        private UrlService $urlService,
        private UserAdminService $userAdminService,
        private UserRepository $userRepository,
        private UserService $userService,
        private Util $util,
    ) {
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {

        $this->sectionInitializer->initialize(
            $request->withAttribute('_route_path', '/' . ($args['rest'] ?? '')),
            'picture'
        );

        $this->userService->saveEditContext();
        $this->permissionService->checkStatus(AccessLevel::Guest);

        /** @var array<string, mixed> $user */
        $user = CurrentUser::get()->rawAttributes;
        $ctx  = SectionContextRegistry::current();

        // Typed locals extracted from SectionContext after SectionInitializer has populated it
        $category  = $ctx->category;
        $catId     = $category !== null && is_scalar($category['id'] ?? null) ? (int) $category['id'] : 0;
        $items     = array_map(static fn (string $i): int => (int) $i, $ctx->items);
        $imageId   = $ctx->imageId !== null ? (int) $ctx->imageId : 0;

        if ($category !== null) {
            $this->categoryService->checkRestrictions($catId);
        }

        $rankOf = array_flip($items);

        if (!isset($rankOf[$imageId])) {
            $imageRepo = $this->imageRepository;
            if ($imageId > 0) {
                $row = $imageRepo->findById($imageId);
            } else {
                $imageFileStr = $ctx->imageFile;
                $replaced = str_replace(['_', '%'], ['/_', '/%'], $imageFileStr);
                $pattern = $replaced . '.%';
                $row     = $imageRepo->findByFilePattern($pattern);
            }
            if ($row === null) {
                $this->htmlService->pageNotFound('The requested image does not exist', $this->urlService->duplicateIndexUrl());
                return ResponseFactory::create(404);
            }
            if (is_numeric($row['level'] ?? null) && is_numeric($user['level'] ?? null) && $row['level'] > $user['level']) {
                $this->htmlService->accessDenied();
            }
            $resolvedImageFile = is_scalar($row['file'] ?? null) ? (string) $row['file'] : '';
            $imageId = is_scalar($row['id'] ?? null) ? (int) $row['id'] : 0;

            if (!isset($rankOf[$imageId])) {
                $visibleImages = FilterContextRegistry::current()->visibleImages;
                if ($visibleImages !== '' && !in_array($imageId, explode(',', $visibleImages))) {
                    $this->htmlService->pageNotFound('The requested image is filtered', $this->urlService->duplicateIndexUrl());
                    return ResponseFactory::create(404);
                }
                if ($ctx->section === 'categories' && $ctx->category === null) {
                    $this->htmlService->accessDenied();
                } else {
                    $query = '
SELECT id
  FROM ' . Tables::images() . ' INNER JOIN ' . Tables::imageCategory() . ' ON id=image_id
  WHERE id=' . $imageId
                        . $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'category_id'], ' AND') . '
  LIMIT 1';
                    if ($this->conn->executeQuery($query)->fetchOne() === false) {
                        $this->htmlService->accessDenied();
                    } else {
                        if ($ctx->section === 'best_rated') {
                            $rankOf[$imageId] = count($items);
                            $items[]          = $imageId;
                        } else {
                            $url = $this->urlService->makePictureUrl(['image_id' => $imageId, 'image_file' => $resolvedImageFile, 'section' => 'categories', 'flat' => true]);
                            $this->htmlService->setStatusHeader($ctx->section === 'recent_pics' ? 301 : 302);
                            $this->util->redirectHttp($url);
                        }
                    }
                }
            }
        }

        if ($this->stringUtil->inputString('metadata', null, $_GET) !== null) {
            if ($this->sessionService->getSessionVar('show_metadata') == null) {
                $this->sessionService->setSessionVar('show_metadata', 1);
            } else {
                $this->sessionService->unsetSessionVar('show_metadata');
            }
        }

        EventDispatcher::addListener('render_element_content', PictureContentRenderer::defaultContent(...));
        EventDispatcher::addListener('render_element_description', 'pwg_nl2br');

        EventDispatcher::notify('loc_begin_picture');

        $tpl = TemplateRegistry::current();

        $nbImagePage = $ctx->nbImagePage;

        $currentRank  = max(0, $rankOf[$imageId] ?? 0);
        $firstRank    = 0;
        $lastRank     = max(0, count($items) - 1);

        $previousItem = $currentRank !== $firstRank ? $items[$currentRank - 1] : null;
        $firstItem    = $currentRank !== $firstRank ? $items[$firstRank] : null;
        $nextItem     = $currentRank !== $lastRank ? $items[min($currentRank + 1, $lastRank)] : null;
        $lastItem     = $currentRank !== $lastRank ? $items[$lastRank] : null;

        $url_up = $this->urlService->duplicateIndexUrl(
            ['start' => (int) floor($currentRank / $nbImagePage) * $nbImagePage],
            ['start']
        );
        $url_self = $this->urlService->duplicatePictureUrl();

        // Actions
        $get_action = $this->stringUtil->inputString('action', null, $_GET);
        if ($get_action !== null) {
            switch ($get_action) {
                case 'add_to_favorites':
                    $this->userRepository->addFavorite(
                        is_numeric($user['id']) ? (int) $user['id'] : 0,
                        $imageId
                    );
                    $this->util->redirect($url_self);
                    break;
                case 'remove_from_favorites':
                    $this->userRepository->deleteFavorite(
                        is_numeric($user['id']) ? (int) $user['id'] : 0,
                        $imageId
                    );
                    $this->util->redirect($ctx->section === 'favorites' ? $url_up : $url_self);
                    break;
                case 'set_as_representative':
                    if ($this->permissionService->isAdmin() && $category !== null) {
                        $this->categoryRepository->setRepresentativePicture([$catId], $imageId);
                        $this->util->pwgActivity('album', $catId, 'edit', ['action' => $get_action, 'image_id' => $imageId]);
                        $this->userAdminService->invalidateUserCache();
                    }
                    $this->util->redirect($url_self);
                    break;
                case 'add_to_caddie':
                    $this->imageRepository->addToUserCaddie(CurrentUser::get()->id, [$imageId]);
                    $this->util->redirect($url_self);
                    break;
                case 'rate':
                    $this->rateService->ratePicture($imageId, $this->stringUtil->inputInt('rate', 0, $_POST));
                    $this->util->redirect($url_self);
                    break;
                case 'edit_comment':
                    $this->util->checkInputParameter('comment_to_edit', $_GET, false, ValidationPattern::ID);
                    $comment_to_edit = $this->stringUtil->inputInt('comment_to_edit', null, $_GET);
                    $author_id       = $this->commentService->getCommentAuthorId($comment_to_edit ?? 0);
                    if ($this->permissionService->canManageComment('edit', $author_id)) {
                        $post_content = $this->stringUtil->inputString('content', null, $_POST);
                        if ($post_content !== null && $post_content !== '') {
                            $this->util->checkPwgToken();
                            $comment_action = $this->commentService->updateUserComment(
                                ['comment_id' => $comment_to_edit, 'image_id' => $imageId, 'content' => $post_content, 'website_url' => $this->stringUtil->inputString('website_url', null, $_POST)],
                                $this->stringUtil->inputString('key', null, $_POST) ?? ''
                            );
                            $perform_redirect = false;
                            switch ($comment_action) {
                                case 'moderate':
                                    PageState::current()->addInfo(Lang::t('An administrator must authorize your comment before it is visible.'));
                                    // no break
                                case 'validate':
                                    PageState::current()->addInfo(Lang::t('Your comment has been registered'));
                                    $perform_redirect = true;
                                    break;
                                case 'reject':
                                    PageState::current()->addError(Lang::t('Your comment has NOT been registered because it did not pass the validation rules'));
                                    break;
                                default:
                                    throw new \LogicException('Invalid comment action: ' . $comment_action);
                            }
                            if ($perform_redirect) {
                                $this->util->redirect($url_self);
                            }
                            unset($_POST['content']);
                        }
                        $edit_comment = $comment_to_edit;
                    }
                    break;
                case 'delete_comment':
                    $this->util->checkPwgToken();
                    $this->util->checkInputParameter('comment_to_delete', $_GET, false, ValidationPattern::ID);
                    $author_id = $this->commentService->getCommentAuthorId($this->stringUtil->inputInt('comment_to_delete', null, $_GET) ?? 0);
                    if ($this->permissionService->canManageComment('delete', $author_id)) {
                        $this->commentService->deleteUserComment($this->stringUtil->inputInt('comment_to_delete', null, $_GET) ?? 0);
                    }
                    $this->util->redirect($url_self);
                    break;
                case 'validate_comment':
                    $this->util->checkPwgToken();
                    $this->util->checkInputParameter('comment_to_validate', $_GET, false, ValidationPattern::ID);
                    $author_id = $this->commentService->getCommentAuthorId($this->stringUtil->inputInt('comment_to_validate', null, $_GET) ?? 0);
                    if ($this->permissionService->canManageComment('validate', $author_id)) {
                        $this->commentService->validateUserComment($this->stringUtil->inputInt('comment_to_validate', null, $_GET) ?? 0);
                    }
                    $this->util->redirect($url_self);
                    break;
            }
        }

        // Hit counter
        $inc_hit_count = $this->stringUtil->inputString('content', null, $_POST) === null;
        if (isset($_SERVER['HTTP_X_MOZ']) && $_SERVER['HTTP_X_MOZ'] == 'prefetch') {
            $inc_hit_count = false;
        } else {
            if ($this->sessionService->getSessionVar('referer_image_id', 0) == $imageId) {
                $inc_hit_count = false;
            }
            $this->sessionService->setSessionVar('referer_image_id', $imageId);
        }
        if (EventDispatcher::dispatch('allow_increment_element_hit_count', $inc_hit_count, $imageId)) {
            $this->pictureService->increaseImageVisitCounter($imageId);
        }

        // Related categories
        $query = '
SELECT id,uppercats,commentable,visible,status,global_rank
  FROM ' . Tables::imageCategory() . '
    INNER JOIN ' . Tables::categories() . ' ON category_id = id
  WHERE image_id = ' . $imageId . '
' . $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'id', 'visible_categories' => 'id'], 'AND') . '
;';
        $related_categories = $this->conn->executeQuery($query)->fetchAllAssociative();
        usort($related_categories, $this->categoryService->globalRankCompare(...));

        // Load prev/current/next picture data
        $picture = [];
        $ids     = [$imageId];
        if ($previousItem !== null && $firstItem !== null) {
            $ids[] = $previousItem;
            $ids[] = $firstItem;
        }
        if ($nextItem !== null && $lastItem !== null) {
            $ids[] = $nextItem;
            $ids[] = $lastItem;
        }

        foreach ($this->imageRepository->findByIds(array_map(intval(...), $ids)) as $row) {
            if ($previousItem !== null && $row['id'] == $previousItem) {
                $i = 'previous';
            } elseif ($nextItem !== null && $row['id'] == $nextItem) {
                $i = 'next';
            } elseif ($firstItem !== null && $row['id'] == $firstItem) {
                $i = 'first';
            } elseif ($lastItem !== null && $row['id'] == $lastItem) {
                $i = 'last';
            } else {
                $i = 'current';
            }

            $src_id   = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            $src_path = is_scalar($row['path'] ?? null) ? (string) $row['path'] : '';
            $src_file = is_scalar($row['file'] ?? null) ? (string) $row['file'] : '';

            $row['src_image']  = new SrcImage($row);
            $row['derivatives'] = DerivativeImage::getAll($row['src_image']);
            $row['path_ext']   = strtolower($this->stringUtil->getExtension($src_path));
            $row['file_ext']   = strtolower($this->stringUtil->getExtension($src_file));

            if ($i == 'current') {
                $row['element_path'] = $this->stringUtil->getElementPath($row);
                if ($row['src_image']->isOriginal()) {
                    if ($user['enabled_high'] == 'true') {
                        $row['element_url']  = $row['src_image']->getUrl();
                        $row['download_url'] = $this->urlService->getActionUrl($src_id, 'e', true);
                    }
                } else {
                    $row['element_url']  = $this->urlService->getElementUrl($row);
                    $row['download_url'] = $this->urlService->getActionUrl($src_id, 'e', true);
                }
            }

            $row['url'] = $this->urlService->duplicatePictureUrl(['image_id' => $row['id'], 'image_file' => $row['file']], ['start']);
            $row['TITLE']     = $this->htmlService->renderElementName($row);
            $row['TITLE_ESC'] = str_replace('"', '&quot;', $row['TITLE']);
            $picture[$i]      = $row;

            if ('previous' == $i && $previousItem === $firstItem) {
                $picture['first'] = $row;
            }
            if ('next' == $i && $nextItem === $lastItem) {
                $picture['last'] = $row;
            }
        }

        if (!isset($picture['current'])) {
            throw new NotFoundException('Current picture not found.');
        }

        $slideshow_params     = [];
        $slideshow_url_params = [];
        $get_slideshow        = $this->stringUtil->inputString('slideshow', null, $_GET);

        if ($get_slideshow !== null) {
            $slideshowActive  = true;
            PageState::current()->metaRobots = ['noindex' => 1, 'nofollow' => 1];
            $slideshow_params     = $this->pictureService->decodeSlideshowParams($get_slideshow);
            $slideshow_url_params['slideshow'] = $this->pictureService->encodeSlideshowParams($slideshow_params);

            if ($slideshow_params['play']) {
                $id_pict_redirect = '';
                if ($nextItem !== null) {
                    $id_pict_redirect = 'next';
                } elseif ($slideshow_params['repeat'] && $firstItem !== null) {
                    $id_pict_redirect = 'first';
                }
                if (!empty($id_pict_redirect) && isset($picture[$id_pict_redirect])) {
                    $refresh  = $slideshow_params['period'];
                    $url_link = $this->urlService->addUrlParams($picture[$id_pict_redirect]['url'], $slideshow_url_params);
                }
            }
        } else {
            $slideshowActive = false;
        }

        $title    = $picture['current']['TITLE'];
        $title_nb = ($currentRank + 1) . '/' . count($items);

        $url_metadata     = $this->urlService->duplicatePictureUrl();
        $url_metadata     = $this->urlService->addUrlParams($url_metadata, ['metadata' => null]);
        $curSrcImg = $picture['current']['src_image'];
        $metadata_showable = EventDispatcher::dispatch(
            'get_element_metadata_available',
            (Config::showExif() || Config::showIptc()) && !$curSrcImg->isMimetype(),
            $picture['current']
        );

        $ps = PageState::current();
        if ($this->stringUtil->inputString('metadata', null, $_GET) !== null) {
            $ps->metaRobots = ['noindex' => 1, 'nofollow' => 1];
        }

        $ps->bodyId = 'thePicturePage';

        /** @var array<string, array<string, mixed>> $picture */
        $picture    = EventDispatcher::dispatch('picture_pictures_data', $picture);
        $currentPic = is_array($picture['current'] ?? null) ? $picture['current'] : [];
        $currentSrcImage = ($currentPic['src_image'] ?? null) instanceof SrcImage ? $currentPic['src_image'] : null;

        foreach (['first', 'previous', 'next', 'last', 'current'] as $which_image) {
            if (isset($picture[$which_image])) {
                $imgArr = $picture[$which_image];
                $tpl->assign($which_image, array_merge($imgArr, ['U_IMG' => $this->urlService->addUrlParams(is_string($imgArr['url'] ?? null) ? $imgArr['url'] : '', $slideshow_url_params)]));
            }
        }

        if (Config::pictureDownloadIcon() && isset($picture['current']['download_url']) && $picture['current']['download_url'] !== '' && $user['enabled_high'] == 'true') {
            $tpl->append('current', ['U_DOWNLOAD' => $picture['current']['download_url']], true);

            if (Config::isFormatsEnabled()) {
                $query = '
SELECT *
  FROM ' . Tables::imageFormat() . '
  WHERE image_id = ' . (is_scalar($currentPic['id'] ?? null) ? (int) $currentPic['id'] : 0) . '
;';
                $formats = $this->conn->executeQuery($query)->fetchAllAssociative();
                array_unshift($formats, [
                    'download_url' => is_scalar($currentPic['download_url'] ?? null) ? $currentPic['download_url'] : '',
                    'ext'          => $this->stringUtil->getExtension(is_string($currentPic['file'] ?? null) ? $currentPic['file'] : ''),
                    'filesize'     => $currentPic['filesize'] ?? null,
                ]);
                foreach ($formats as &$format) {
                    if (!isset($format['download_url'])) {
                        $format['download_url'] = $this->urlGenerator->actionFormat((int) (is_scalar($format['format_id'] ?? null) ? $format['format_id'] : 0));
                    }
                    $fmtExtRaw        = $format['ext'] ?? null;
                    $extStr           = is_scalar($fmtExtRaw) ? (string) $fmtExtRaw : '';
                    $format['label']  = strtoupper($extStr);
                    $lang_key         = 'format ' . strtoupper($extStr);
                    if (Lang::has($lang_key)) {
                        $format['label'] = Lang::t($lang_key);
                    }
                    $fsRaw                = $format['filesize'] ?? 0;
                    $format['filesize']   = sprintf('%.1fMB', (is_numeric($fsRaw) ? (float) $fsRaw : 0.0) / 1024.0);
                }
                $tpl->append('current', ['formats' => $formats], true);
            }
        }

        PictureContextRegistry::set(new PictureContext(
            currentItem:       $imageId,
            nextItem:          $nextItem,
            previousItem:      $previousItem,
            firstItem:         $firstItem,
            lastItem:          $lastItem,
            currentRank:       $currentRank,
            lastRank:          $lastRank,
            rankOf:            $rankOf,
            slideshow:         $slideshowActive,
            ratingScore:       is_numeric($currentPic['rating_score'] ?? null) ? (float) $currentPic['rating_score'] : null,
            srcImage:          ($currentPic['src_image'] ?? null) instanceof SrcImage ? $currentPic['src_image'] : null,
            relatedCategories: $related_categories,
        ));

        // Slideshow controls
        if ($slideshowActive) {
            $tpl_slideshow = [];
            $currentUrl    = is_string($picture['current']['url'] ?? null) ? $picture['current']['url'] : '';
            $tpl->assign(['U_SLIDESHOW_STOP' => $currentUrl]);
            foreach (['repeat', 'play'] as $p) {
                $pVal = $slideshow_params[$p] ?? false;
                $pValBool = $pVal !== false && $pVal !== '' && $pVal !== 0 && $pVal !== [];
                $var_name = 'U_' . ($pValBool ? 'STOP_' : 'START_') . strtoupper($p);
                $tpl_slideshow[$var_name] = $this->urlService->addUrlParams($currentUrl, ['slideshow' => $this->pictureService->encodeSlideshowParams(array_merge($slideshow_params, [$p => !$pValBool]))]);
            }
            foreach (['dec', 'inc'] as $op) {
                $periodRaw = $slideshow_params['period'] ?? 0;
                $new_period  = (is_numeric($periodRaw) ? (int) $periodRaw : 0) + (($op == 'dec' ? -1 : 1) * Config::slideshowPeriodStep());
                $new_params  = $this->pictureService->correctSlideshowParams(array_merge($slideshow_params, ['period' => $new_period]));
                if ($new_params['period'] === $new_period) {
                    $tpl_slideshow['U_' . strtoupper($op) . '_PERIOD'] = $this->urlService->addUrlParams($currentUrl, ['slideshow' => $this->pictureService->encodeSlideshowParams($new_params)]);
                }
            }
            $tpl->assign('slideshow', $tpl_slideshow);
        } elseif (Config::pictureSlideShowIcon()) {
            $currentUrl = is_string($picture['current']['url'] ?? null) ? $picture['current']['url'] : '';
            $tpl->assign(['U_SLIDESHOW_START' => $this->urlService->addUrlParams($currentUrl, ['slideshow' => ''])]);
        }

        $tpl->assign([
            'SECTION_TITLE'        => new Html($ctx->sectionTitle),
            'PHOTO'                => $title_nb,
            'IS_HOME'              => ($ctx->section === 'categories' && $ctx->category === null),
            'LEVEL_SEPARATOR'      => Config::levelSeparator(),
            'U_UP'                 => $url_up,
            'DISPLAY_NAV_BUTTONS'  => Config::pictureNavigationIcons(),
            'DISPLAY_NAV_THUMB'    => Config::pictureNavigationThumb(),
        ]);

        if (Config::pictureMetadataIcon()) {
            $tpl->assign('U_METADATA', $url_metadata);
        }

        if ($this->permissionService->isAdmin()) {
            if ($ctx->category !== null && Config::pictureRepresentativeIcon()) {
                $tpl->assign(['U_SET_AS_REPRESENTATIVE' => $this->urlService->addUrlParams($url_self, ['action' => 'set_as_representative'])]);
            }
            if (Config::pictureEditIcon()) {
                $tpl->assign('U_PHOTO_ADMIN', $this->urlGenerator->admin('photo-' . $imageId));
            }
            if (Config::pictureCaddieIcon()) {
                $tpl->assign('U_CADDIE', $this->urlService->addUrlParams($url_self, ['action' => 'add_to_caddie']));
            }
        }

        if (!$this->permissionService->isAGuest() && Config::pictureFavoriteIcon()) {
            $is_favorite = $this->userRepository->isFavorite(
                is_numeric($user['id']) ? (int) $user['id'] : 0,
                $imageId
            );
            $tpl->assign('favorite', ['IS_FAVORITE' => $is_favorite, 'U_FAVORITE' => $this->urlService->addUrlParams($url_self, ['action' => !$is_favorite ? 'add_to_favorites' : 'remove_from_favorites'])]);
        }

        // Picture info
        $infos = [];
        if (isset($picture['current']['comment']) && $picture['current']['comment'] !== '') {
            /** @var mixed $commentValue */
            $commentValue = $picture['current']['comment'];
            $rendered = EventDispatcher::dispatch('render_element_description', $commentValue, 'picture_page_element_description');
            $tpl->assign('COMMENT_IMG', new Html(is_string($rendered) ? $rendered : ''));
        }
        if (isset($currentPic['author']) && $currentPic['author'] !== '') {
            /** @var mixed $authorValue */
            $authorValue = $currentPic['author'];
            $infos['INFO_AUTHOR'] = $authorValue;
        }
        if (isset($currentPic['date_creation']) && $currentPic['date_creation'] !== '') {
            $dc   = (is_string($currentPic['date_creation']) || is_int($currentPic['date_creation'])) ? $currentPic['date_creation'] : null;
            $val  = $this->dateService->formatDate($dc);
            $url  = $this->urlService->makeIndexUrl(['chronology_field' => 'created', 'chronology_style' => 'monthly', 'chronology_view' => 'list', 'chronology_date' => explode('-', substr(is_scalar($dc) ? (string) $dc : '', 0, 10))]);
            $infos['INFO_CREATION_DATE'] = new Html('<a href="' . $url . '" rel="nofollow">' . $val . '</a>');
        }
        $da  = (isset($currentPic['date_available']) && (is_string($currentPic['date_available']) || is_int($currentPic['date_available']))) ? $currentPic['date_available'] : null;
        $val = $this->dateService->formatDate($da);
        $url = $this->urlService->makeIndexUrl(['chronology_field' => 'posted', 'chronology_style' => 'monthly', 'chronology_view' => 'list', 'chronology_date' => explode('-', substr(is_scalar($da) ? (string) $da : '', 0, 10))]);
        $infos['INFO_POSTED_DATE'] = new Html('<a href="' . $url . '" rel="nofollow">' . $val . '</a>');

        if ($currentSrcImage !== null && $currentSrcImage->isOriginal() && isset($currentPic['width'])) {
            $picHeightRaw = $currentPic['height'] ?? null;
            $infos['INFO_DIMENSIONS'] = (is_string($currentPic['width']) ? $currentPic['width'] : '') . '*' . (is_scalar($picHeightRaw) ? (string) $picHeightRaw : '');
        }
        if (isset($currentPic['filesize']) && $currentPic['filesize'] !== '') {
            $filesize = $currentPic['filesize'];
            $infos['INFO_FILESIZE'] = Lang::t('%d Kb', is_numeric($filesize) ? (int) $filesize : 0);
        }
        $infos['INFO_VISITS'] = $currentPic['hit'] ?? null;
        $infos['INFO_FILE']   = $currentPic['file'] ?? null;

        $tpl->assign($infos);
        $tpl->assign('display_info', unserialize(Config::pictureInformations() ?? ''));

        // Related tags
        $tags = $this->tagService->getCommonTags([$imageId], -1);
        foreach ($tags as $tag) {
            $tagArr = is_array($tag) ? $tag : [];
            $tpl->append('related_tags', array_merge($tagArr, ['URL' => $this->urlService->makeIndexUrl(['tags' => [$tag]]), 'U_TAG_IMAGE' => $this->urlService->duplicatePictureUrl(['section' => 'tags', 'tags' => [$tag]])]));
        }

        // Related categories
        if (count($related_categories) == 1 && $category !== null && $related_categories[0]['id'] == $catId) {
            $upperNames = is_array($category['upper_names'] ?? null) ? $category['upper_names'] : [];
            $tpl->append('related_categories', new Html($this->htmlService->getCatDisplayName($upperNames)));
        } else {
            $ids = [];
            foreach ($related_categories as $category) {
                $ids = array_merge($ids, explode(',', is_string($category['uppercats'] ?? null) ? $category['uppercats'] : ''));
            }
            $ids    = array_unique($ids);
            $query  = 'SELECT id, name, permalink FROM ' . Tables::categories() . ' WHERE id IN (' . implode(',', $ids) . ')';
            $catMap = array_column($this->conn->executeQuery($query)->fetchAllAssociative(), null, 'id');
            foreach ($related_categories as $category) {
                $cats = [];
                foreach (explode(',', is_string($category['uppercats'] ?? null) ? $category['uppercats'] : '') as $id) {
                    $cats[] = $catMap[$id];
                }
                $tpl->append('related_categories', new Html($this->htmlService->getCatDisplayName($cats)));
            }
        }

        if (in_array(strtolower($this->stringUtil->getExtension(is_string($currentPic['file'] ?? null) ? $currentPic['file'] : '')), ['pdf'])) {
            $tpl->assign(['PDF_VIEWER_FILESIZE_THRESHOLD' => Config::pdfViewerFilesizeThreshold() * 1024, 'PDF_NB_PAGES' => $this->pictureService->countPdfPages(is_string($currentPic['path'] ?? null) ? $currentPic['path'] : '')]);
        }

        $element_content = EventDispatcher::dispatch('render_element_content', '', $picture['current']);
        $tpl->assign('ELEMENT_CONTENT', new Html((string) $element_content));

        $nextPic      = is_array($picture['next'] ?? null) ? $picture['next'] : null;
        $nextSrcImage = ($nextPic !== null && ($nextPic['src_image'] ?? null) instanceof SrcImage) ? $nextPic['src_image'] : null;
        /** @var mixed $httpUserAgentRaw */
        $httpUserAgentRaw = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $httpUserAgent    = is_string($httpUserAgentRaw) ? $httpUserAgentRaw : '';
        if ($nextPic !== null && $nextSrcImage !== null && $nextSrcImage->isOriginal() && $tpl->getTemplateVars('U_PREFETCH') == null
            && !str_contains($httpUserAgent, 'Chrome/')
        ) {
            $derivRaw   = $this->sessionService->getSessionVar('picture_deriv', Config::derivativeDefaultSize());
            $derivType  = is_string($derivRaw) ? $derivRaw : Config::derivativeDefaultSize();
            $nextDerivsRaw = $nextPic['derivatives'] ?? null;
            $nextDerivs = is_array($nextDerivsRaw) ? $nextDerivsRaw : [];
            $nextDeriv  = ($nextDerivs[$derivType] ?? null) instanceof DerivativeImage ? $nextDerivs[$derivType] : null;
            if ($nextDeriv !== null) {
                $tpl->assign('U_PREFETCH', $nextDeriv->getUrl());
            }
        }

        $tpl->assign('U_CANONICAL', $this->urlService->makePictureUrl(['image_id' => $currentPic['id'] ?? null, 'image_file' => $currentPic['file'] ?? null]));

        $this->pictureRateRenderer->render();
        if (Config::activateComments()) {
            $this->pictureCommentRenderer->render($edit_comment ?? null);
        }
        if ($metadata_showable && isset($_SESSION['pwg_show_metadata'])) {
            $this->pictureMetadataRenderer->render();
        }

        $themeconf    = $tpl->getTemplateVars('themeconf');
        $themeconfArr = is_array($themeconf) ? $themeconf : [];
        $hideMenuOn   = is_array($themeconfArr['hide_menu_on'] ?? null) ? $themeconfArr['hide_menu_on'] : [];
        if (Config::pictureMenu() && !in_array('thePicturePage', $hideMenuOn)) {
            $this->menubarRenderer->render();
        }

        PageHeaderRenderer::render($title, isset($refresh) && is_int($refresh) ? $refresh : null, $url_link ?? null);
        EventDispatcher::notify('loc_end_picture');
        $this->htmlService->flushPageMessages();
        if ($slideshowActive && Config::lightSlideshow()) {
            $tpl->pparse('slideshow.latte');
        } else {
            $tpl->parsePictureButtons();
            $tpl->pparse('picture.latte');
        }

        $picIdRaw = $currentPic['id'] ?? null;
        $this->util->pwgLog((is_int($picIdRaw) || is_string($picIdRaw)) ? $picIdRaw : null, 'picture');
        PageTailRenderer::render();

        return ResponseFactory::create(200);
    }
}
