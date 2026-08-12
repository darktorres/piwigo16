<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Projection\ElementSetRanksHeaderPageContext;
use Piwigo\Admin\Projection\ElementSetRanksSaveSuccessPageContext;
use Piwigo\Admin\Request\ElementSetRanksRequest;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\Projection\Category;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\StringHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Template\CurrentTemplate;

/**
 * Renders the "sort_order" tab of the "album" admin page (dispatched by
 * AlbumSubController) -- changes the rank of images inside a category.
 * The POST handler is CSRF-protected: render() assigns CSRF_TOKEN and
 * element_set_ranks.tpl carries the matching hidden input.
 *
 * Access control is enforced by admin.php's dispatch gate
 * (check_status(AccessLevel::Administrator)) before this renderer runs, so
 * render() does not repeat that check. The cat_id guard is not redundant,
 * though: it reads $_GET['cat_id'] directly rather than through
 * AlbumSubController's own already-validated $category array, so it stays
 * a real, load-bearing check.
 */
final readonly class ElementSetRanksPageRenderer
{
    public function __construct(
        private Lang $lang,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private ErrorCollector $errorCollector,
        private ImageStdParams $imageStdParams,
        private CurrentTemplate $currentTemplate,
        private CategoryAdminService $categoryAdminService,
        private ImageService $imageService,
        private HtmlRenderingInterface $htmlRenderer,
        private CurrentConfig $currentConfig,
    ) {}

    public function render(): void
    {
        $template = $this->currentTemplate->get();

        $htmlRenderer = $this->htmlRenderer;
        $conn = DbConnection::build();

        $sort_fields = [
            '' => '',
            'file ASC' => $this->lang->t('File name, A &rarr; Z'),
            'file DESC' => $this->lang->t('File name, Z &rarr; A'),
            'name ASC' => $this->lang->t('Photo title, A &rarr; Z'),
            'name DESC' => $this->lang->t('Photo title, Z &rarr; A'),
            'date_creation DESC' => $this->lang->t('Date created, new &rarr; old'),
            'date_creation ASC' => $this->lang->t('Date created, old &rarr; new'),
            'date_available DESC' => $this->lang->t('Date posted, new &rarr; old'),
            'date_available ASC' => $this->lang->t('Date posted, old &rarr; new'),
            'rating_score DESC' => $this->lang->t('Rating score, high &rarr; low'),
            'rating_score ASC' => $this->lang->t('Rating score, low &rarr; high'),
            'hit DESC' => $this->lang->t('Visits, high &rarr; low'),
            'hit ASC' => $this->lang->t('Visits, low &rarr; high'),
            'id ASC' => $this->lang->t('Numeric identifier, 1 &rarr; 9'),
            'id DESC' => $this->lang->t('Numeric identifier, 9 &rarr; 1'),
            'rank ASC' => $this->lang->t('Manual sort order'),
        ];

        $elementSetRanksRequest = ElementSetRanksRequest::fromGlobals();

        if (! $elementSetRanksRequest->isCatIdValid) {
            // recordFatal() only records the failure; it doesn't throw or
            // return, so execution always falls through to use
            // $elementSetRanksRequest->catId below regardless of whether
            // cat_id was valid.
            $this->errorCollector->recordFatal('missing cat_id param');
        }

        $category_id = $elementSetRanksRequest->catId;

        $image_order_choice = $elementSetRanksRequest->imageOrderChoice;

        if ($elementSetRanksRequest->isSubmitted) {
            new CsrfService($this->currentConfig)
                ->checkOrFail($this->htmlRenderer, $this->redirectService);

            if ($elementSetRanksRequest->rankOfImage !== []) {
                $this->imageService
                    ->saveImagesOrder(
                        $category_id,
                        array_map(intval(...), array_keys($elementSetRanksRequest->rankOfImage))
                    );
            }

            $message = $this->lang->t('Album updated successfully');

            $image_order = null;
            if ($image_order_choice === 'user_define') {
                $post_image_order = $elementSetRanksRequest->imageOrderFields;
                for ($i = 0; $i < 3; $i++) {
                    $order_value = $post_image_order[$i] ?? null;
                    if (is_string($order_value) && $order_value !== '' && in_array($order_value, array_keys($sort_fields), true)) {
                        if ($image_order !== null) {
                            $image_order .= ',';
                        }
                        $image_order .= $order_value;
                    }
                }
            } elseif ($image_order_choice === 'rank') {
                $image_order = '`rank` ASC';

                $message = $this->lang->t('Images manual order was saved');
            }
            $this->categoryAdminService->saveImageOrder($category_id, $image_order, $elementSetRanksRequest->isImageOrderSubcats, $this->redirectService);

            $template->assignContext(new ElementSetRanksSaveSuccessPageContext($message));
        }

        $base_url = $this->urlService->getRootUrl() . 'admin.php';

        $category = new CategoryRepository(EntityManagerFactory::build($conn), $this->currentConfig)
            ->findById($category_id);
        if (! $category instanceof Category) {
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

        $thumbnails = [];

        $thumbnail_rows = EntityManagerFactory::build($conn)->getRepository(ImageEntity::class)
            ->findThumbnailRowsForCategoryOrderedByRank(CategoryId::from($category_id));
        if (count($thumbnail_rows) > 0) {
            // template thumbnail initialization
            $current_rank = 1;
            $derivativeParams = $this->imageStdParams->getByType(ImageStdParams::SQUARE);
            foreach ($thumbnail_rows as $row) {
                $derivative = new DerivativeImage($derivativeParams, new SrcImage($row), $this->currentConfig);

                if (! in_array($row['name'], [null, false, 0, '0', '', []], true)) {
                    $thumbnail_name = $row['name'];
                } else {
                    $file_wo_ext = is_string($row['file']) ? StringHelper::getFilenameWoExtension($row['file']) : '';
                    $thumbnail_name = str_replace('_', ' ', $file_wo_ext);
                }
                $current_rank++;
                $thumbnails[] = [
                    'ID' => $row['id'],
                    'NAME' => $thumbnail_name,
                    'TN_SRC' => $derivative->getUrl(),
                    'RANK' => $current_rank * 10,
                    'SIZE' => $derivative->getSize(),
                ];
            }
        }
        // image order management
        $image_order = explode(',', $category->imageOrder ?? '');

        $image_order_tpl = [];
        for ($i = 0; $i < 3; $i++) { // 3 fields
            $image_order_tpl[] = $image_order[$i] ?? '';
        }

        $template->assignContext(new ElementSetRanksHeaderPageContext(
            categoriesNav: (string) preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $navigation)),
            formAction: $base_url . $this->urlService->getQueryStringDiff([]),
            pwgToken: new CsrfService($this->currentConfig)
                ->getToken(),
            imageOrderOptions: $sort_fields,
            imageOrderChoice: $image_order_choice,
            thumbnails: $thumbnails,
            imageOrder: $image_order_tpl,
        ));

        $template->assignVarFromTemplate('ADMIN_CONTENT', 'element_set_ranks.latte');
    }
}
