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

use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if ($conf->rate) {
    $rate_summary = [
        'count' => 0,
        'score' => $picture['current']['rating_score'],
        'average' => null,
    ];

    if ($rate_summary['score'] != null) {
        $query = <<<SQL
            SELECT COUNT(rate) AS count, ROUND(AVG(rate), 2) AS average
            FROM rate
            WHERE element_id = {$picture['current']['id']};
            SQL;
        [$rate_summary['count'], $rate_summary['average']] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));
    }

    $template->assign('rate_summary', $rate_summary);

    $user_rate = null;

    if ($conf->rate_anonymous ||
        functions_user::is_authorized_status(ACCESS_CLASSIC)
    ) {
        if ($rate_summary['count'] > 0) {
            $query = <<<SQL
                SELECT rate
                FROM rate
                WHERE element_id = {$page['image_id']}
                    AND user_id = {$user['id']}

                SQL;

            if (! functions_user::is_authorized_status(ACCESS_CLASSIC)) {
                $ip_components = explode('.', $_SERVER['REMOTE_ADDR']);

                if (count($ip_components) > 3) {
                    array_pop($ip_components);
                }

                $anonymous_id = implode('.', $ip_components);
                $query .= <<<SQL
                    AND anonymous_id = '{$anonymous_id}'

                    SQL;
            }

            $query = trim($query) . ';';
            $result = $conf->sql_backend::pwg_query($query);

            if ($conf->sql_backend::pwg_db_num_rows($result) > 0) {
                $row = $conf->sql_backend::pwg_db_fetch_assoc($result);
                $user_rate = $row['rate'];
            }
        }

        $template->assign(
            'rating',
            [
                'F_ACTION' => functions_url::add_url_params(
                    $url_self,
                    [
                        'action' => 'rate',
                    ]
                ),
                'USER_RATE' => $user_rate,
                'marks' => $conf->rate_items,
            ]
        );
    }
}
