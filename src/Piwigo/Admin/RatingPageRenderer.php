<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\AccessLevel;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Image\DerivativeImage;
use Piwigo\Rate\RateRepository;
use Piwigo\Template\Template;

/**
 * Ported from admin/rating.php (page slug "rating").
 */
final class RatingPageRenderer
{
    public function render(): void
    {
        /**
         * @var array<string, mixed> $conf
         * @var Template $template
         */
        global $conf, $template;

        check_status(AccessLevel::Administrator);

        check_input_parameter('display', $_GET, false, ValidationPattern::ID);

        $tabsheet = new tabsheet();
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

        // $conf['guest_id'] is set as a PHP int literal in
        // include/config_default.inc.php.
        $conf_guest_id = $conf['guest_id'];
        $guest_id = is_numeric($conf_guest_id) ? (int) $conf_guest_id : 0;

        $cat_ids = [];
        if (isset($_GET['cat']) and is_numeric($_GET['cat'])) {
            $cat_ids = array_values(array_map(intval(...), array_filter(get_subcat_ids([(int) $_GET['cat']]), is_numeric(...))));
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
        $user_fields = $conf['user_fields'];
        $rate_repository = new RateRepository(DbConnection::build());

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
                'navbar' => create_navigation_bar(
                    PHPWG_ROOT_PATH . 'admin.php' . get_query_string_diff(['start', 'del']),
                    $nb_images,
                    $start,
                    $elements_per_page
                ),
                'F_ACTION' => PHPWG_ROOT_PATH . 'admin.php',
                'DISPLAY' => $elements_per_page,
                'NB_ELEMENTS' => $nb_elements,
                'category' => (isset($_GET['cat']) ? [$_GET['cat']] : []),
                'CACHE_KEYS' => get_admin_client_cache_keys(['categories']),
            ]
        );

        $available_order_by = [
            [l10n('Rate date'), 'recently_rated DESC'],
            [l10n('Rating score'), 'score DESC'],
            [l10n('Average rate'), 'avg_rates DESC'],
            [l10n('Number of rates'), 'nb_rates DESC'],
            [l10n('Sum of rates'), 'sum_rates DESC'],
            [l10n('File name'), 'file DESC'],
            [l10n('Creation date'), 'date_creation DESC'],
            [l10n('Post date'), 'date_available DESC'],
        ];

        for ($i = 0; $i < count($available_order_by); $i++) {
            $template->append(
                'order_by_options',
                $available_order_by[$i][0]
            );
        }
        $template->assign('order_by_options_selected', [$order_by_index]);

        $user_options = [
            'all' => l10n('all'),
            'user' => l10n('Users'),
            'guest' => l10n('Guests'),
        ];

        $template->assign('user_options', $user_options);
        $template->assign('user_options_selected', [$_GET['users'] ?? null]);
        $template->assign('ADMIN_PAGE_TITLE', l10n('Rating'));

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

            $image_url = get_root_url() . 'admin.php?page=photo-' . $image['id'];

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
                if (isset($users[$rate_row['user_id']])) {
                    $user_rate = $users[$rate_row['user_id']];
                } else {
                    $user_rate = '? ' . $rate_row['user_id'];
                }
                if ($rate_row['anonymous_id'] !== '') {
                    $user_rate .= '(' . $rate_row['anonymous_id'] . ')';
                }

                $tpl_image['rates'][] = [
                    ...$rate_row,
                    'USER' => $user_rate,
                ];
            }
            $template->append('images', $tpl_image);
        }

        $template->assign_var_from_handle('ADMIN_CONTENT', 'rating');
    }
}
