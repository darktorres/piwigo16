<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Projection\RatingPageContext;
use Piwigo\Admin\Request\RatingRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\PaginationService;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Image\DerivativeImage;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Rate\RateEntity;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Validation\InputValidator;

/**
 * Ported from admin/rating.php (page slug "rating").
 */
final class RatingPageRenderer
{
    public function render(Lang $lang, AccessControl $accessControl, UrlServiceInterface $urlService, CurrentTemplate $currentTemplate, CurrentConfig $currentConfig, CategoryService $categoryService, InputValidator $inputValidator, EventDispatcher $eventDispatcher): void
    {
        $template = $currentTemplate->get();

        $accessControl->checkStatus(AccessLevel::Administrator);

        $ratingRequest = RatingRequest::fromGlobals($inputValidator);

        $tabsheet = new Tabsheet();
        $tabsheet->setId('rating');
        $tabsheet->select('rating', $eventDispatcher);
        $tabsheet->assign($currentTemplate);

        $start = $ratingRequest->start;
        $elements_per_page = $ratingRequest->elementsPerPage;
        $order_by_index = $ratingRequest->orderByIndex;

        // \Piwigo\Config\CurrentConfig::guestId() is set as a PHP int literal in
        // include/config_default.inc.php.
        $conf_guest_id = $currentConfig->guestId;
        $guest_id = $conf_guest_id;

        $conn = DbConnection::build();

        $cat_ids = [];
        if ($ratingRequest->catId !== null) {
            $cat_ids = array_values(array_map(intval(...), array_filter($categoryService->getSubcatIds([$ratingRequest->catId]), is_numeric(...))));
        }

        $filter_user_id = null;
        $exclude_filter_user = false;
        if ($ratingRequest->isUsersUser) {
            $filter_user_id = $guest_id;
            $exclude_filter_user = true;
        } elseif ($ratingRequest->isUsersGuest) {
            $filter_user_id = $guest_id;
            $exclude_filter_user = false;
        }

        $rate_repository = EntityManagerFactory::build($conn)->getRepository(RateEntity::class);

        $usernames_by_id = $rate_repository->findUsernamesById();
        $users = [];
        foreach ($usernames_by_id as $user_id => $username) {
            $users[$user_id] = stripslashes($username);
        }

        $filterUserId = $filter_user_id === null ? null : UserId::from($filter_user_id);

        $nb_images = $rate_repository->countRatedElements($filterUserId, $exclude_filter_user, $cat_ids);
        $nb_elements = $rate_repository->countAllRates();

        $template->set_filename('rating', 'rating.tpl');

        $navbar = new PaginationService($currentConfig)
            ->createNavigationBar($urlService->getRootUrl() . 'admin.php' . $urlService->getQueryStringDiff(['start', 'del']), $nb_images, $start, $elements_per_page);

        $available_order_by = [
            [$lang->t('Rate date'), 'recently_rated'],
            [$lang->t('Rating score'), 'score'],
            [$lang->t('Average rate'), 'avg_rates'],
            [$lang->t('Number of rates'), 'nb_rates'],
            [$lang->t('Sum of rates'), 'sum_rates'],
            [$lang->t('File name'), 'file'],
            [$lang->t('Creation date'), 'date_creation'],
            [$lang->t('Post date'), 'date_available'],
        ];

        if ($order_by_index < 0 or $order_by_index >= count($available_order_by)) {
            $order_by_index = 0;
        }

        $order_by_options = [];
        for ($i = 0; $i < count($available_order_by); $i++) {
            $order_by_options[] = $available_order_by[$i][0];
        }
        $user_options = [
            'all' => $lang->t('all'),
            'user' => $lang->t('Users'),
            'guest' => $lang->t('Guests'),
        ];

        $images = $rate_repository->findRatingReport(
            $filterUserId,
            $exclude_filter_user,
            $cat_ids,
            $available_order_by[$order_by_index][1],
            $elements_per_page,
            $start
        );

        $tpl_images = [];
        foreach ($images as $image) {
            $thumbnail_src = DerivativeImage::thumb_url($image->toArray());

            $image_url = $urlService->getRootUrl() . 'admin.php?page=photo-' . $image->id;

            $rates = $rate_repository->findRateRowsForElement(ImageId::from($image->id));
            $nb_rates = count($rates);

            $tpl_image =
              [
                  'id' => $image->id,
                  'U_THUMB' => $thumbnail_src,
                  'U_URL' => $image_url,
                  'SCORE_RATE' => $image->score,
                  'AVG_RATE' => $image->avgRates,
                  'SUM_RATE' => $image->sumRates,
                  'NB_RATES' => $image->nbRates,
                  'NB_RATES_TOTAL' => $nb_rates,
                  'FILE' => $image->file,
                  'rates' => [],
              ];

            foreach ($rates as $rate_row) {
                if (isset($users[$rate_row->userId->value])) {
                    $user_rate = $users[$rate_row->userId->value];
                } else {
                    $user_rate = '? ' . $rate_row->userId->value;
                }
                if ($rate_row->anonymousId !== '') {
                    $user_rate .= '(' . $rate_row->anonymousId . ')';
                }

                $tpl_image['rates'][] = [
                    ...$rate_row->toArray(),
                    'USER' => $user_rate,
                ];
            }
            $tpl_images[] = $tpl_image;
        }

        $template->assignContext(new RatingPageContext(
            navbar: $navbar,
            fAction: $urlService->getRootUrl() . 'admin.php',
            display: $elements_per_page,
            nbElements: $nb_elements,
            category: $ratingRequest->catPresent ? [$ratingRequest->catRaw] : [],
            cacheKeys: AdminUiHelper::getAdminClientCacheKeys($urlService, ['categories']),
            orderByOptionsSelected: [$order_by_index],
            userOptions: $user_options,
            userOptionsSelected: [$ratingRequest->usersRaw],
            adminPageTitle: $lang->t('Rating'),
            orderByOptions: $order_by_options,
            images: $tpl_images,
        ));

        $template->assign_var_from_handle('ADMIN_CONTENT', 'rating');
    }
}
