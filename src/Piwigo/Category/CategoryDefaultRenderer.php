<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Db\Tables;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Session\SessionService;
use Piwigo\Template\Template;

/**
 * Renders the main/index page's thumbnail grid for the current page's image
 * selection. Ported from include/category_cats.inc.php's sibling
 * include/category_default.inc.php -- a clean, mechanical port: the file
 * already self-declared `global` for every real global it touched, no
 * user_cache_categories/user_cache reads at all (that's category_cats.inc.php's
 * concern, see CategoryCatsRenderer), and no bare-scope-sharing risk.
 */
final class CategoryDefaultRenderer
{
    public function __construct(
        private readonly HtmlRenderingInterface $htmlRenderer,
    ) {}

    public function render(): void
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $page
         * @var Template $template
         * @var array<string, mixed> $user
         */
        global $conf, $page, $template, $user;

        $pictures = [];

        $pageItems = $page['items'];
        if (! is_array($pageItems)) {
            $pageItems = [];
        }
        $pageStart = is_numeric($page['start'] ?? null) ? (int) $page['start'] : 0;
        $pageNbImagePage = is_numeric($page['nb_image_page'] ?? null) ? (int) $page['nb_image_page'] : 0;

        $selection = array_slice($pageItems, $pageStart, $pageNbImagePage);

        $selection = trigger_change('loc_index_thumbnails_selection', $selection);
        if (! is_array($selection)) {
            // A misbehaving plugin handler could return something else;
            // count() on a non-array/non-Countable is a fatal TypeError in
            // PHP 8, so this also guards against a real runtime crash, not
            // just the static type.
            $selection = [];
        }
        /** @var list<int|string> $selection */
        $selection = array_values(array_filter(
            $selection,
            static fn ($item): bool => is_int($item) || is_string($item)
        ));

        if (count($selection) > 0) {
            $rankOf = array_flip($selection);

            $query = '
SELECT *
  FROM ' . Tables::images() . '
  WHERE id IN (' . implode(',', $selection) . ')
;';
            $result = \Piwigo\Db\MysqliDb::query($query);
            while ((bool) ($row = \Piwigo\Db\MysqliDb::fetchAssoc($result))) {
                $imageId = $row['id'] ?? '';
                $row['rank'] = $rankOf[$imageId] ?? 0;
                $pictures[] = $row;
            }

            usort($pictures, CategoryService::compareByRank(...));
            unset($rankOf);
        }

        // Only conditionally populated below (activate_comments +
        // show_nb_comments both truthy AND at least one picture) --
        // declared up front (rather than relying on isset() to gate a
        // maybe-undefined variable) so PHPStan can prove its real type --
        // null, or \Piwigo\Db\MysqliDb::query2Array()'s actual inferred return type -- at every
        // later read.
        $nbCommentsOf = null;

        if (count($pictures) > 0) {
            // define category slideshow url
            $row = reset($pictures);
            $page['cat_slideshow_url'] =
              add_url_params(
                  duplicate_picture_url(
                      [
                          'image_id' => $row['id'],
                          'image_file' => $row['file'],
                      ],
                      ['start']
                  ),
                  [
                      'slideshow' => ($_GET['slideshow']
                                                         ?? ''),
                  ]
              );

            if ((bool) $conf['activate_comments'] and (bool) $user['show_nb_comments']) {
                $query = '
SELECT image_id, COUNT(*) AS nb_comments
  FROM ' . Tables::comments() . '
  WHERE validated = \'true\'
    AND image_id IN (' . implode(',', $selection) . ')
  GROUP BY image_id
;';
                $nbCommentsOf = \Piwigo\Db\MysqliDb::query2Array($query, 'image_id', 'nb_comments');
            }
        }

        // template thumbnail initialization
        $template->set_filenames([
            'index_thumbnails' => 'thumbnails.tpl',
        ]);

        trigger_notify('loc_begin_index_thumbnails', $pictures);
        $tplThumbnailsVar = [];

        foreach ($pictures as $row) {
            // 'id' is the images table's NOT NULL primary key -- the ''
            // fallback only satisfies the array-key type, it never changes
            // real behavior.
            $imageId = $row['id'] ?? '';

            // link on picture.php page
            $url = duplicate_picture_url(
                [
                    'image_id' => $imageId,
                    'image_file' => $row['file'],
                ],
                ['start']
            );

            if ($nbCommentsOf !== null) {
                $row['NB_COMMENTS'] = $row['nb_comments'] = (int) @$nbCommentsOf[$imageId];
            }

            $name = $this->htmlRenderer->renderElementName($row);
            $desc = $this->htmlRenderer->renderElementDescription($row, 'main_page_element_description');

            // 'path'/'file' are non-nullable text columns in practice, but
            // $row is a dynamically-fetched DB row (SELECT *), so PHPStan
            // only knows them as possibly-non-string -- narrow for real
            // before get_extension().
            $rowPath = is_string($row['path']) ? $row['path'] : null;
            $rowFile = is_string($row['file']) ? $row['file'] : null;

            $tplVar = array_merge($row, [
                'TN_ALT' => htmlspecialchars(strip_tags($name)),
                'TN_TITLE' => $this->htmlRenderer->getThumbnailTitle($row, $name, $desc),
                'URL' => $url,
                'DESCRIPTION' => $desc,
                'src_image' => new SrcImage($row),
                'path_ext' => strtolower(\Piwigo\Core\StringHelper::getExtension($rowPath)),
                'file_ext' => strtolower(\Piwigo\Core\StringHelper::getExtension($rowFile)),
            ]);

            if ((bool) $conf['index_new_icon']) {
                // '' falls through get_icon()'s own empty($date) guard
                // exactly like a non-string/null column value would, so
                // behavior is unchanged.
                $dateAvailable = is_string($row['date_available']) ? $row['date_available'] : '';
                $tplVar['icon_ts'] = \Piwigo\Core\RecentIconResolver::getIcon($dateAvailable);
            }

            if ((bool) $user['show_nb_hits']) {
                $tplVar['NB_HITS'] = $row['hit'];
            }

            switch ($page['section']) {
                case 'best_rated':
                    $ratingScore = $row['rating_score'];
                    $name = '(' . (is_string($ratingScore) || is_int($ratingScore) ? $ratingScore : '') . ') ' . $name;
                    break;

                case 'most_visited':
                    if (! (bool) $user['show_nb_hits']) {
                        $hit = $row['hit'];
                        $name = '(' . (is_string($hit) || is_int($hit) ? $hit : '') . ') ' . $name;
                    }
                    break;
            }
            $tplVar['NAME'] = $name;
            $tplThumbnailsVar[] = $tplVar;
        }

        $indexDeriv = SessionService::get()->getSessionVar('index_deriv', ImageStdParams::THUMB);
        $indexDeriv = is_string($indexDeriv) ? $indexDeriv : ImageStdParams::THUMB;

        $template->assign([
            'derivative_params' => trigger_change('get_index_derivative_params', ImageStdParams::get_by_type($indexDeriv)),
            'maxRequests' => $conf['max_requests'],
            'SHOW_THUMBNAIL_CAPTION' => $conf['show_thumbnail_caption'],
        ]);
        $tplThumbnailsVar = trigger_change('loc_end_index_thumbnails', $tplThumbnailsVar, $pictures);
        $template->assign('thumbnails', $tplThumbnailsVar);

        $template->assign_var_from_handle('THUMBNAILS', 'index_thumbnails');
        unset($pictures, $selection, $tplThumbnailsVar);
        $template->clear_assign('thumbnails');
        \Piwigo\Core\TimingHelper::debug('end CategoryDefaultRenderer::render()');
    }
}
