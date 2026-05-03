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
        $query = '
SELECT COUNT(rate) AS count
     , ROUND(AVG(rate),2) AS average
  FROM '.RATE_TABLE.'
  WHERE element_id = '.$picture['current']['id'].'
;';
        [$rate_summary['count'], $rate_summary['average']] = pwg_db_fetch_row(pwg_query($query)) ?? [null, null];
    }
    $template->assign('rate_summary', $rate_summary);

    $user_rate = null;
    if (\Piwigo\Config\Config::rateAnonymous() or is_autorize_status(ACCESS_CLASSIC)) {
        if ($rate_summary['count'] > 0) {
            $imageId = is_numeric($page['image_id'] ?? null) ? (int) $page['image_id'] : 0;
            $query = 'SELECT rate
      FROM '.RATE_TABLE.'
      WHERE element_id = '.$imageId . '
      AND user_id = '.CurrentUser::get()->id ;

            if (!is_autorize_status(ACCESS_CLASSIC)) {
                $ip_components = explode('.', is_scalar($_SERVER['REMOTE_ADDR'] ?? null) ? (string) $_SERVER['REMOTE_ADDR'] : '');
                if (count($ip_components) > 3) {
                    array_pop($ip_components);
                }
                $anonymous_id = implode('.', $ip_components);
                $query .= ' AND anonymous_id = \''.$anonymous_id . '\'';
            }

            $result = pwg_query($query);
            if (pwg_db_num_rows($result) > 0) {
                $row = pwg_db_fetch_assoc($result);
                $user_rate = $row['rate'] ?? null;
            }
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
