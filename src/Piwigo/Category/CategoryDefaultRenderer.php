<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Piwigo\Comment\CommentRepository;
use Piwigo\Config\Config;
use Piwigo\Core\DebugCollector;
use Piwigo\Event\Location\LocBeginIndexThumbnails;
use Piwigo\Event\Location\LocEndIndexThumbnails;
use Piwigo\Event\Location\LocIndexThumbnailsSelection;
use Piwigo\Event\Picture\GetIndexDerivativeParams;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\View\PictureViewModel;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Session\Session;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class CategoryDefaultRenderer
{
    public function __construct(
        private CategoryService $categoryService,
        private CommentRepository $commentRepository,
        private HtmlService $htmlService,
        private ImageRepository $imageRepository,
        private Session $session,
        private UrlService $urlService,
        private DebugCollector $debugCollector,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function render(): void
    {
        $template = TemplateRegistry::current();
        $ctx = SectionContextRegistry::current();
        $user = CurrentUser::get()->rawAttributes;

        $pictures = [];

        $pageItems = $ctx->items;
        $pageStart = $ctx->start;
        $pageNbImagePage = $ctx->nbImagePage;
        $selectionEvent = new LocIndexThumbnailsSelection(
            array_slice($pageItems, $pageStart, $pageNbImagePage)
        );
        $this->dispatcher->dispatch($selectionEvent);
        /** @var array<int, int|string> $selection */
        $selection = $selectionEvent->selection;

        if (count($selection) > 0) {
            $rank_of = array_flip($selection);

            foreach ($this->imageRepository
                ->findByIds(array_map(intval(...), $selection)) as $img) {
                $row = PictureViewModel::fromImage($img)->toArray();
                $row['rank'] = $rank_of[$img->id->value] ?? 0;
                $pictures[] = $row;
            }

            usort($pictures, $this->categoryService->rankCompare(...));
            unset($rank_of);
        }

        if (count($pictures) > 0) {
            $row = reset($pictures);
            $template->assign('CAT_SLIDESHOW_URL', $this->urlService->addUrlParams(
                $this->urlService->duplicatePictureUrl(
                    ['image_id' => $row['id'], 'image_file' => $row['file']],
                    ['start']
                ),
                ['slideshow' => ($_GET['slideshow'] ?? '')]
            ));

            if (Config::activateComments() and $user['show_nb_comments']) {
                $imageIdsInt    = array_values(array_map(intval(...), $selection));
                $nb_comments_of = $this->commentRepository->countValidatedByImageIdsKeyedByImageId($imageIdsInt);
            }
        }


        $this->dispatcher->dispatch(new LocBeginIndexThumbnails($pictures));
        $tpl_thumbnails_var = [];

        foreach ($pictures as $row) {
            $url = $this->urlService->duplicatePictureUrl(
                ['image_id' => $row['id'], 'image_file' => $row['file']],
                ['start']
            );

            if (isset($nb_comments_of)) {
                $rowId = is_numeric($row['id']) ? (int) $row['id'] : 0;
                $nbVal = $nb_comments_of[$rowId] ?? 0;
                $row['NB_COMMENTS'] = $row['nb_comments'] = $nbVal;
            }

            $name = $this->htmlService->renderElementName($row);
            $desc = $this->htmlService->renderElementDescription($row, 'main_page_element_description');

            // `src_image`, `path_ext`, `file_ext` already populated upstream
            // by PictureViewModel::fromImage() — no need to rebuild them here.
            $tpl_var = array_merge($row, [
                'TN_ALT' => htmlspecialchars(strip_tags($name)),
                'TN_TITLE' => $this->htmlService->getThumbnailTitle($row, $name, $desc),
                'URL' => $url,
                'DESCRIPTION' => $desc,
            ]);

            if (Config::indexNewIcon()) {
                $rowDateAvailable = $row['date_available'] ?? null;
                $tpl_var['icon_ts'] = $this->htmlService->getIcon(is_string($rowDateAvailable) ? $rowDateAvailable : null);
            }

            if ($user['show_nb_hits']) {
                $tpl_var['NB_HITS'] = $row['hit'];
            }

            switch ($ctx->section) {
                case 'best_rated':
                    $ratingScoreRaw = $row['rating_score'] ?? null;
                    $name = '(' . (is_string($ratingScoreRaw) ? $ratingScoreRaw : '') . ') ' . $name;
                    break;
                case 'most_visited':
                    if (!$user['show_nb_hits']) {
                        $hitRaw = $row['hit'] ?? null;
                        $name = '(' . (is_string($hitRaw) ? $hitRaw : '') . ') ' . $name;
                    }
                    break;
            }
            $tpl_var['NAME'] = $name;
            $tpl_thumbnails_var[] = $tpl_var;
        }

        $derivType = $this->session->indexDeriv !== null ? $this->session->indexDeriv->value : DerivativeSize::Thumb->value;
        $derivEvent = new GetIndexDerivativeParams(ImageStdParams::getByType($derivType));
        $this->dispatcher->dispatch($derivEvent);
        $template->assign([
            'derivative_params' => $derivEvent->value,
            'maxRequests' => Config::maxRequests(),
            'SHOW_THUMBNAIL_CAPTION' => Config::showThumbnailCaption(),
        ]);
        $thumbsEvent = new LocEndIndexThumbnails($tpl_thumbnails_var, $pictures);
        $this->dispatcher->dispatch($thumbsEvent);
        $tpl_thumbnails_var = $thumbsEvent->tplThumbnailsVar;
        $template->assign('thumbnails', $tpl_thumbnails_var);

        $template->assignVarFromTemplate('THUMBNAILS', 'thumbnails.latte');
        unset($pictures, $selection, $tpl_thumbnails_var);
        $template->clearAssign('thumbnails');
        $this->debugCollector->collect('end CategoryDefaultRenderer');
    }
}
