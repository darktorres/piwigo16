<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\Projection\RatingUserView;
use Piwigo\Admin\Projection\UserRatingRow;
use Piwigo\Admin\Request\RatingUserFilterRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\TypedRepository;
use Piwigo\GeoIp\GeoIpLookupService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\Projection\SrcImageInfo;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Rate\Projection\ImageThumbUrl;
use Piwigo\Rate\RateEntity;
use Piwigo\Rate\RateRepository;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;

/**
 * Ported from admin/rating_user.php (page slug "rating_user") -- the admin
 * "rating by user" report, a sibling top-level page to "rating" sharing the
 * same tabsheet group (not a nested tab of it).
 *
 * `rating_user.js`'s delete-ratings AJAX action reads `csrf_token` off
 * the page data exposed by `rating_user.latte` and sends it back as the
 * `X-CSRF-Token` header -- render() must supply a real token for that
 * request to validate (same pre-existing gap RatingPageRenderer's own
 * conversion found and fixed).
 */
final class RatingUserPageRenderer
{
    public function render(Lang $lang, AccessControl $accessControl, UrlServiceInterface $urlService, ImageStdParams $imageStdParams, CurrentTemplate $currentTemplate, CurrentConfig $currentConfig, EventDispatcher $eventDispatcher, EntityManagerInterface $entityManager, CsrfService $csrfService, Renderer $renderer, GeoIpLookupService $geoIpLookupService): AdminPageResult
    {
        $tabsheet = new Tabsheet();
        $tabsheet->setId('rating');
        $tabsheet->select('rating_user', $eventDispatcher);
        $tabsheet->assign($currentTemplate, $renderer);

        $ratingFilter = RatingUserFilterRequest::fromGlobals($currentConfig->topNumber);
        $filter_min_rates = $ratingFilter->minRates;
        $consensus_top_number = $ratingFilter->consensusTopNumber;

        // build users
        $rate_repository = TypedRepository::narrow($entityManager->getRepository(RateEntity::class), RateRepository::class);

        $users_by_id = [];
        foreach ($rate_repository->findUsersWithStatusByIdUsername() as $u) {
            $users_by_id[$u->id] = [
                'name' => $u->name,
                'anon' => ! $accessControl->isAuthorizeStatus(AccessLevel::Classic, $u->status),
            ];
        }

        $rate_items = $currentConfig->rateItems;

        // by user aggregation
        $image_ids = [];
        /** @var array<string, UserRatingAccumulator> $by_user_ratings */
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
            // Rate::$date is nullable, and every consumer of it --
            // the two rendered dates and lastRateCompare()'s strcmp --
            // already treated null as the empty string.
            $rate_date = $rate_row->date ?? '';

            if (! isset($by_user_ratings[$user_key])) {
                $by_user_ratings[$user_key] = new UserRatingAccumulator(
                    uid: $user_id,
                    aid: $usr['anon'] ? $rate_row->anonymousId : '',
                    lastDate: $rate_date,
                    rateItems: $rate_items,
                );
            }

            $element_id = $rate_row->elementId->value;
            $by_user_ratings[$user_key]->add($rate_row->rate, $element_id, $rate_date);
            $image_ids[$element_id] = 1;
        }

        // get image tn urls
        $image_urls = [];
        if (count($image_ids) > 0) {
            $params = $imageStdParams->getByType(ImageStdParams::SQUARE);
            foreach ($rate_repository->findImageThumbInfoByIds(array_keys($image_ids)) as $thumb_row) {
                $image_urls[$thumb_row->id] = new ImageThumbUrl(
                    // ImageThumbInfo's own producing DQL never selects width/
                    // height at all (see RateRepository::findImageThumbInfoByIds()),
                    // matching SrcImageInfo's own "dimensions never given" state.
                    tn: DerivativeImage::url($params, new SrcImageInfo(
                        id: $thumb_row->id,
                        path: $thumb_row->path,
                        representativeExt: $thumb_row->representativeExt,
                        dimensionsUnavailable: true,
                    )),
                );
            }
        }

        // all image averages
        $all_img_sum = $rate_repository->findAverageRatePerElement();

        $best_rated = array_flip($rate_repository->findTopRatedImageIds($consensus_top_number));

        // by user stats
        /** @var array<string, UserRatingRow> $rating_rows */
        $rating_rows = [];
        foreach ($by_user_ratings as $user_key => $accumulator) {
            $row = $accumulator->freeze($all_img_sum, $best_rated);

            // filter
            if ($row->count > $filter_min_rates) {
                $rating_rows[$user_key] = $row;
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
        uasort($rating_rows, $available_order_by[$order_by_index][1]);

        $nb_elements = $rate_repository->countAllRates();

        $adminContent = $renderer->render(new RatingUserView(
            orderByOptionsSelected: [$order_by_index],
            formAction: $urlService->getRootUrl() . 'admin.php',
            minRates: $filter_min_rates,
            consensusTopNumber: $consensus_top_number,
            availableRates: $currentConfig->rateItems,
            ratings: $rating_rows,
            imageUrls: $image_urls,
            tnWidth: (int) $imageStdParams->getByType(ImageStdParams::SQUARE)->sizing->ideal_size->width,
            nbElements: $nb_elements,
            orderByOptions: $order_by_options,
            csrfToken: $csrfService
                ->getToken(),
            rootUrl: $urlService->getRootUrl(),
            geoIpAvailable: $geoIpLookupService->isAvailable(),
        ));

        return new AdminPageResult(
            content: $adminContent,
            pageTitle: $lang->t('Rating'),
        );
    }

    /**
     * The 5 compare*() methods below sort {@see UserRatingRow}s. They
     * used to read a `array<string, mixed>` defensively -- an
     * is_numeric()/is_string() narrowing plus a default per key --
     * because the row they sorted was built across three separate
     * mutation points and had no shape a caller could rely on. It is
     * frozen by {@see UserRatingAccumulator::freeze()} now, so each
     * one reads the field it sorts on directly.
     */
    public static function avgCompare(UserRatingRow $a, UserRatingRow $b): int
    {
        $d = $a->avg - $b->avg;
        return $d === 0.0 ? 0 : ($d < 0 ? -1 : 1);
    }

    public static function countCompare(UserRatingRow $a, UserRatingRow $b): int
    {
        // Cast, even though both sides are int: the zero test below
        // is a strict one, and `0 === 0.0` is false.
        $d = (float) $a->count - (float) $b->count;
        return $d === 0.0 ? 0 : ($d < 0 ? -1 : 1);
    }

    public static function cvCompare(UserRatingRow $a, UserRatingRow $b): int
    {
        $d = $b->cv - $a->cv; // desc
        return $d === 0.0 ? 0 : ($d < 0 ? -1 : 1);
    }

    public static function consensusDevCompare(UserRatingRow $a, UserRatingRow $b): int
    {
        $d = $b->cd - $a->cd; // desc
        return $d === 0.0 ? 0 : ($d < 0 ? -1 : 1);
    }

    public static function lastRateCompare(UserRatingRow $a, UserRatingRow $b): int
    {
        return -strcmp($a->lastDate, $b->lastDate);
    }
}
