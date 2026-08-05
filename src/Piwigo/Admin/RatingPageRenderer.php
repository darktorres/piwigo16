<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Auth\AccessControl;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Image\DerivativeImage;

/**
 * Ported from admin/rating.php (page slug "rating").
 */
final class RatingPageRenderer
{
    public function render(Lang $lang, AccessControl $accessControl, UrlServiceInterface $urlService, \Piwigo\Template\CurrentTemplate $currentTemplate, \Piwigo\Config\CurrentConfig $currentConfig, \Piwigo\Category\CategoryService $categoryService, \Piwigo\Validation\InputValidator $inputValidator): void
    {
        $template = $currentTemplate->get();

        $accessControl->checkStatus(AccessLevel::Administrator);

        $ratingRequest = Request\RatingRequest::fromGlobals($inputValidator);

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('rating');
        $tabsheet->select('rating');
        $tabsheet->assign($currentTemplate);

        $start = $ratingRequest->start;
        $elements_per_page = $ratingRequest->elementsPerPage;
        $order_by_index = $ratingRequest->orderByIndex;

        // \Piwigo\Config\CurrentConfig::guestId() is set as a PHP int literal in
        // include/config_default.inc.php.
        $conf_guest_id = $currentConfig->guestId();
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

        $rate_repository = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Rate\RateEntity::class);

        $usernames_by_id = $rate_repository->findUsernamesById();
        $users = [];
        foreach ($usernames_by_id as $user_id => $username) {
            $users[$user_id] = stripslashes($username);
        }

        $nb_images = $rate_repository->countRatedElements($filter_user_id, $exclude_filter_user, $cat_ids);
        $nb_elements = $rate_repository->countAllRates();

        $template->set_filename('rating', 'rating.tpl');

        $template->assign(
            [
                'navbar' => new \Piwigo\Core\PaginationService($currentConfig)
                    ->createNavigationBar($urlService->getRootUrl() . 'admin.php' . $urlService->getQueryStringDiff(['start', 'del']), $nb_images, $start, $elements_per_page),
                'F_ACTION' => $urlService->getRootUrl() . 'admin.php',
                'DISPLAY' => $elements_per_page,
                'NB_ELEMENTS' => $nb_elements,
                'category' => ($ratingRequest->catPresent ? [$ratingRequest->catRaw] : []),
                'CACHE_KEYS' => AdminUiHelper::getAdminClientCacheKeys($urlService, ['categories']),
            ]
        );

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

        for ($i = 0; $i < count($available_order_by); $i++) {
            $template->append(
                'order_by_options',
                $available_order_by[$i][0]
            );
        }
        $template->assign('order_by_options_selected', [$order_by_index]);

        $user_options = [
            'all' => $lang->t('all'),
            'user' => $lang->t('Users'),
            'guest' => $lang->t('Guests'),
        ];

        $template->assign('user_options', $user_options);
        $template->assign('user_options_selected', [$ratingRequest->usersRaw]);
        $template->assign('ADMIN_PAGE_TITLE', $lang->t('Rating'));

        $images = $rate_repository->findRatingReport(
            $filter_user_id,
            $exclude_filter_user,
            $cat_ids,
            $available_order_by[$order_by_index][1],
            $elements_per_page,
            $start
        );

        $template->assign('images', []);
        foreach ($images as $image) {
            $thumbnail_src = DerivativeImage::thumb_url($image);

            $image_url = $urlService->getRootUrl() . 'admin.php?page=photo-' . $image['id'];

            $rates = $rate_repository->findRateRowsForElement($image['id']);
            $nb_rates = count($rates);

            $tpl_image =
              [
                  'id' => $image['id'],
                  'U_THUMB' => $thumbnail_src,
                  'U_URL' => $image_url,
                  'SCORE_RATE' => $image['score'],
                  'AVG_RATE' => $image['avg_rates'],
                  'SUM_RATE' => $image['sum_rates'],
                  'NB_RATES' => $image['nb_rates'],
                  'NB_RATES_TOTAL' => $nb_rates,
                  'FILE' => $image['file'],
                  'rates' => [],
              ];

            foreach ($rates as $rate_row) {
                if (isset($users[$rate_row->userId])) {
                    $user_rate = $users[$rate_row->userId];
                } else {
                    $user_rate = '? ' . $rate_row->userId;
                }
                if ($rate_row->anonymousId !== '') {
                    $user_rate .= '(' . $rate_row->anonymousId . ')';
                }

                $tpl_image['rates'][] = [
                    ...$rate_row->toArray(),
                    'USER' => $user_rate,
                ];
            }
            $template->append('images', $tpl_image);
        }

        $template->assign_var_from_handle('ADMIN_CONTENT', 'rating');
    }
}
