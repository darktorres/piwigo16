<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\ErrorCollector;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Image\DerivativeImage;
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
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly ErrorCollector $errorCollector,
        private readonly \Piwigo\Image\ImageStdParams $imageStdParams,
    ) {}

    public function render(): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        $htmlRenderer = \Piwigo\Bootstrap\PresentationAccessor::htmlService();
        $conn = DbConnection::build();

        $sort_fields = [
            '' => '',
            'file ASC' => Lang::t('File name, A &rarr; Z'),
            'file DESC' => Lang::t('File name, Z &rarr; A'),
            'name ASC' => Lang::t('Photo title, A &rarr; Z'),
            'name DESC' => Lang::t('Photo title, Z &rarr; A'),
            'date_creation DESC' => Lang::t('Date created, new &rarr; old'),
            'date_creation ASC' => Lang::t('Date created, old &rarr; new'),
            'date_available DESC' => Lang::t('Date posted, new &rarr; old'),
            'date_available ASC' => Lang::t('Date posted, old &rarr; new'),
            'rating_score DESC' => Lang::t('Rating score, high &rarr; low'),
            'rating_score ASC' => Lang::t('Rating score, low &rarr; high'),
            'hit DESC' => Lang::t('Visits, high &rarr; low'),
            'hit ASC' => Lang::t('Visits, low &rarr; high'),
            'id ASC' => Lang::t('Numeric identifier, 1 &rarr; 9'),
            'id DESC' => Lang::t('Numeric identifier, 9 &rarr; 1'),
            'rank ASC' => Lang::t('Manual sort order'),
        ];

        $elementSetRanksRequest = Request\ElementSetRanksRequest::fromGlobals();

        if (! $elementSetRanksRequest->isCatIdValid) {
            // Used to be trigger_error(..., E_USER_ERROR) -- deprecated as
            // of PHP 8.4, see HtmlService::fatalError()'s own docblock for
            // the real replacement/reasoning. Execution always fell
            // through to the cast below regardless (see
            // ElementSetRanksRequest's own docblock), so nothing else
            // changes here.
            $this->errorCollector->recordFatal('missing cat_id param');
        }

        $category_id = $elementSetRanksRequest->catId;

        // +-------------------------------------------------------------------+
        // |                       global mode form submission                 |
        // +-------------------------------------------------------------------+

        $image_order_choice = $elementSetRanksRequest->imageOrderChoice;

        if ($elementSetRanksRequest->isSubmitted) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(\Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->redirectService);

            if ($elementSetRanksRequest->rankOfImage !== []) {
                \Piwigo\Bootstrap\CoreDomainAccessor::imageService()
                    ->saveImagesOrder(
                        $category_id,
                        array_map(intval(...), array_keys($elementSetRanksRequest->rankOfImage))
                    );
            }

            $message = Lang::t('Album updated successfully');

            $image_order = null;
            if ($image_order_choice === 'user_define') {
                $post_image_order = $elementSetRanksRequest->imageOrderFields;
                for ($i = 0; $i < 3; $i++) {
                    $order_value = $post_image_order[$i] ?? null;
                    if (is_string($order_value) && $order_value !== '' && in_array($order_value, array_keys($sort_fields), true)) {
                        if (! in_array($image_order, [null, ''], true)) {
                            $image_order .= ',';
                        }
                        $image_order .= $order_value;
                    }
                }
            } elseif ($image_order_choice === 'rank') {
                $image_order = '`rank` ASC';

                $message = Lang::t('Images manual order was saved');
            }
            \Piwigo\Bootstrap\AdminAccessor::categoryAdminService()->saveImageOrder($category_id, $image_order, $elementSetRanksRequest->isImageOrderSubcats, $this->redirectService);

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

        $base_url = $this->urlService->getRootUrl() . 'admin.php';

        $category = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Category\CategoryEntity::class)
            ->findById($category_id);
        if ($category === null) {
            $htmlRenderer->pageNotFound($this->redirectService, 'Requested album does not exist');
        }

        if ($category->imageOrder === 'rank ASC' or $category->imageOrder === '`rank` ASC') {
            $image_order_choice = 'rank';
        } elseif ($category->imageOrder !== '') {
            $image_order_choice = 'user_define';
        }

        // Navigation path
        $navigation = $htmlRenderer->getCatDisplayNameCache(
            $category->uppercats,
            $this->urlService->getRootUrl() . 'admin.php?page=album-'
        );

        $template->assign(
            [
                'CATEGORIES_NAV' => preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $navigation)),
                'F_ACTION' => $base_url . $this->urlService->getQueryStringDiff([]),
                'PWG_TOKEN' => new \Piwigo\Csrf\CsrfService()
                    ->getToken(),
            ]
        );

        // +-------------------------------------------------------------------+
        // |                              thumbnails                           |
        // +-------------------------------------------------------------------+

        $thumbnail_rows = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Image\ImageEntity::class)
            ->findThumbnailRowsForCategoryOrderedByRank($category_id);
        if (count($thumbnail_rows) > 0) {
            // template thumbnail initialization
            $current_rank = 1;
            $derivativeParams = $this->imageStdParams->get_by_type(ImageStdParams::SQUARE);
            foreach ($thumbnail_rows as $row) {
                $derivative = new DerivativeImage($derivativeParams, new SrcImage($row));

                if (! in_array($row['name'], [null, false, 0, '0', '', []], true)) {
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

        $image_order = explode(',', $category->imageOrder ?? '');

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
