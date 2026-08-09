<?php

declare(strict_types=1);

namespace Piwigo\Picture;

use Piwigo\Auth\AccessControl;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Picture\Projection\PictureRateSummaryPageContext;
use Piwigo\Picture\Projection\PictureRatingFormPageContext;
use Piwigo\Rate\RateRepository;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;

/**
 * Renders the picture page's rating summary + rate form. Ported from
 * include/picture_rate.inc.php. Constructor-injects RateRepository --
 * unlike PictureMetadataRenderer/PictureCommentRenderer's own "no
 * constructor deps" shape, this class has only one real caller
 * (PictureController).
 */
final class PictureRateRenderer
{
    public function __construct(
        private readonly AccessControl $accessControl,
        private readonly RateRepository $repo,
        private readonly CurrentUser $currentUser,
        private readonly CurrentTemplate $currentTemplate,
        private readonly CurrentConfig $currentConfig,
    ) {}

    /**
     * $imageId may have been resolved/overwritten from a URL-supplied
     * image file name before this call; the caller (PictureController)
     * passes its own local value directly, rather than this method reading
     * it from a shared registry.
     */
    /**
     * $picture is PictureController's own growing per-request display bag
     * (PictureController::$picture['current'] mixes a real SrcImage object
     * with dozens of incrementally-added scalar template fields, e.g.
     * TITLE/download_url/id/file/filesize/url) -- same "$page-shaped
     * accumulator" rationale as Search\SearchFilterRenderer's own $page,
     * not a single reusable domain shape.
     *
     * @param array<string, array<string, mixed>> $picture
     */
    public function render(int $imageId, UrlServiceInterface $urlService, array $picture, string $url_self): void
    {
        $template = $this->currentTemplate->get();

        if (! $this->currentConfig->rateEnabled) {
            return;
        }

        $rate_summary = [
            'count' => 0,
            'score' => $picture['current']['rating_score'],
            'average' => null,
        ];
        if ($rate_summary['score'] !== null) {
            // images.id is the NOT NULL primary key, always a numeric string
            // once fetched (see the matching assert in picture.php).
            $picture_current_id = $picture['current']['id'];
            assert(is_numeric($picture_current_id));

            $summary = $this->repo->findRateSummaryForElement(ImageId::from((int) $picture_current_id));
            $rate_summary['count'] = $summary->count;
            $rate_summary['average'] = $summary->average;
        }
        $template->assignContext(new PictureRateSummaryPageContext($rate_summary));

        $user_rate = null;
        if ($this->currentConfig->rateAnonymous or $this->accessControl->isAuthorizeStatus(AccessLevel::Classic)) {
            if ($rate_summary['count'] > 0) {
                $rate_image_id = ImageId::from($imageId);
                $rate_user_id = $this->currentUser->get()
                    ->id;

                $anonymous_id = null;
                if (! $this->accessControl->isAuthorizeStatus(AccessLevel::Classic)) {
                    $remote_addr = IpAddress::fromRemoteAddr()->value ?? '';
                    $ip_components = explode('.', $remote_addr);
                    if (count($ip_components) > 3) {
                        array_pop($ip_components);
                    }
                    $anonymous_id = implode('.', $ip_components);
                }

                $user_rate = $this->repo->findUserRate($rate_image_id, $rate_user_id, $anonymous_id);
            }

            $template->assignContext(new PictureRatingFormPageContext(
                formAction: $urlService->addUrlParams(
                    $url_self,
                    [
                        'action' => 'rate',
                    ]
                ),
                userRate: $user_rate,
                marks: $this->currentConfig->rateItems,
            ));
        }
    }
}
