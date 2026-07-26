<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Image\DerivativeImage;

/**
 * Ported from admin/rating.php (page slug "rating").
 */
final class RatingPageRenderer
{
    public function render(UrlServiceInterface $urlService): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Administrator);

        new \Piwigo\Validation\InputValidator()
            ->validate('display', $_GET, false, ValidationPattern::ID);

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('rating');
        $tabsheet->select('rating');
        $tabsheet->assign();

        if (isset($_GET['start']) and is_numeric($_GET['start'])) {
            $start = (int) $_GET['start'];
        } else {
            $start = 0;
        }

        $elements_per_page = 10;
        if (isset($_GET['display']) and is_numeric($_GET['display'])) {
            $elements_per_page = (int) $_GET['display'];
        }

        $order_by_index = 0;
        if (isset($_GET['order_by']) and is_numeric($_GET['order_by'])) {
            $order_by_index = (int) $_GET['order_by'];
        }

        // \Piwigo\Config\CurrentConfig::guestId() is set as a PHP int literal in
        // include/config_default.inc.php.
        $conf_guest_id = \Piwigo\Config\CurrentConfig::guestId();
        $guest_id = $conf_guest_id;

        $conn = DbConnection::build();

        $cat_ids = [];
        if (isset($_GET['cat']) and is_numeric($_GET['cat'])) {
            $categoryService = \Piwigo\Bootstrap\CoreDomainAccessor::categoryService();
            $cat_ids = array_values(array_map(intval(...), array_filter($categoryService->getSubcatIds([(int) $_GET['cat']]), is_numeric(...))));
        }

        $filter_user_id = null;
        $exclude_filter_user = false;
        if (isset($_GET['users'])) {
            if ($_GET['users'] === 'user') {
                $filter_user_id = $guest_id;
                $exclude_filter_user = true;
            } elseif ($_GET['users'] === 'guest') {
                $filter_user_id = $guest_id;
                $exclude_filter_user = false;
            }
        }

        /** @var array<string, string> $user_fields */
        $user_fields = \Piwigo\Config\CurrentConfig::userFields();
        $rate_repository = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Rate\RateEntity::class);

        $usernames_by_id = $rate_repository->findUsernamesById($user_fields['id'], $user_fields['username']);
        $users = [];
        foreach ($usernames_by_id as $user_id => $username) {
            $users[$user_id] = stripslashes($username);
        }

        $nb_images = $rate_repository->countRatedElements($filter_user_id, $exclude_filter_user, $cat_ids);
        $nb_elements = $rate_repository->countAllRates();

        $template->set_filename('rating', 'rating.tpl');

        $template->assign(
            [
                'navbar' => new \Piwigo\Core\PaginationService()
                    ->createNavigationBar($urlService->getRootUrl() . 'admin.php' . $urlService->getQueryStringDiff(['start', 'del']), $nb_images, $start, $elements_per_page),
                'F_ACTION' => $urlService->getRootUrl() . 'admin.php',
                'DISPLAY' => $elements_per_page,
                'NB_ELEMENTS' => $nb_elements,
                'category' => (isset($_GET['cat']) ? [$_GET['cat']] : []),
                'CACHE_KEYS' => AdminUiHelper::getAdminClientCacheKeys($urlService, ['categories']),
            ]
        );

        $available_order_by = [
            [Lang::t('Rate date'), 'recently_rated DESC'],
            [Lang::t('Rating score'), 'score DESC'],
            [Lang::t('Average rate'), 'avg_rates DESC'],
            [Lang::t('Number of rates'), 'nb_rates DESC'],
            [Lang::t('Sum of rates'), 'sum_rates DESC'],
            [Lang::t('File name'), 'file DESC'],
            [Lang::t('Creation date'), 'date_creation DESC'],
            [Lang::t('Post date'), 'date_available DESC'],
        ];

        for ($i = 0; $i < count($available_order_by); $i++) {
            $template->append(
                'order_by_options',
                $available_order_by[$i][0]
            );
        }
        $template->assign('order_by_options_selected', [$order_by_index]);

        $user_options = [
            'all' => Lang::t('all'),
            'user' => Lang::t('Users'),
            'guest' => Lang::t('Guests'),
        ];

        $template->assign('user_options', $user_options);
        $template->assign('user_options_selected', [$_GET['users'] ?? null]);
        $template->assign('ADMIN_PAGE_TITLE', Lang::t('Rating'));

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
