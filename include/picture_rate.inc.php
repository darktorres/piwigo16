<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Core\AccessLevel;
use Piwigo\Db\Tables;
use Piwigo\Template\Template;

/**
 * This file is included by the picture page to manage rates
 */

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $page
 * @var Template $template
 * @var array<string, mixed> $user
 */
global $conf, $page, $template, $user;
// Set by picture.php, right before this include.
/**
 * @var array<string, array<string, mixed>> $picture
 * @var string $url_self
 */
global $picture, $url_self;

if ((bool) $conf['rate']) {
    $rate_summary = [
        'count' => 0,
        'score' => $picture['current']['rating_score'],
        'average' => null,
    ];
    if ($rate_summary['score'] != null) {
        // images.id is the NOT NULL primary key, always a numeric string
        // once fetched (see pwg_db_fetch_assoc()'s return type and the
        // matching assert in picture.php).
        $picture_current_id = $picture['current']['id'];
        assert(is_string($picture_current_id));

        $query = '
SELECT COUNT(rate) AS count
     , ROUND(AVG(rate),2) AS average
  FROM ' . Tables::rate() . '
  WHERE element_id = ' . $picture_current_id . '
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$rate_summary['count'], $rate_summary['average']] = $row;
    }
    $template->assign('rate_summary', $rate_summary);

    $user_rate = null;
    if ((bool) $conf['rate_anonymous'] or is_autorize_status(AccessLevel::Classic)) {
        if ($rate_summary['count'] > 0) {
            // $page['image_id'] / $user['id'] are always numeric (int or
            // numeric string) -- see the identical narrowing in picture.php.
            $rate_image_id = $page['image_id'];
            $rate_image_id = is_numeric($rate_image_id) ? (int) $rate_image_id : 0;
            $rate_user_id = $user['id'];
            $rate_user_id = is_numeric($rate_user_id) ? (int) $rate_user_id : 0;

            $query = 'SELECT rate
      FROM ' . Tables::rate() . '
      WHERE element_id = ' . $rate_image_id . '
      AND user_id = ' . $rate_user_id;

            if (! is_autorize_status(AccessLevel::Classic)) {
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
