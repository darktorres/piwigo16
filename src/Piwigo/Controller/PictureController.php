<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Latte\Runtime\Html;
use Piwigo\Activity\ActivityAction;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentManagementAction;
use Piwigo\Comment\CommentModerationAction;
use Piwigo\Comment\CommentService;
use Piwigo\Common\Enum\Section;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\DateService;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Core\ValidationPattern;
use Piwigo\Csrf\CsrfService;
use Piwigo\Event\Location\LocBeginPicture;
use Piwigo\Event\Location\LocEndPicture;
use Piwigo\Event\Picture\AllowIncrementElementHitCount;
use Piwigo\Event\Picture\GetElementMetadataAvailable;
use Piwigo\Event\Picture\PicturePicturesData;
use Piwigo\Event\Picture\RenderElementContent;
use Piwigo\Event\Picture\RenderElementDescription;
use Piwigo\Exception\NotFoundException;
use Piwigo\Filter\FilterContextRegistry;
use Piwigo\Html\HtmlService;
use Piwigo\Http\RedirectResponder;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageFormatRepository;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\View\PictureViewModel;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Picture\PictureCommentRenderer;
use Piwigo\Picture\PictureContext;
use Piwigo\Picture\PictureContextRegistry;
use Piwigo\Picture\PictureMetadataRenderer;
use Piwigo\Picture\PictureRateRenderer;
use Piwigo\Picture\PictureService;
use Piwigo\Rate\RateService;
use Piwigo\Section\SectionContext;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Section\SectionInitializer;
use Piwigo\Session\Session;
use Piwigo\Tag\TagService;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserCaddieRepository;
use Piwigo\Users\UserFavoriteRepository;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles the single-image page (/picture/{rest}).
 * Corresponds to the former picture.php entry-point.
 */
final readonly class PictureController implements ControllerInterface
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private CategoryService $categoryService,
        private CommentService $commentService,
        private DateService $dateService,
        private HtmlService $htmlService,
        private ImageFormatRepository $imageFormatRepository,
        private ImageRepository $imageRepository,
        private MenubarRenderer $menubarRenderer,
        private PermissionService $permissionService,
        private PictureCommentRenderer $pictureCommentRenderer,
        private PictureMetadataRenderer $pictureMetadataRenderer,
        private PictureRateRenderer $pictureRateRenderer,
        private PictureService $pictureService,
        private RateService $rateService,
        private SectionInitializer $sectionInitializer,
        private Session $session,
        private TagService $tagService,
        private UrlGenerator $urlGenerator,
        private UrlService $urlService,
        private UserAdminService $userAdminService,
        private UserCaddieRepository $userCaddieRepository,
        private UserFavoriteRepository $userFavoriteRepository,
        private UserService $userService,
        private ActivityLogger $activityLogger,
        private CsrfService $csrfService,
        private InputValidator $inputValidator,
        private RedirectResponder $redirectResponder,
        private EventDispatcherInterface $dispatcher,
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
            $earlyResponse = $this->resolveImageNotInSet($imageId, $items, $rankOf, $catId, $ctx, $user);
            if ($earlyResponse !== null) {
                return $earlyResponse;
            }
        }

        if (StringUtil::inputString('metadata', null, $_GET) !== null) {
            // Toggle: ?metadata flips showMetadata on if off and vice versa.
            $this->session->showMetadata = !$this->session->showMetadata;
        }

        // render_element_content / render_element_description listeners now
        // register at container build time via CoreSubscribers
        // (RenderElementContentSubscriber, RenderElementDescriptionSubscriber).
        $this->dispatcher->dispatch(new LocBeginPicture());

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
        $edit_comment = null;
        $get_action   = StringUtil::inputString('action', null, $_GET);
        if ($get_action !== null) {
            $edit_comment = $this->handlePictureAction($get_action, $imageId, $url_self, $url_up, $catId, $ctx);
        }

        // Hit counter
        $inc_hit_count = StringUtil::inputString('content', null, $_POST) === null;
        if (isset($_SERVER['HTTP_X_MOZ']) && $_SERVER['HTTP_X_MOZ'] == 'prefetch') {
            $inc_hit_count = false;
        } else {
            if ($this->session->refererImageId !== null && $this->session->refererImageId->value === $imageId) {
                $inc_hit_count = false;
            }
            $this->session->refererImageId = ImageId::from($imageId);
        }
        $allowEvent = new AllowIncrementElementHitCount($inc_hit_count);
        $this->dispatcher->dispatch($allowEvent);
        if ($allowEvent->contentNotSet) {
            $this->pictureService->increaseImageVisitCounter($imageId);
        }

        // Related categories
        $relPerm = $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'id', 'visible_categories' => 'id'], 'AND');
        $related_categories = array_map(
            static fn (\Piwigo\Category\Projection\PictureNavCategoryRow $r): array => $r->toRow(),
            $this->categoryRepository->findPictureNavCategoriesForImage($imageId, $relPerm->where, $relPerm->params, $relPerm->types),
        );
        usort($related_categories, $this->categoryService->globalRankCompare(...));

        // Load prev/current/next picture data
        $picture = [];
        /** @var array<string, PictureViewModel> $pictureVms */
        $pictureVms = [];
        $ids     = [$imageId];
        if ($previousItem !== null && $firstItem !== null) {
            $ids[] = $previousItem;
            $ids[] = $firstItem;
        }
        if ($nextItem !== null && $lastItem !== null) {
            $ids[] = $nextItem;
            $ids[] = $lastItem;
        }

        foreach ($this->imageRepository->findByIds(array_map(intval(...), $ids)) as $img) {
            $imgId = $img->id->value;
            if ($previousItem !== null && $imgId === $previousItem) {
                $i = 'previous';
            } elseif ($nextItem !== null && $imgId === $nextItem) {
                $i = 'next';
            } elseif ($firstItem !== null && $imgId === $firstItem) {
                $i = 'first';
            } elseif ($lastItem !== null && $imgId === $lastItem) {
                $i = 'last';
            } else {
                $i = 'current';
            }

            $vm = PictureViewModel::fromImage($img);

            if ($i === 'current') {
                $elementPath = StringUtil::getElementPath(['path' => $img->path->value]);
                $elementUrl  = null;
                $downloadUrl = null;
                if ($vm->srcImage->isOriginal()) {
                    if (BoolUtil::fromMixed($user['enabled_high'])) {
                        $url = $vm->srcImage->getUrl();
                        $elementUrl  = is_string($url) ? $url : '';
                        $downloadUrl = $this->urlService->getActionUrl($imgId, 'e', true);
                    }
                } else {
                    $elementUrl  = $this->urlService->getElementUrl([
                        'id'                 => $imgId,
                        'path'               => $img->path->value,
                        'file'               => $img->file->value,
                        'representative_ext' => $img->representativeExt,
                    ]);
                    $downloadUrl = $this->urlService->getActionUrl($imgId, 'e', true);
                }
                $vm = $vm->withCurrentExtras($elementPath, $elementUrl, $downloadUrl);
            }

            $url   = $this->urlService->duplicatePictureUrl(['image_id' => $imgId, 'image_file' => $img->file->value], ['start']);
            $title = $this->htmlService->renderElementName([
                'name' => $img->name,
                'file' => $img->file->value,
            ]);
            $vm = $vm->withUrl($url)->withTitle($title, str_replace('"', '&quot;', $title));

            $row = $vm->toArray();
            $picture[$i] = $row;
            $pictureVms[$i] = $vm;

            if ($i === 'previous' && $previousItem === $firstItem) {
                $picture['first']    = $row;
                $pictureVms['first'] = $vm;
            }
            if ($i === 'next' && $nextItem === $lastItem) {
                $picture['last']    = $row;
                $pictureVms['last'] = $vm;
            }
        }

        if (!isset($picture['current'])) {
            throw new NotFoundException('Current picture not found.');
        }

        $slideshow_params     = [];
        $slideshow_url_params = [];
        $get_slideshow        = StringUtil::inputString('slideshow', null, $_GET);

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
                if (!empty($id_pict_redirect) && isset($pictureVms[$id_pict_redirect])) {
                    $refresh  = $slideshow_params['period'];
                    $url_link = $this->urlService->addUrlParams($pictureVms[$id_pict_redirect]->url, $slideshow_url_params);
                }
            }
        } else {
            $slideshowActive = false;
        }

        $title    = $pictureVms['current']->title;
        $title_nb = ($currentRank + 1) . '/' . count($items);

        $url_metadata     = $this->urlService->duplicatePictureUrl();
        $url_metadata     = $this->urlService->addUrlParams($url_metadata, ['metadata' => null]);
        $curSrcImg = $pictureVms['current']->srcImage;
        $metadataEvent = new GetElementMetadataAvailable(
            (Config::showExif() || Config::showIptc()) && !$curSrcImg->isMimetype(),
            $picture['current']
        );
        $this->dispatcher->dispatch($metadataEvent);
        $metadata_showable = $metadataEvent->available;

        $ps = PageState::current();
        if (StringUtil::inputString('metadata', null, $_GET) !== null) {
            $ps->metaRobots = ['noindex' => 1, 'nofollow' => 1];
        }

        $ps->bodyId = 'thePicturePage';

        $pictureDataEvent = new PicturePicturesData($picture);
        $this->dispatcher->dispatch($pictureDataEvent);
        /** @var array<string, array<string, mixed>> $picture */
        $picture = $pictureDataEvent->picture;
        $currentVm = $pictureVms['current'];

        foreach (['first', 'previous', 'next', 'last', 'current'] as $which_image) {
            if (isset($picture[$which_image])) {
                $imgArr = $picture[$which_image];
                $tpl->assign($which_image, array_merge($imgArr, ['U_IMG' => $this->urlService->addUrlParams(is_string($imgArr['url'] ?? null) ? $imgArr['url'] : '', $slideshow_url_params)]));
            }
        }

        if (Config::pictureDownloadIcon() && $currentVm->downloadUrl !== null && $currentVm->downloadUrl !== '' && BoolUtil::fromMixed($user['enabled_high'])) {
            $tpl->append('current', ['U_DOWNLOAD' => $currentVm->downloadUrl], true);

            if (Config::isFormatsEnabled()) {
                $formats = $this->imageFormatRepository->findByImageIds([$currentVm->image->id->value]);
                array_unshift($formats, [
                    'download_url' => $currentVm->downloadUrl,
                    'ext'          => StringUtil::getExtension($currentVm->image->file->value),
                    'filesize'     => $currentVm->image->filesize,
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
            ratingScore:       $currentVm->image->ratingScore,
            srcImage:          $currentVm->srcImage,
            relatedCategories: $related_categories,
        ));

        // Slideshow controls
        if ($slideshowActive) {
            $tpl_slideshow = [];
            $currentUrl    = $currentVm->url;
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
            $tpl->assign(['U_SLIDESHOW_START' => $this->urlService->addUrlParams($currentVm->url, ['slideshow' => ''])]);
        }

        $tpl->assign([
            'SECTION_TITLE'        => new Html($ctx->sectionTitle),
            'PHOTO'                => $title_nb,
            'IS_HOME'              => ($ctx->section === Section::Categories && $ctx->category === null),
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
            $is_favorite = $this->userFavoriteRepository->exists(
                is_numeric($user['id']) ? (int) $user['id'] : 0,
                $imageId
            );
            $tpl->assign('favorite', ['IS_FAVORITE' => $is_favorite, 'U_FAVORITE' => $this->urlService->addUrlParams($url_self, ['action' => !$is_favorite ? 'add_to_favorites' : 'remove_from_favorites'])]);
        }

        // Picture info
        $infos = [];
        if ($currentVm->image->comment !== null && $currentVm->image->comment !== '') {
            $descEvent = new RenderElementDescription($currentVm->image->comment, 'picture_page_element_description');
            $this->dispatcher->dispatch($descEvent);
            $tpl->assign('COMMENT_IMG', new Html($descEvent->elementDescription));
        }
        if ($currentVm->image->author !== null && $currentVm->image->author !== '') {
            $infos['INFO_AUTHOR'] = $currentVm->image->author;
        }
        if ($currentVm->image->dateCreation !== null) {
            $dc   = $currentVm->image->dateCreation->value;
            $val  = $this->dateService->formatDate($dc);
            $url  = $this->urlService->makeIndexUrl(['chronology_field' => 'created', 'chronology_style' => 'monthly', 'chronology_view' => 'list', 'chronology_date' => explode('-', substr($dc, 0, 10))]);
            $infos['INFO_CREATION_DATE'] = new Html('<a href="' . $url . '" rel="nofollow">' . $val . '</a>');
        }
        $da  = $currentVm->image->dateAvailable?->value;
        $val = $this->dateService->formatDate($da);
        $url = $this->urlService->makeIndexUrl(['chronology_field' => 'posted', 'chronology_style' => 'monthly', 'chronology_view' => 'list', 'chronology_date' => explode('-', substr($da ?? '', 0, 10))]);
        $infos['INFO_POSTED_DATE'] = new Html('<a href="' . $url . '" rel="nofollow">' . $val . '</a>');

        if ($currentVm->srcImage->isOriginal() && $currentVm->image->width !== null) {
            $infos['INFO_DIMENSIONS'] = $currentVm->image->width . '*' . ($currentVm->image->height ?? '');
        }
        if ($currentVm->image->filesize !== null) {
            $infos['INFO_FILESIZE'] = Lang::t('%d Kb', $currentVm->image->filesize);
        }
        $infos['INFO_VISITS'] = $currentVm->image->hit;
        $infos['INFO_FILE']   = $currentVm->image->file->value;

        $tpl->assign($infos);
        $tpl->assign('display_info', Config::pictureInformations());

        // Related tags
        foreach ($this->tagService->getCommonTags([$imageId], -1) as $tag) {
            $tpl->append('related_tags', array_merge($tag, ['URL' => $this->urlService->makeIndexUrl(['tags' => [$tag]]), 'U_TAG_IMAGE' => $this->urlService->duplicatePictureUrl(['section' => 'tags', 'tags' => [$tag]])]));
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
            $idsInt = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_values(array_unique($ids)));
            $catMap = $this->categoryRepository->findNamePermalinkByIdsKeyedById($idsInt);
            foreach ($related_categories as $category) {
                $cats = [];
                foreach (explode(',', is_string($category['uppercats'] ?? null) ? $category['uppercats'] : '') as $id) {
                    $idInt = (int) $id;
                    if (isset($catMap[$idInt])) {
                        $cats[] = $catMap[$idInt]->toRow();
                    }
                }
                $tpl->append('related_categories', new Html($this->htmlService->getCatDisplayName($cats)));
            }
        }

        if ($currentVm->pathExt === 'pdf') {
            $tpl->assign(['PDF_VIEWER_FILESIZE_THRESHOLD' => Config::pdfViewerFilesizeThreshold() * 1024, 'PDF_NB_PAGES' => $this->pictureService->countPdfPages($currentVm->image->path->value)]);
        }

        // RenderElementContent listeners (plugins/themes) may inspect the
        // full picture row; the maybe-plugin-mutated `$picture['current']`
        // is what flows in, not the typed VM.
        $rawCurrent = $picture['current'] ?? null;
        $currentPic = is_array($rawCurrent) ? $rawCurrent : [];
        $contentEvent      = new RenderElementContent('', $currentPic);
        $this->dispatcher->dispatch($contentEvent);
        $tpl->assign('ELEMENT_CONTENT', new Html($contentEvent->content));

        $nextVm       = $pictureVms['next'] ?? null;
        $nextSrcImage = $nextVm?->srcImage;
        /** @var mixed $httpUserAgentRaw */
        $httpUserAgentRaw = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $httpUserAgent    = is_string($httpUserAgentRaw) ? $httpUserAgentRaw : '';
        if ($nextVm !== null && $nextSrcImage !== null && $nextSrcImage->isOriginal() && $tpl->getTemplateVars('U_PREFETCH') == null
            && !str_contains($httpUserAgent, 'Chrome/')
        ) {
            $derivType = $this->session->pictureDeriv !== null ? $this->session->pictureDeriv->value : Config::derivativeDefaultSize();
            $nextDeriv = $nextVm->derivatives[$derivType] ?? null;
            if ($nextDeriv !== null) {
                $tpl->assign('U_PREFETCH', $nextDeriv->getUrl());
            }
        }

        $tpl->assign('U_CANONICAL', $this->urlService->makePictureUrl(['image_id' => $currentVm->image->id->value, 'image_file' => $currentVm->image->file->value]));

        $this->pictureRateRenderer->render();
        if (Config::activateComments()) {
            $this->pictureCommentRenderer->render($edit_comment ?? null);
        }
        if ($metadata_showable && $this->session->showMetadata) {
            $this->pictureMetadataRenderer->render();
        }

        $themeconf    = $tpl->getTemplateVars('themeconf');
        $themeconfArr = is_array($themeconf) ? $themeconf : [];
        $hideMenuOn   = is_array($themeconfArr['hide_menu_on'] ?? null) ? $themeconfArr['hide_menu_on'] : [];
        if (Config::pictureMenu() && !in_array('thePicturePage', $hideMenuOn)) {
            $this->menubarRenderer->render();
        }

        PageHeaderRenderer::render($title, isset($refresh) && is_int($refresh) ? $refresh : null, $url_link ?? null);
        $this->dispatcher->dispatch(new LocEndPicture());
        $this->htmlService->flushPageMessages();
        if ($slideshowActive && Config::lightSlideshow()) {
            $tpl->pparse('slideshow.latte');
        } else {
            $tpl->parsePictureButtons();
            $tpl->pparse('picture.latte');
        }

        $this->activityLogger->pageView($currentVm->image->id->value, 'picture');
        PageTailRenderer::render();

        return ResponseFactory::create(200);
    }

    /**
     * Dispatches a picture-page action (add/remove favorites, rate, comment management, etc.).
     * Most cases redirect immediately; only `edit_comment` returns the comment ID for later use.
     */
    private function handlePictureAction(
        string $action,
        int $imageId,
        string $urlSelf,
        string $urlUp,
        int $catId,
        SectionContext $ctx,
    ): ?int {
        $userId = CurrentUser::get()->id;

        switch ($action) {
            case 'add_to_favorites':
                $this->userFavoriteRepository->add($userId, $imageId);
                $this->redirectResponder->redirect($urlSelf);
                break;
            case 'remove_from_favorites':
                $this->userFavoriteRepository->delete($userId, $imageId);
                $this->redirectResponder->redirect($ctx->section === Section::Favorites ? $urlUp : $urlSelf);
                break;
            case 'set_as_representative':
                if ($this->permissionService->isAdmin() && $ctx->category !== null) {
                    $this->categoryRepository->setRepresentativePicture([$catId], $imageId);
                    $this->activityLogger->log(new ActivityEvent(ActivityObject::Album, $catId, ActivityAction::Edit, ['action' => $action, 'image_id' => $imageId]));
                    $this->userAdminService->invalidateUserCache();
                }
                $this->redirectResponder->redirect($urlSelf);
                break;
            case 'add_to_caddie':
                $this->userCaddieRepository->addElements($userId, [$imageId]);
                $this->redirectResponder->redirect($urlSelf);
                break;
            case 'rate':
                $this->rateService->ratePicture($imageId, StringUtil::inputInt('rate', 0, $_POST));
                $this->redirectResponder->redirect($urlSelf);
                break;
            case 'edit_comment':
                return $this->handleEditCommentAction($imageId, $urlSelf);
            case 'delete_comment':
                $this->csrfService->check();
                $this->inputValidator->check('comment_to_delete', $_GET, false, ValidationPattern::ID);
                $authorId = $this->commentService->getCommentAuthorId(StringUtil::inputInt('comment_to_delete', null, $_GET) ?? 0);
                if ($this->permissionService->canManageComment(CommentManagementAction::Delete, $authorId)) {
                    $this->commentService->deleteUserComment(StringUtil::inputInt('comment_to_delete', null, $_GET) ?? 0);
                }
                $this->redirectResponder->redirect($urlSelf);
                break;
            case 'validate_comment':
                $this->csrfService->check();
                $this->inputValidator->check('comment_to_validate', $_GET, false, ValidationPattern::ID);
                $authorId = $this->commentService->getCommentAuthorId(StringUtil::inputInt('comment_to_validate', null, $_GET) ?? 0);
                if ($this->permissionService->canManageComment(CommentManagementAction::Validate, $authorId)) {
                    $this->commentService->validateUserComment(StringUtil::inputInt('comment_to_validate', null, $_GET) ?? 0);
                }
                $this->redirectResponder->redirect($urlSelf);
                break;
        }
        return null;
    }

    private function handleEditCommentAction(int $imageId, string $urlSelf): ?int
    {
        $this->inputValidator->check('comment_to_edit', $_GET, false, ValidationPattern::ID);
        $commentToEdit = StringUtil::inputInt('comment_to_edit', null, $_GET);
        $authorId      = $this->commentService->getCommentAuthorId($commentToEdit ?? 0);
        if (!$this->permissionService->canManageComment(CommentManagementAction::Edit, $authorId)) {
            return null;
        }
        $postContent = StringUtil::inputString('content', null, $_POST);
        if ($postContent !== null && $postContent !== '') {
            $this->csrfService->check();
            $commentAction = $this->commentService->updateUserComment(
                ['comment_id' => $commentToEdit, 'image_id' => $imageId, 'content' => $postContent, 'website_url' => StringUtil::inputString('website_url', null, $_POST)],
                StringUtil::inputString('key', null, $_POST) ?? ''
            );
            $performRedirect = match ($commentAction) {
                CommentModerationAction::Moderate => $this->addModerationInfo(),
                CommentModerationAction::Validate => $this->addValidateInfo(),
                CommentModerationAction::Reject   => $this->addRejectError(),
            };
            if ($performRedirect) {
                $this->redirectResponder->redirect($urlSelf);
            }
            unset($_POST['content']);
        }
        return $commentToEdit;
    }

    private function addModerationInfo(): bool
    {
        PageState::current()->addInfo(Lang::t('An administrator must authorize your comment before it is visible.'));
        PageState::current()->addInfo(Lang::t('Your comment has been registered'));
        return true;
    }

    private function addValidateInfo(): bool
    {
        PageState::current()->addInfo(Lang::t('Your comment has been registered'));
        return true;
    }

    private function addRejectError(): bool
    {
        PageState::current()->addError(Lang::t('Your comment has NOT been registered because it did not pass the validation rules'));
        return false;
    }

    /**
     * Resolves an image that is not present in the sorted item set.
     * Looks the image up by ID or file pattern, checks level/visibility/category
     * access, and either inserts it into the working set, redirects, or returns a
     * 404 Response. Returns null to continue normal rendering.
     *
     * @param list<int>            &$items
     * @param array<int, int>      &$rankOf
     * @param array<string, mixed> $user
     */
    private function resolveImageNotInSet(
        int &$imageId,
        array &$items,
        array &$rankOf,
        int $catId,
        SectionContext $ctx,
        array $user,
    ): ?ResponseInterface {
        if ($imageId > 0) {
            $image = $this->imageRepository->findById($imageId);
        } else {
            $imageFileStr = $ctx->imageFile;
            $replaced     = str_replace(['_', '%'], ['/_', '/%'], $imageFileStr);
            $image        = $this->imageRepository->findByFilePattern($replaced . '.%');
        }
        if ($image === null) {
            $this->htmlService->pageNotFound('The requested image does not exist', $this->urlService->duplicateIndexUrl());
            return ResponseFactory::create(404);
        }
        $resolvedImageFile = $image->file->value;
        $imageId           = $image->id->value;
        if (is_numeric($user['level'] ?? null) && $image->level > $user['level']) {
            $this->htmlService->accessDenied();
        }

        if (!isset($rankOf[$imageId])) {
            $visibleImages = FilterContextRegistry::current()->visibleImages;
            if ($visibleImages !== [] && !in_array($imageId, $visibleImages, true)) {
                $this->htmlService->pageNotFound('The requested image is filtered', $this->urlService->duplicateIndexUrl());
                return ResponseFactory::create(404);
            }
            if ($ctx->section === Section::Categories && $ctx->category === null) {
                $this->htmlService->accessDenied();
            } else {
                $perm = $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'category_id'], ' AND');
                if (!$this->categoryRepository->isImageInVisibleCategory($imageId, $perm->where, $perm->params, $perm->types)) {
                    $this->htmlService->accessDenied();
                } elseif ($ctx->section === Section::BestRated) {
                    $rankOf[$imageId] = count($items);
                    $items[]          = $imageId;
                } else {
                    $url = $this->urlService->makePictureUrl(['image_id' => $imageId, 'image_file' => $resolvedImageFile, 'section' => 'categories', 'flat' => true]);
                    $this->htmlService->setStatusHeader($ctx->section === Section::RecentPics ? 301 : 302);
                    $this->redirectResponder->redirectHttp($url);
                }
            }
        }
        return null;
    }
}
