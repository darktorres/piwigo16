<?php

declare(strict_types=1);

namespace Piwigo\Picture;

use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\ServiceLocator;
use Piwigo\Rate\RateRepository;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;

final class PictureRateRenderer
{
    public function render(): void
    {
        if (!Config::rateEnabled()) {
            return;
        }

        $template = TemplateRegistry::current();
        $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];
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
                ServiceLocator::get(RateRepository::class)
                    ->findCountAndAvgByElementId(is_numeric($current['id'] ?? null) ? (int) $current['id'] : 0);
        }
        $template->assign('rate_summary', $rate_summary);

        if (Config::rateAnonymous() or PermissionService::get()->isAutorizeStatus(AccessLevel::Classic)) {
            if ($rate_summary['count'] > 0) {
                $imageId = is_numeric($page['image_id'] ?? null) ? (int) $page['image_id'] : 0;
                $anonId = null;
                if (!PermissionService::get()->isAutorizeStatus(AccessLevel::Classic)) {
                    /** @var mixed $remoteAddrRaw */
                    $remoteAddrRaw = $_SERVER['REMOTE_ADDR'] ?? '';
                    $ip_components = explode('.', is_string($remoteAddrRaw) ? $remoteAddrRaw : '');
                    if (count($ip_components) > 3) {
                        array_pop($ip_components);
                    }
                    $anonId = implode('.', $ip_components);
                }
                $user_rate = ServiceLocator::get(RateRepository::class)
                    ->findRateByUserAndElement($imageId, CurrentUser::get()->id, $anonId);
            } else {
                $user_rate = null;
            }

            $template->assign('rating', [
                'F_ACTION' => UrlService::get()->addUrlParams($url_self, ['action' => 'rate']),
                'USER_RATE' => $user_rate ?? null,
                'marks' => Config::rateItems(),
            ]);
        }
    }
}
