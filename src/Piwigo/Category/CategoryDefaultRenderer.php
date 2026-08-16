<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Piwigo\Category\Event\IndexThumbnailsRendered;
use Piwigo\Category\Event\IndexThumbnailsRendering;
use Piwigo\Category\Event\IndexThumbnailsSelected;
use Piwigo\Category\Projection\CategoryDefaultThumbnailsPageContext;
use Piwigo\Category\Request\CategorySlideshowRequest;
use Piwigo\Common\Enum\Section;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CommentCounterInterface;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\RecentIconResolver;
use Piwigo\Core\StringHelper;
use Piwigo\Core\TemplateInterface;
use Piwigo\Core\TimingHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Image\Event\GetIndexDerivativeParams;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Users\CurrentUser;

/**
 * Renders the main/index page's thumbnail grid for the current page's image
 * selection. Ported from include/category_cats.inc.php's sibling
 * include/category_default.inc.php -- a clean, mechanical port: the file
 * already self-declared `global` for every real global it touched, no
 * user_cache_categories/user_cache reads at all (that's category_cats.inc.php's
 * concern, see CategoryCatsRenderer), and no bare-scope-sharing risk.
 */
final readonly class CategoryDefaultRenderer
{
    public function __construct(
        private HtmlRenderingInterface $htmlRenderer,
        private TemplateInterface $template,
        private ImageRepository $imageRepo,
        private CommentCounterInterface $commentCounter,
        private UrlServiceInterface $urlService,
        private SessionService $sessionService,
        private EventDispatcher $eventDispatcher,
        private ImageStdParams $imageStdParams,
        private CurrentUser $currentUser,
        private CurrentConfig $currentConfig,
        private Lang $lang,
        private ProcessCache $processCache,
        private PageState $pageState,
    ) {}

    /**
     * $items/$start/$nbImagePage/$section are plain values rather than the
     * SectionContext object itself: Category is L2aCoreDomain and Section
     * is L2bExtendedDomain, so a SectionContext parameter here would be a
     * `deptrac analyse` DependsOnDisallowedLayer violation (L2a may not
     * depend on L2b). The one real caller (GalleryController) already has
     * these values from SectionContextRegistry::current().
     *
     * The slideshow URL is this method's return value: it's produced here
     * for GalleryController's own later read, after SectionContext was
     * already built, so it's out of SectionContext's own scope (see that
     * class's docblock) -- but the two are always in the same call and
     * don't need a shared PageState field.
     *
     * @param list<int|string> $items
     */
    public function render(array $items, int $start, int $nbImagePage, Section $section): ?string
    {
        $template = $this->template;

        $pictures = [];
        $slideshowUrl = null;

        $selection = array_slice($items, $start, $nbImagePage);

        $selection = $this->eventDispatcher->dispatch(new IndexThumbnailsSelected($selection))
            ->selection;
        /** @var list<int|string> $selection */
        $selection = array_values(array_filter(
            $selection,
            static fn ($item): bool => is_int($item) || is_string($item)
        ));

        if (count($selection) > 0) {
            $rankOf = array_flip($selection);

            foreach ($this->imageRepo->findByIds($selection) as $imageId => $row) {
                $pictureRow = $row->toArray();
                $pictureRow['rank'] = $rankOf[$imageId] ?? 0;
                $pictures[] = $pictureRow;
            }

            usort($pictures, CategoryService::compareByRank(...));
            unset($rankOf);
        }

        // Only conditionally populated below (activate_comments +
        // show_nb_comments both truthy AND at least one picture) --
        // declared up front (rather than relying on isset() to gate a
        // maybe-undefined variable) so PHPStan can prove its real type --
        // null, or CommentRepository::countValidatedByImageIds()'s actual
        // inferred return type -- at every later read.
        $nbCommentsOf = null;

        if (count($pictures) > 0) {
            // define category slideshow url
            $row = reset($pictures);
            $slideshowUrl =
              $this->urlService->addUrlParams(
                  $this->urlService->duplicatePictureUrl(
                      [
                          'image_id' => $row['id'],
                          'image_file' => $row['file'],
                      ],
                      ['start']
                  ),
                  [
                      'slideshow' => CategorySlideshowRequest::fromGlobals()->slideshow,
                  ]
              );

            if ($this->currentConfig->activateComments and (bool) $this->currentUser->get()->rawAttributes['show_nb_comments']) {
                $nbCommentsOf = $this->commentCounter->countValidatedByImageIds($selection);
            }
        }

        $this->eventDispatcher->dispatch(new IndexThumbnailsRendering($pictures));
        $tplThumbnailsVar = [];

        foreach ($pictures as $row) {
            $imageId = $row['id'];
            $imageIdKey = (string) $imageId;

            // link on picture.php page
            $url = $this->urlService->duplicatePictureUrl(
                [
                    'image_id' => $imageId,
                    'image_file' => $row['file'],
                ],
                ['start']
            );

            if ($nbCommentsOf !== null) {
                $nbComments = array_key_exists($imageIdKey, $nbCommentsOf) ? $nbCommentsOf[$imageIdKey] : 0;
                $row['NB_COMMENTS'] = $row['nb_comments'] = $nbComments;
            }

            $name = $this->htmlRenderer->renderElementName($row);
            $desc = $this->htmlRenderer->renderElementDescription($row, 'main_page_element_description');

            $rowPath = $row['path'];
            $rowFile = $row['file'];

            $tplVar = array_merge($row, [
                'TN_ALT' => htmlspecialchars(strip_tags($name)),
                'TN_TITLE' => $this->htmlRenderer->getThumbnailTitle($row, $name, $desc),
                'URL' => $url,
                'DESCRIPTION' => $desc,
                'src_image' => new SrcImage($row),
                'path_ext' => strtolower(StringHelper::getExtension($rowPath)),
                'file_ext' => strtolower(StringHelper::getExtension($rowFile)),
            ]);

            if ($this->currentConfig->indexNewIcon) {
                // '' falls through get_icon()'s own empty($date) guard
                // exactly like a non-string/null column value would, so
                // behavior is unchanged.
                $dateAvailable = is_string($row['date_available']) ? $row['date_available'] : '';
                $recentPeriodRaw = $this->currentUser->get()
                    ->rawAttributes['recent_period'] ?? null;
                $recentPeriodForIcon = is_numeric($recentPeriodRaw) ? (int) $recentPeriodRaw : 0;
                $tplVar['icon_ts'] = RecentIconResolver::getIcon($dateAvailable, $recentPeriodForIcon, $this->processCache, $this->lang);
            }

            if ((bool) $this->currentUser->get()->rawAttributes['show_nb_hits']) {
                $tplVar['NB_HITS'] = $row['hit'];
            }

            switch ($section) {
                case Section::BestRated:
                    // `rating_score` is a native DBAL float|null, never a
                    // string/int -- the original `is_string(...) ||
                    // is_int(...)` guard (written for mysqli's
                    // always-string legacy fetch mode) was always false
                    // here, so the best-rated special page's thumbnail
                    // name label always rendered "() Name" instead of
                    // "(4.5) Name", silently, since $row was untyped
                    // `mixed` before this domain's Projection retype made
                    // the always-false condition visible to PHPStan.
                    $ratingScore = $row['rating_score'];
                    $name = '(' . ($ratingScore !== null ? (string) $ratingScore : '') . ') ' . $name;
                    break;

                case Section::MostVisited:
                    if (! (bool) $this->currentUser->get()->rawAttributes['show_nb_hits']) {
                        $name = '(' . $row['hit'] . ') ' . $name;
                    }
                    break;
            }
            $tplVar['NAME'] = $name;
            $tplThumbnailsVar[] = $tplVar;
        }

        $indexDeriv = $this->sessionService->getIndexDeriv() ?? ImageStdParams::THUMB;

        $tplThumbnailsVar = $this->eventDispatcher->dispatch(new IndexThumbnailsRendered($tplThumbnailsVar, $pictures))
            ->tplThumbnailsVar;
        $template->assignContext(new CategoryDefaultThumbnailsPageContext(
            derivativeParams: $this->eventDispatcher->dispatch(new GetIndexDerivativeParams($this->imageStdParams->getByType($indexDeriv)))
                ->params,
            maxRequests: $this->currentConfig->maxRequests,
            showThumbnailCaption: $this->currentConfig->showThumbnailCaption,
            thumbnails: $tplThumbnailsVar,
        ));

        $template->assignVarFromTemplate('THUMBNAILS', 'thumbnails.latte');
        unset($pictures, $selection, $tplThumbnailsVar);
        $template->clearAssign('thumbnails');
        TimingHelper::debug('end CategoryDefaultRenderer::render()', $this->pageState);

        return $slideshowUrl;
    }
}
