<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\Projection\RatingReportImageRow;
use Piwigo\Admin\Projection\RatingReportRateRow;
use Piwigo\Admin\Projection\RatingView;
use Piwigo\Admin\Request\RatingRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\PaginationService;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\TypedRepository;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\Projection\SrcImageInfo;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Rate\RateEntity;
use Piwigo\Rate\RateRepository;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Validation\InputValidator;

/**
 * Ported from admin/rating.php (page slug "rating").
 *
 * `rating.js`'s delete-rate AJAX action reads `csrf_token` off the
 * page data exposed by `rating.latte` and sends it back as the
 * `X-CSRF-Token` header -- render() must supply a real token for that
 * request to validate.
 */
final class RatingPageRenderer
{
    public function render(Lang $lang, AccessControl $accessControl, UrlServiceInterface $urlService, CurrentTemplate $currentTemplate, CurrentConfig $currentConfig, CategoryService $categoryService, InputValidator $inputValidator, EventDispatcher $eventDispatcher, EntityManagerInterface $entityManager, CsrfService $csrfService, Renderer $renderer): AdminPageResult
    {
        $template = $currentTemplate->get();

        $accessControl->checkStatus(AccessLevel::Administrator);

        $ratingRequest = RatingRequest::fromGlobals($inputValidator);

        $tabsheet = new Tabsheet();
        $tabsheet->setId('rating');
        $tabsheet->select('rating', $eventDispatcher);
        $tabsheet->assign($currentTemplate, $renderer);

        $start = $ratingRequest->start;
        $elements_per_page = $ratingRequest->elementsPerPage;
        $order_by_index = $ratingRequest->orderByIndex;

        // \Piwigo\Config\CurrentConfig::guestId() is set as a PHP int literal in
        // include/config_default.inc.php.
        $conf_guest_id = $currentConfig->guestId;
        $guest_id = $conf_guest_id;

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

        $rate_repository = TypedRepository::narrow($entityManager->getRepository(RateEntity::class), RateRepository::class);

        $usernames_by_id = $rate_repository->findUsernamesById();
        $users = [];
        foreach ($usernames_by_id as $user_id => $username) {
            $users[$user_id] = $username;
        }

        $filterUserId = $filter_user_id === null ? null : UserId::from($filter_user_id);

        $nb_images = $rate_repository->countRatedElements($filterUserId, $exclude_filter_user, $cat_ids);
        $nb_elements = $rate_repository->countAllRates();

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
            /**
             * @psalm-suppress InvalidArrayOffset Psalm can't prove $i stays
             *   within $available_order_by's own literal 8-element bound
             *   through a `count()`-based loop condition.
             */
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
            // RatingReportRow's own producing DQL never selects width/height
            // at all (see RateRepository::findRatingReport()), matching
            // SrcImageInfo's own "dimensions never given" state exactly.
            $thumbnail_src = DerivativeImage::thumbUrl(new SrcImageInfo(
                id: $image->id,
                path: $image->path,
                representativeExt: $image->representativeExt,
                dimensionsUnavailable: true,
            ));

            $image_url = $urlService->getRootUrl() . 'admin.php?page=photo-' . $image->id;

            $rates = $rate_repository->findRateRowsForElement(ImageId::from($image->id));
            $nb_rates = count($rates);

            $tpl_rates = [];
            foreach ($rates as $rate_row) {
                if (isset($users[$rate_row->userId->value])) {
                    $user_rate = $users[$rate_row->userId->value];
                } else {
                    $user_rate = '? ' . $rate_row->userId->value;
                }
                if ($rate_row->anonymousId !== '') {
                    $user_rate .= '(' . $rate_row->anonymousId . ')';
                }

                $tpl_rates[] = new RatingReportRateRow(
                    userId: $rate_row->userId->value,
                    elementId: $rate_row->elementId->value,
                    anonymousId: $rate_row->anonymousId,
                    rate: $rate_row->rate,
                    date: $rate_row->date,
                    user: $user_rate,
                );
            }

            $tpl_images[] = new RatingReportImageRow(
                id: $image->id,
                uThumb: $thumbnail_src,
                uUrl: $image_url,
                scoreRate: $image->score,
                avgRate: $image->avgRates,
                sumRate: $image->sumRates,
                nbRates: $image->nbRates,
                nbRatesTotal: $nb_rates,
                file: $image->file,
                rates: $tpl_rates,
            );
        }

        $adminContent = $renderer->render(new RatingView(
            navbar: $navbar->toArray(),
            fAction: $urlService->getRootUrl() . 'admin.php',
            display: $elements_per_page,
            nbElements: $nb_elements,
            category: $ratingRequest->catPresent ? [$ratingRequest->catRaw] : [],
            cacheKeys: AdminUiHelper::getAdminClientCacheKeys($urlService, ['categories']),
            orderByOptionsSelected: [$order_by_index],
            userOptions: $user_options,
            userOptionsSelected: [$ratingRequest->usersRaw],
            orderByOptions: $order_by_options,
            images: array_map(static fn (RatingReportImageRow $image): array => $image->toArray(), $tpl_images),
            csrfToken: $csrfService->getToken(),
            colorscheme: $template->themeConf('colorscheme'),
            rootUrl: $urlService->getRootUrl(),
        ));

        return new AdminPageResult(
            content: $adminContent,
            pageTitle: $lang->t('Rating'),
        );
    }
}
