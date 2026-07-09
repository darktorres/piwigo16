<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * This file is included by the picture page to manage rates
 */

// Bootstrap globals, set by include/common.inc.php.
global $conf, $page, $template, $user;
// Set by picture.php, right before this include.
global $picture, $url_self;

if ($conf['rate']) {
    $rate_summary = [
        'count' => 0,
        'score' => $picture['current']['rating_score'],
        'average' => null,
    ];
    if ($rate_summary['score'] != null) {
        $query = '
SELECT COUNT(rate) AS count
     , ROUND(AVG(rate),2) AS average
  FROM ' . RATE_TABLE . '
  WHERE element_id = ' . $picture['current']['id'] . '
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$rate_summary['count'], $rate_summary['average']] = $row;
    }
    $template->assign('rate_summary', $rate_summary);

    $user_rate = null;
    if ($conf['rate_anonymous'] or is_autorize_status(ACCESS_CLASSIC)) {
        if ($rate_summary['count'] > 0) {
            $query = 'SELECT rate
      FROM ' . RATE_TABLE . '
      WHERE element_id = ' . $page['image_id'] . '
      AND user_id = ' . $user['id'];

            if (! is_autorize_status(ACCESS_CLASSIC)) {
                $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';
                $remote_addr = is_string($remote_addr) ? $remote_addr : '';
                $ip_components = explode('.', $remote_addr);
                if (count($ip_components) > 3) {
                    array_pop($ip_components);
                }
                $anonymous_id = implode('.', $ip_components);
                $query .= ' AND anonymous_id = \'' . $anonymous_id . '\'';
            }

            $result = pwg_query($query);
            if (pwg_db_num_rows($result) > 0) {
                $row = pwg_db_fetch_assoc($result);
                assert(is_array($row));
                $user_rate = $row['rate'];
            }
        }

        $template->assign(
            'rating',
            [
                'F_ACTION' => add_url_params(
                    $url_self,
                    [
                        'action' => 'rate',
                    ]
                ),
                'USER_RATE' => $user_rate,
                'marks' => $conf['rate_items'],
            ]
        );
    }
}
