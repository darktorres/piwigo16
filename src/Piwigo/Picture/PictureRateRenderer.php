<?php

declare(strict_types=1);

namespace Piwigo\Picture;

use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Rate\RateRepository;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;

final readonly class PictureRateRenderer
{
    public function __construct(
        private RateRepository $rateRepository,
        private PermissionService $permissionService,
        private UrlService $urlService,
    ) {
    }

    public function render(): void
    {
        if (!Config::rateEnabled()) {
            return;
        }

        $template = TemplateRegistry::current();
        $ctx = SectionContextRegistry::current();
        $picture = is_array($GLOBALS['picture'] ?? null) ? $GLOBALS['picture'] : [];
        $url_self = is_scalar($GLOBALS['url_self'] ?? null) ? (string) $GLOBALS['url_self'] : '';
        $current = is_array($picture['current'] ?? null) ? $picture['current'] : [];

        $rate_summary = [
            'count' => 0,
            'score' => $current['rating_score'] ?? null,
            'average' => null,
        ];
        if (null !== $rate_summary['score']) {
            [$rate_summary['count'], $rate_summary['average']] =
                $this->rateRepository
                    ->findCountAndAvgByElementId(is_numeric($current['id'] ?? null) ? (int) $current['id'] : 0);
        }
        $template->assign('rate_summary', $rate_summary);

        if (Config::rateAnonymous() or $this->permissionService->isAutorizeStatus(AccessLevel::Classic)) {
            if ($rate_summary['count'] > 0) {
                $imageId = is_numeric($ctx->imageId) ? (int) $ctx->imageId : 0;
                $anonId = null;
                if (!$this->permissionService->isAutorizeStatus(AccessLevel::Classic)) {
                    /** @var mixed $remoteAddrRaw */
                    $remoteAddrRaw = $_SERVER['REMOTE_ADDR'] ?? '';
                    $ip_components = explode('.', is_string($remoteAddrRaw) ? $remoteAddrRaw : '');
                    if (count($ip_components) > 3) {
                        array_pop($ip_components);
                    }
                    $anonId = implode('.', $ip_components);
                }
                $user_rate = $this->rateRepository
                    ->findRateByUserAndElement($imageId, CurrentUser::get()->id, $anonId);
            } else {
                $user_rate = null;
            }

            $template->assign('rating', [
                'F_ACTION' => $this->urlService->addUrlParams($url_self, ['action' => 'rate']),
                'USER_RATE' => $user_rate ?? null,
                'marks' => Config::rateItems(),
            ]);
        }
    }
}
