<?php

declare(strict_types=1);

global $persistent_cache, $url_self, $picture, $related_categories, $comment_action;

use Piwigo\Template\TemplateRegistry;
use Piwigo\Users\CurrentUser;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * This file is included by the picture page to manage rates
 *
 */

if (\Piwigo\Config\Config::rateEnabled()) {
    $template = TemplateRegistry::current();
    $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];
    $rate_summary = [ 'count' => 0, 'score' => $picture['current']['rating_score'], 'average' => null ];
    if (null != $rate_summary['score']) {
        [$rate_summary['count'], $rate_summary['average']] =
            \Piwigo\Core\ServiceLocator::get(\Piwigo\Rate\RateRepository::class)
                ->findCountAndAvgByElementId(is_numeric($picture['current']['id'] ?? null) ? (int) $picture['current']['id'] : 0);
    }
    $template->assign('rate_summary', $rate_summary);

    $user_rate = null;
    if (\Piwigo\Config\Config::rateAnonymous() or is_autorize_status(ACCESS_CLASSIC)) {
        if ($rate_summary['count'] > 0) {
            $imageId = is_numeric($page['image_id'] ?? null) ? (int) $page['image_id'] : 0;
            $anonId = null;
            if (!is_autorize_status(ACCESS_CLASSIC)) {
                $ip_components = explode('.', is_scalar($_SERVER['REMOTE_ADDR'] ?? null) ? (string) $_SERVER['REMOTE_ADDR'] : '');
                if (count($ip_components) > 3) {
                    array_pop($ip_components);
                }
                $anonId = implode('.', $ip_components);
            }
            $user_rate = \Piwigo\Core\ServiceLocator::get(\Piwigo\Rate\RateRepository::class)
                ->findRateByUserAndElement($imageId, CurrentUser::get()->id, $anonId);
        }

        $template->assign(
            'rating',
            [
              'F_ACTION' => add_url_params(
                  $url_self,
                  ['action' => 'rate']
              ),
              'USER_RATE' => $user_rate,
              'marks'    => \Piwigo\Config\Config::rateItems(),
            ]
        );
    }
}
