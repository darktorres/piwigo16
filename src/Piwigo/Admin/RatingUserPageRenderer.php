<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\Rate\RateRepository;

/**
 * Ported from admin/rating_user.php (page slug "rating_user") -- the admin
 * "rating by user" report, a sibling top-level page to "rating" sharing the
 * same tabsheet group (not a nested tab of it).
 */
final class RatingUserPageRenderer
{
    public function render(UrlServiceInterface $urlService): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('rating');
        $tabsheet->select('rating_user');
        $tabsheet->assign();

        $filter_min_rates = 2;
        if (isset($_GET['f_min_rates']) && is_numeric($_GET['f_min_rates'])) {
            $filter_min_rates = (int) $_GET['f_min_rates'];
        }

        $consensus_top_number = is_numeric(\Piwigo\Config\Config::topNumber()) ? (int) \Piwigo\Config\Config::topNumber() : 15;
        if (isset($_GET['consensus_top_number']) && is_numeric($_GET['consensus_top_number'])) {
            $consensus_top_number = (int) $_GET['consensus_top_number'];
        }

        // build users
        /** @var array<string, string> $user_fields */
        $user_fields = \Piwigo\Config\Config::userFields();
        $rate_repository = new RateRepository(DbConnection::build());

        $users_by_id = [];
        foreach ($rate_repository->findUsersWithStatusByIdUsername($user_fields['id'], $user_fields['username']) as $u) {
            $users_by_id[$u['id']] = [
                'name' => $u['name'],
                'anon' => ! \Piwigo\Auth\AccessControl::isAuthorizeStatus(AccessLevel::Classic, $u['status']),
            ];
        }

        $rate_items_raw = \Piwigo\Config\Config::rateItems();
        /** @var list<int> $rate_items */
        $rate_items = is_array($rate_items_raw) ? array_map(intval(...), array_filter($rate_items_raw, is_numeric(...))) : [];

        $by_user_rating_model = [
            'rates' => [],
        ];
        foreach ($rate_items as $rate) {
            $by_user_rating_model['rates'][$rate] = [];
        }

        // by user aggregation
        $image_ids = [];
        $by_user_ratings = [];
        foreach ($rate_repository->findAllRatesOrderedByDateDesc() as $rate_row) {
            $user_id = $rate_row['user_id'];
            if (! isset($users_by_id[$user_id])) {
                $users_by_id[$user_id] = [
                    'name' => '???' . $user_id,
                    'anon' => false,
                ];
            }
            $usr = $users_by_id[$user_id];
            if ($usr['anon']) {
                $user_key = $usr['name'] . '(' . $rate_row['anonymous_id'] . ')';
            } else {
                $user_key = $usr['name'];
            }
            if (! isset($by_user_ratings[$user_key])) {
                $rating = $by_user_rating_model;
                $rating['uid'] = $user_id;
                $rating['aid'] = $usr['anon'] ? $rate_row['anonymous_id'] : '';
                $rating['last_date'] = $rating['first_date'] = $rate_row['date'];
            } else {
                $rating = $by_user_ratings[$user_key];
                $rating['first_date'] = $rate_row['date'];
            }

            $element_id = $rate_row['element_id'];
            $rating['rates'][$rate_row['rate']][] = [
                'id' => $element_id,
                'date' => $rate_row['date'],
            ];
            $by_user_ratings[$user_key] = $rating;
            $image_ids[$element_id] = 1;
        }

        // get image tn urls
        $image_urls = [];
        if (count($image_ids) > 0) {
            $params = ImageStdParams::get_by_type(ImageStdParams::SQUARE);
            foreach ($rate_repository->findImageThumbInfoByIds(array_keys($image_ids)) as $thumb_row) {
                $image_urls[$thumb_row['id']] = [
                    'tn' => DerivativeImage::url($params, $thumb_row),
                    'page' => $urlService->makePictureUrl([
                        'image_id' => $thumb_row['id'],
                        'image_file' => $thumb_row['file'],
                    ]),
                ];
            }
        }

        // all image averages
        $all_img_sum = [];
        foreach ($rate_repository->findAverageRatePerElement() as $element_id => $avg) {
            $all_img_sum[$element_id] = [
                'avg' => $avg,
            ];
        }

        $best_rated = array_flip($rate_repository->findTopRatedImageIds($consensus_top_number));

        // by user stats
        foreach ($by_user_ratings as $id => &$rating) {
            $c = 0;
            $s = 0;
            $ss = 0;
            $consensus_dev = 0;
            $consensus_dev_top = 0;
            $consensus_dev_top_count = 0;
            foreach ($rating['rates'] as $rate => $rates) {
                $ct = count($rates);
                $c += $ct;
                $s += $ct * $rate;
                $ss += $ct * $rate * $rate;
                foreach ($rates as $id_date) {
                    $dev = abs($rate - $all_img_sum[$id_date['id']]['avg']);
                    $consensus_dev += $dev;
                    if (isset($best_rated[$id_date['id']])) {
                        $consensus_dev_top += $dev;
                        $consensus_dev_top_count++;
                    }
                }
            }

            $consensus_dev /= $c;
            if ($consensus_dev_top_count > 0) {
                $consensus_dev_top /= $consensus_dev_top_count;
            }

            $var = ($ss - $s * $s / $c) / $c;
            $rating += [
                'id' => $id,
                'count' => $c,
                'avg' => $s / $c,
                'cv' => $s === 0 ? -1 : sqrt($var) / ($s / $c), // http://en.wikipedia.org/wiki/Coefficient_of_variation
                'cd' => $consensus_dev,
                'cdtop' => $consensus_dev_top_count > 0 ? $consensus_dev_top : '',
            ];
        }
        unset($rating);

        // filter
        foreach ($by_user_ratings as $id => $rating) {
            if ($rating['count'] <= $filter_min_rates) {
                unset($by_user_ratings[$id]);
            }
        }

        $order_by_index = 4;
        if (isset($_GET['order_by']) and is_numeric($_GET['order_by'])) {
            $order_by_index = (int) $_GET['order_by'];
        }

        $available_order_by = [
            [Lang::t('Average rate'), self::avgCompare(...)],
            [Lang::t('Number of rates'), self::countCompare(...)],
            [Lang::t('Variation'), self::cvCompare(...)],
            [Lang::t('Consensus deviation'), self::consensusDevCompare(...)],
            [Lang::t('Last'), self::lastRateCompare(...)],
        ];

        for ($i = 0; $i < count($available_order_by); $i++) {
            $template->append(
                'order_by_options',
                $available_order_by[$i][0]
            );
        }
        $template->assign('order_by_options_selected', [$order_by_index]);

        uasort($by_user_ratings, $available_order_by[$order_by_index][1]);

        $nb_elements = $rate_repository->countAllRates();

        $template->assign([
            'F_ACTION' => $urlService->getRootUrl() . 'admin.php',
            'F_MIN_RATES' => $filter_min_rates,
            'CONSENSUS_TOP_NUMBER' => $consensus_top_number,
            'available_rates' => \Piwigo\Config\Config::rateItems(),
            'ratings' => $by_user_ratings,
            'image_urls' => $image_urls,
            'TN_WIDTH' => ImageStdParams::get_by_type(ImageStdParams::SQUARE)->sizing->ideal_size[0],
            'NB_ELEMENTS' => $nb_elements,
            'ADMIN_PAGE_TITLE' => Lang::t('Rating'),
        ]);
        $template->set_filename('rating', 'rating_user.tpl');
        $template->assign_var_from_handle('ADMIN_CONTENT', 'rating');
    }

    /** @param array<string, mixed> $a
     *  @param array<string, mixed> $b */
    public static function avgCompare(array $a, array $b): int
    {
        $a_avg = is_numeric($a['avg'] ?? null) ? (float) $a['avg'] : 0.0;
        $b_avg = is_numeric($b['avg'] ?? null) ? (float) $b['avg'] : 0.0;
        $d = $a_avg - $b_avg;
        return $d === 0.0 ? 0 : ($d < 0 ? -1 : 1);
    }

    /** @param array<string, mixed> $a
     *  @param array<string, mixed> $b */
    public static function countCompare(array $a, array $b): int
    {
        $a_count = is_numeric($a['count'] ?? null) ? (float) $a['count'] : 0.0;
        $b_count = is_numeric($b['count'] ?? null) ? (float) $b['count'] : 0.0;
        $d = $a_count - $b_count;
        return $d === 0.0 ? 0 : ($d < 0 ? -1 : 1);
    }

    /** @param array<string, mixed> $a
     *  @param array<string, mixed> $b */
    public static function cvCompare(array $a, array $b): int
    {
        $a_cv = is_numeric($a['cv'] ?? null) ? (float) $a['cv'] : 0.0;
        $b_cv = is_numeric($b['cv'] ?? null) ? (float) $b['cv'] : 0.0;
        $d = $b_cv - $a_cv; // desc
        return $d === 0.0 ? 0 : ($d < 0 ? -1 : 1);
    }

    /** @param array<string, mixed> $a
     *  @param array<string, mixed> $b */
    public static function consensusDevCompare(array $a, array $b): int
    {
        $a_cd = is_numeric($a['cd'] ?? null) ? (float) $a['cd'] : 0.0;
        $b_cd = is_numeric($b['cd'] ?? null) ? (float) $b['cd'] : 0.0;
        $d = $b_cd - $a_cd; // desc
        return $d === 0.0 ? 0 : ($d < 0 ? -1 : 1);
    }

    /** @param array<string, mixed> $a
     *  @param array<string, mixed> $b */
    public static function lastRateCompare(array $a, array $b): int
    {
        $a_date = is_string($a['last_date'] ?? null) ? $a['last_date'] : '';
        $b_date = is_string($b['last_date'] ?? null) ? $b['last_date'] : '';
        return -strcmp($a_date, $b_date);
    }
}
