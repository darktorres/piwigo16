<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\Projection\RatingUserPageContext;
use Piwigo\Admin\Request\RatingUserFilterRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\Projection\SrcImageInfo;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Rate\RateEntity;
use Piwigo\Template\CurrentTemplate;

/**
 * Ported from admin/rating_user.php (page slug "rating_user") -- the admin
 * "rating by user" report, a sibling top-level page to "rating" sharing the
 * same tabsheet group (not a nested tab of it).
 */
final class RatingUserPageRenderer
{
    public function render(Lang $lang, AccessControl $accessControl, UrlServiceInterface $urlService, ImageStdParams $imageStdParams, CurrentTemplate $currentTemplate, CurrentConfig $currentConfig, EventDispatcher $eventDispatcher, EntityManagerInterface $entityManager): void
    {
        $template = $currentTemplate->get();

        $tabsheet = new Tabsheet();
        $tabsheet->setId('rating');
        $tabsheet->select('rating_user', $eventDispatcher);
        $tabsheet->assign($currentTemplate);

        $ratingFilter = RatingUserFilterRequest::fromGlobals($currentConfig->topNumber);
        $filter_min_rates = $ratingFilter->minRates;
        $consensus_top_number = $ratingFilter->consensusTopNumber;

        // build users
        $rate_repository = $entityManager->getRepository(RateEntity::class);

        $users_by_id = [];
        foreach ($rate_repository->findUsersWithStatusByIdUsername() as $u) {
            $users_by_id[$u->id] = [
                'name' => $u->name,
                'anon' => ! $accessControl->isAuthorizeStatus(AccessLevel::Classic, $u->status),
            ];
        }

        $rate_items = $currentConfig->rateItems;

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
            $user_id = $rate_row->userId->value;
            if (! isset($users_by_id[$user_id])) {
                $users_by_id[$user_id] = [
                    'name' => '???' . $user_id,
                    'anon' => false,
                ];
            }
            $usr = $users_by_id[$user_id];
            if ($usr['anon']) {
                $user_key = $usr['name'] . '(' . $rate_row->anonymousId . ')';
            } else {
                $user_key = $usr['name'];
            }
            if (! isset($by_user_ratings[$user_key])) {
                $rating = $by_user_rating_model;
                $rating['uid'] = $user_id;
                $rating['aid'] = $usr['anon'] ? $rate_row->anonymousId : '';
                $rating['last_date'] = $rating['first_date'] = $rate_row->date;
            } else {
                $rating = $by_user_ratings[$user_key];
                $rating['first_date'] = $rate_row->date;
            }

            $element_id = $rate_row->elementId->value;
            $rating['rates'][$rate_row->rate][] = [
                'id' => $element_id,
                'date' => $rate_row->date,
            ];
            $by_user_ratings[$user_key] = $rating;
            $image_ids[$element_id] = 1;
        }

        // get image tn urls
        $image_urls = [];
        if (count($image_ids) > 0) {
            $params = $imageStdParams->getByType(ImageStdParams::SQUARE);
            foreach ($rate_repository->findImageThumbInfoByIds(array_keys($image_ids)) as $thumb_row) {
                // ImageThumbInfo's own producing DQL never selects width/
                // height at all (see RateRepository::findImageThumbInfoByIds()),
                // matching SrcImageInfo's own "dimensions never given" state.
                $image_urls[$thumb_row->id] = [
                    'tn' => DerivativeImage::url($params, new SrcImageInfo(
                        id: $thumb_row->id,
                        path: $thumb_row->path,
                        representativeExt: $thumb_row->representativeExt,
                        dimensionsUnavailable: true,
                    )),
                    'page' => $urlService->makePictureUrl([
                        'image_id' => $thumb_row->id,
                        'image_file' => $thumb_row->file,
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
            $consensus_dev = 0.0;
            $consensus_dev_top = 0.0;
            $consensus_dev_top_count = 0;
            foreach ($rating['rates'] as $rate => $rates) {
                $ct = count($rates);
                $c += $ct;
                $s += $ct * $rate;
                $ss += $ct * $rate * $rate;
                foreach ($rates as $id_date) {
                    $dev = abs((float) $rate - $all_img_sum[$id_date['id']]['avg']);
                    $consensus_dev += $dev;
                    if (isset($best_rated[$id_date['id']])) {
                        $consensus_dev_top += $dev;
                        $consensus_dev_top_count++;
                    }
                }
            }

            $consensus_dev /= (float) $c;
            if ($consensus_dev_top_count > 0) {
                $consensus_dev_top /= (float) $consensus_dev_top_count;
            }

            $var = ((float) $ss - (float) $s * (float) $s / (float) $c) / (float) $c;
            $rating += [
                'id' => $id,
                'count' => $c,
                'avg' => $s / $c,
                'cv' => $s === 0 ? -1 : sqrt($var) / ((float) $s / (float) $c), // http://en.wikipedia.org/wiki/Coefficient_of_variation
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

        $available_order_by = [
            [$lang->t('Average rate'), self::avgCompare(...)],
            [$lang->t('Number of rates'), self::countCompare(...)],
            [$lang->t('Variation'), self::cvCompare(...)],
            [$lang->t('Consensus deviation'), self::consensusDevCompare(...)],
            [$lang->t('Last'), self::lastRateCompare(...)],
        ];

        if ($ratingFilter->orderBy !== null and $ratingFilter->orderBy >= 0 and $ratingFilter->orderBy < count($available_order_by)) {
            $order_by_index = $ratingFilter->orderBy;
        }

        $order_by_options = [];
        for ($i = 0; $i < count($available_order_by); $i++) {
            $order_by_options[] = $available_order_by[$i][0];
        }
        uasort($by_user_ratings, $available_order_by[$order_by_index][1]);

        $nb_elements = $rate_repository->countAllRates();

        $template->assignContext(new RatingUserPageContext(
            orderByIndex: $order_by_index,
            formAction: $urlService->getRootUrl() . 'admin.php',
            minRates: $filter_min_rates,
            consensusTopNumber: $consensus_top_number,
            availableRates: $currentConfig->rateItems,
            ratings: $by_user_ratings,
            imageUrls: $image_urls,
            tnWidth: (int) $imageStdParams->getByType(ImageStdParams::SQUARE)->sizing->ideal_size->width,
            nbElements: $nb_elements,
            adminPageTitle: $lang->t('Rating'),
            orderByOptions: $order_by_options,
        ));
        $template->assignVarFromTemplate('ADMIN_CONTENT', 'rating_user.latte');
    }

    /**
     * The 5 compare*() methods below all sort the same $by_user_ratings
     * row, incrementally built up by render() across 3 separate mutation
     * points (the initial $by_user_rating_model copy, the per-rate 'rates'
     * bucket appends, and the final by-user-stats `$rating += [...]`) --
     * a real static shape for it is derivable but would tie every
     * comparator to render()'s own internal accumulation order, for no
     * safety gain: each comparator already reads only its own 1-2 keys
     * defensively (is_numeric()/is_string() + a default), the same
     * cross-domain generic-row-reader pattern used for comparators
     * elsewhere (e.g. {@see \Piwigo\Category\CategoryService::compareByGlobalRank()}).
     *
     * Two mutation-testing-confirmed-equivalent shapes repeated across all 4
     * numeric comparators below:
     * - Each side's `(float)` cast, taken alone, can't change $d's numeric
     *   VALUE (is_numeric() already guarantees an int/float/numeric-string,
     *   which all coerce identically under `-`) -- it can only affect $d's
     *   TYPE, and only if BOTH sides lose their cast simultaneously (which a
     *   single mutation never does: the other side's still-active cast
     *   always forces the subtraction result back to float).
     * - `$d < 0` is only ever reached after `$d === 0.0` has already failed,
     *   so $d is guaranteed nonzero there -- `$d <= 0` would behave
     *   identically to `$d < 0` in that reduced domain.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function avgCompare(array $a, array $b): int
    {
        $a_avg = is_numeric($a['avg'] ?? null) ? (float) $a['avg'] : 0.0;
        $b_avg = is_numeric($b['avg'] ?? null) ? (float) $b['avg'] : 0.0;
        $d = $a_avg - $b_avg;
        return $d === 0.0 ? 0 : ($d < 0 ? -1 : 1);
    }

    /**
     * @param array<string, mixed> $a see {@see avgCompare()}'s own docblock
     * @param array<string, mixed> $b
     */
    public static function countCompare(array $a, array $b): int
    {
        $a_count = is_numeric($a['count'] ?? null) ? (float) $a['count'] : 0.0;
        $b_count = is_numeric($b['count'] ?? null) ? (float) $b['count'] : 0.0;
        $d = $a_count - $b_count;
        return $d === 0.0 ? 0 : ($d < 0 ? -1 : 1);
    }

    /**
     * @param array<string, mixed> $a see {@see avgCompare()}'s own docblock
     * @param array<string, mixed> $b
     */
    public static function cvCompare(array $a, array $b): int
    {
        $a_cv = is_numeric($a['cv'] ?? null) ? (float) $a['cv'] : 0.0;
        $b_cv = is_numeric($b['cv'] ?? null) ? (float) $b['cv'] : 0.0;
        $d = $b_cv - $a_cv; // desc
        return $d === 0.0 ? 0 : ($d < 0 ? -1 : 1);
    }

    /**
     * @param array<string, mixed> $a see {@see avgCompare()}'s own docblock
     * @param array<string, mixed> $b
     */
    public static function consensusDevCompare(array $a, array $b): int
    {
        $a_cd = is_numeric($a['cd'] ?? null) ? (float) $a['cd'] : 0.0;
        $b_cd = is_numeric($b['cd'] ?? null) ? (float) $b['cd'] : 0.0;
        $d = $b_cd - $a_cd; // desc
        return $d === 0.0 ? 0 : ($d < 0 ? -1 : 1);
    }

    /**
     * @param array<string, mixed> $a see {@see avgCompare()}'s own docblock
     * @param array<string, mixed> $b
     */
    public static function lastRateCompare(array $a, array $b): int
    {
        $a_date = is_string($a['last_date'] ?? null) ? $a['last_date'] : '';
        $b_date = is_string($b['last_date'] ?? null) ? $b['last_date'] : '';
        return -strcmp($a_date, $b_date);
    }
}
