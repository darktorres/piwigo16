<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Piwigo\Config\Config;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;

final class CategoryDefaultRenderer
{
    public function render(): void
    {
        $template = TemplateRegistry::current();
        $page = &$GLOBALS['page'];
        if (!is_array($page)) {
            $page = [];
        }
        $user = CurrentUser::get()->rawAttributes;

        $pictures = [];

        $pageItems = is_array($page['items'] ?? null) ? $page['items'] : [];
        $pageStart = is_numeric($page['start'] ?? null) ? (int) $page['start'] : 0;
        $pageNbImagePage = is_numeric($page['nb_image_page'] ?? null) ? (int) $page['nb_image_page'] : null;
        $selection = array_values(array_filter(
            EventDispatcher::dispatch(
                'loc_index_thumbnails_selection',
                array_slice($pageItems, $pageStart, $pageNbImagePage)
            ),
            fn (mixed $v): bool => is_int($v) || is_string($v)
        ));

        if (count($selection) > 0) {
            $rank_of = array_flip($selection);

            foreach (ServiceLocator::get(ImageRepository::class)
                ->findByIds(array_map(intval(...), $selection)) as $row) {
                $row['rank'] = $rank_of[ is_numeric($row['id']) ? (int)$row['id'] : 0 ];
                $pictures[] = $row;
            }

            usort($pictures, ServiceLocator::get(CategoryService::class)->rankCompare(...));
            unset($rank_of);
        }

        if (count($pictures) > 0) {
            $row = reset($pictures);
            $page['cat_slideshow_url'] = UrlService::get()->addUrlParams(
                UrlService::get()->duplicatePictureUrl(
                    ['image_id' => $row['id'], 'image_file' => $row['file']],
                    ['start']
                ),
                ['slideshow' => ($_GET['slideshow'] ?? '')]
            );

            if (Config::activateComments() and $user['show_nb_comments']) {
                $query = '
SELECT image_id, COUNT(*) AS nb_comments
  FROM ' . Tables::comments() . '
  WHERE validated = \'true\'
    AND image_id IN (' . implode(',', array_map(strval(...), $selection)) . ')
  GROUP BY image_id
;';
                $nb_comments_of = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), 'nb_comments', 'image_id');
            }
        }

        $template->setFilenames(['index_thumbnails' => 'thumbnails.latte']);

        EventDispatcher::notify('loc_begin_index_thumbnails', $pictures);
        $tpl_thumbnails_var = [];

        foreach ($pictures as $row) {
            $url = UrlService::get()->duplicatePictureUrl(
                ['image_id' => $row['id'], 'image_file' => $row['file']],
                ['start']
            );

            if (isset($nb_comments_of)) {
                $rowId = is_numeric($row['id']) ? (int) $row['id'] : 0;
                $nbVal = $nb_comments_of[$rowId] ?? 0;
                $row['NB_COMMENTS'] = $row['nb_comments'] = is_numeric($nbVal) ? (int) $nbVal : 0;
            }

            $name = ServiceLocator::get(HtmlService::class)->renderElementName($row);
            $desc = ServiceLocator::get(HtmlService::class)->renderElementDescription($row, 'main_page_element_description');

            $rowPath = $row['path'] ?? null;
            $rowFile = $row['file'] ?? null;
            $tpl_var = array_merge($row, [
                'TN_ALT' => htmlspecialchars(strip_tags($name)),
                'TN_TITLE' => ServiceLocator::get(HtmlService::class)->getThumbnailTitle($row, $name, $desc),
                'URL' => $url,
                'DESCRIPTION' => $desc,
                'src_image' => new SrcImage($row),
                'path_ext' => strtolower(ServiceLocator::get(StringUtil::class)->getExtension(is_string($rowPath) ? $rowPath : '')),
                'file_ext' => strtolower(ServiceLocator::get(StringUtil::class)->getExtension(is_string($rowFile) ? $rowFile : '')),
            ]);

            if (Config::indexNewIcon()) {
                $rowDateAvailable = $row['date_available'] ?? null;
                $tpl_var['icon_ts'] = ServiceLocator::get(Util::class)->getIcon(is_string($rowDateAvailable) ? $rowDateAvailable : null);
            }

            if ($user['show_nb_hits']) {
                $tpl_var['NB_HITS'] = $row['hit'];
            }

            switch ($page['section']) {
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

        $derivRaw = ServiceLocator::get(SessionService::class)->getSessionVar('index_deriv', DerivativeSize::Thumb->value);
        $derivType = is_string($derivRaw) ? $derivRaw : DerivativeSize::Thumb->value;
        $template->assign([
            'derivative_params' => EventDispatcher::dispatch('get_index_derivative_params', ImageStdParams::getByType($derivType)),
            'maxRequests' => Config::maxRequests(),
            'SHOW_THUMBNAIL_CAPTION' => Config::showThumbnailCaption(),
        ]);
        $tpl_thumbnails_var = EventDispatcher::dispatch('loc_end_index_thumbnails', $tpl_thumbnails_var, $pictures);
        $template->assign('thumbnails', $tpl_thumbnails_var);

        $template->assignVarFromHandle('THUMBNAILS', 'index_thumbnails');
        unset($pictures, $selection, $tpl_thumbnails_var);
        $template->clearAssign('thumbnails');
        ServiceLocator::get(Util::class)->pwgDebug('end CategoryDefaultRenderer');
    }
}
