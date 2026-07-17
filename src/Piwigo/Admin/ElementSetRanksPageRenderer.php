<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Template\Template;

/**
 * Ported from admin/element_set_ranks.php (the "sort_order" tab of the
 * "album" page slug, dispatched by AlbumSubController) -- changes the rank
 * of images inside a category.
 *
 * P23 batch 6f fix: the POST handler had no check_pwg_token(), unlike its
 * two sibling tabs (CatPermPageRenderer, AlbumNotificationPageRenderer),
 * both of which already protect their own POST handlers -- a real CSRF gap
 * closed here the same way, matching every prior sub-batch's precedent.
 * element_set_ranks.tpl itself never had a hidden pwg_token field either
 * (confirmed via a direct grep -- unlike cat_perm.tpl/album_notification.tpl,
 * which both already render one): this render() method now assigns
 * PWG_TOKEN and the template gained the matching hidden input, the same
 * shape as those two sibling templates, so the real "Save order" button
 * keeps working once the server-side check is enforced.
 *
 * admin.php itself already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch (admin.php:65),
 * so the original element_set_ranks.php's own (redundant) check_status()
 * call is dropped here -- same precedent as PhotosAddSubController. The
 * missing/non-numeric cat_id guard is NOT dropped (unlike check_status()):
 * it reads $_GET['cat_id'] directly rather than through
 * AlbumSubController's own already-validated $category array, so this is
 * a real, still-load-bearing check, kept unchanged.
 */
final class ElementSetRanksPageRenderer
{
    public function render(): void
    {
        /**
         * @var array<string, mixed> $page
         * @var Template $template
         */
        global $page, $template;

        $htmlRenderer = new HtmlService();

        $sort_fields = [
            '' => '',
            'file ASC' => l10n('File name, A &rarr; Z'),
            'file DESC' => l10n('File name, Z &rarr; A'),
            'name ASC' => l10n('Photo title, A &rarr; Z'),
            'name DESC' => l10n('Photo title, Z &rarr; A'),
            'date_creation DESC' => l10n('Date created, new &rarr; old'),
            'date_creation ASC' => l10n('Date created, old &rarr; new'),
            'date_available DESC' => l10n('Date posted, new &rarr; old'),
            'date_available ASC' => l10n('Date posted, old &rarr; new'),
            'rating_score DESC' => l10n('Rating score, high &rarr; low'),
            'rating_score ASC' => l10n('Rating score, low &rarr; high'),
            'hit DESC' => l10n('Visits, high &rarr; low'),
            'hit ASC' => l10n('Visits, low &rarr; high'),
            'id ASC' => l10n('Numeric identifier, 1 &rarr; 9'),
            'id DESC' => l10n('Numeric identifier, 9 &rarr; 1'),
            'rank ASC' => l10n('Manual sort order'),
        ];

        if (! isset($_GET['cat_id']) or ! is_numeric($_GET['cat_id'])) {
            trigger_error('missing cat_id param', E_USER_ERROR);
        }

        $page['category_id'] = $_GET['cat_id'];

        // +-------------------------------------------------------------------+
        // |                       global mode form submission                 |
        // +-------------------------------------------------------------------+

        $image_order_choices = ['default', 'rank', 'user_define'];
        $image_order_choice = 'default';

        if (isset($_POST['submit'])) {
            new \Piwigo\Csrf\CsrfService()->checkOrFail(new HtmlService());

            if (isset($_POST['rank_of_image']) && is_array($_POST['rank_of_image'])) {
                $rank_of_image = array_filter($_POST['rank_of_image'], is_numeric(...));
                asort($rank_of_image, SORT_NUMERIC);

                $imageConn = DbConnection::build();
                new ImageService(new ImageRepository($imageConn), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository($imageConn)))
                    ->saveImagesOrder(
                        (int) $page['category_id'],
                        array_map(intval(...), array_keys($rank_of_image))
                    );
            }

            if (! empty($_POST['image_order_choice'])
                && in_array($_POST['image_order_choice'], $image_order_choices)) {
                $image_order_choice = $_POST['image_order_choice'];
            }

            $message = l10n('Album updated successfully');

            $image_order = null;
            if ($image_order_choice === 'user_define') {
                $post_image_order = isset($_POST['image_order']) && is_array($_POST['image_order']) ? $_POST['image_order'] : [];
                for ($i = 0; $i < 3; $i++) {
                    $order_value = $post_image_order[$i] ?? null;
                    if (is_string($order_value) && $order_value !== '' && in_array($order_value, array_keys($sort_fields), true)) {
                        if (! empty($image_order)) {
                            $image_order .= ',';
                        }
                        $image_order .= $order_value;
                    }
                }
            } elseif ($image_order_choice === 'rank') {
                $image_order = '`rank` ASC';

                $message = l10n('Images manual order was saved');
            }
            new CategoryAdminService()
                ->saveImageOrder((int) $page['category_id'], $image_order, isset($_POST['image_order_subcats']));

            $template->assign(
                [
                    'save_success' => $message,
                ]
            );
        }

        // +-------------------------------------------------------------------+
        // |                             template init                         |
        // +-------------------------------------------------------------------+
        $template->set_filenames(
            [
                'element_set_ranks' => 'element_set_ranks.tpl',
            ]
        );

        $base_url = get_root_url() . 'admin.php';

        $query = '
SELECT *
  FROM ' . Tables::categories() . '
  WHERE id = ' . $page['category_id'] . '
;';
        $category = \Piwigo\Db\MysqliDb::fetchAssoc(\Piwigo\Db\MysqliDb::query($query));
        if (! is_array($category) || ! is_string($category['uppercats'] ?? null)) {
            $htmlRenderer->pageNotFound('Requested album does not exist');
        }

        if ($category['image_order'] === 'rank ASC' or $category['image_order'] === '`rank` ASC') {
            $image_order_choice = 'rank';
        } elseif ($category['image_order'] !== '') {
            $image_order_choice = 'user_define';
        }

        // Navigation path
        $navigation = $htmlRenderer->getCatDisplayNameCache(
            $category['uppercats'],
            get_root_url() . 'admin.php?page=album-'
        );

        $template->assign(
            [
                'CATEGORIES_NAV' => preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $navigation)),
                'F_ACTION' => $base_url . get_query_string_diff([]),
                'PWG_TOKEN' => (new \Piwigo\Csrf\CsrfService())->getToken(),
            ]
        );

        // +-------------------------------------------------------------------+
        // |                              thumbnails                           |
        // +-------------------------------------------------------------------+

        $query = '
SELECT
    id,
    file,
    path,
    representative_ext,
    width, height, rotation,
    name,
    `rank`
  FROM ' . Tables::images() . '
    JOIN ' . Tables::imageCategory() . ' ON image_id = id
  WHERE category_id = ' . $page['category_id'] . '
  ORDER BY `rank`
;';
        $result = \Piwigo\Db\MysqliDb::query($query);
        if (\Piwigo\Db\MysqliDb::numRows($result) > 0) {
            // template thumbnail initialization
            $current_rank = 1;
            $derivativeParams = ImageStdParams::get_by_type(ImageStdParams::SQUARE);
            while ((bool) ($row = \Piwigo\Db\MysqliDb::fetchAssoc($result))) {
                $derivative = new DerivativeImage($derivativeParams, new SrcImage($row));

                if (! empty($row['name'])) {
                    $thumbnail_name = $row['name'];
                } else {
                    $file_wo_ext = is_string($row['file']) ? \Piwigo\Core\StringHelper::getFilenameWoExtension($row['file']) : '';
                    $thumbnail_name = str_replace('_', ' ', $file_wo_ext);
                }
                $current_rank++;
                $template->append(
                    'thumbnails',
                    [
                        'ID' => $row['id'],
                        'NAME' => $thumbnail_name,
                        'TN_SRC' => $derivative->get_url(),
                        'RANK' => $current_rank * 10,
                        'SIZE' => $derivative->get_size(),
                    ]
                );
            }
        }
        // image order management
        $template->assign('image_order_options', $sort_fields);

        $image_order = explode(',', $category['image_order'] ?? '');

        for ($i = 0; $i < 3; $i++) { // 3 fields
            if (isset($image_order[$i])) {
                $template->append('image_order', $image_order[$i]);
            } else {
                $template->append('image_order', '');
            }
        }

        $template->assign('image_order_choice', $image_order_choice);

        // +-------------------------------------------------------------------+
        // |                          sending html code                        |
        // +-------------------------------------------------------------------+

        $template->assign_var_from_handle('ADMIN_CONTENT', 'element_set_ranks');
    }
}
