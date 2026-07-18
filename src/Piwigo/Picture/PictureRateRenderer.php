<?php

declare(strict_types=1);

namespace Piwigo\Picture;

use Piwigo\Core\AccessLevel;
use Piwigo\Db\Tables;

/**
 * Renders the picture page's rating summary + rate form. Ported from
 * include/picture_rate.inc.php -- real rate-summary/user's-own-rate SQL,
 * not yet delegated to RateService/RateRepository (out of this fold's
 * scope; matches PictureMetadataRenderer/PictureCommentRenderer's own
 * "plain global-function/global-variable reads" shape, no constructor
 * deps).
 */
final class PictureRateRenderer
{
    public function render(): void
    {
        /**
         * @var array<string, mixed>
         */
        global $page;
        $template = \Piwigo\Template\CurrentTemplate::get();
        // Set by picture.php/PictureController, right before this call.
        /**
         * @var array<string, array<string, mixed>>
         */
        global $picture;
        /**
         * @var string
         */
        global $url_self;

        if (! \Piwigo\Config\Config::rateEnabled()) {
            return;
        }

        $rate_summary = [
            'count' => 0,
            'score' => $picture['current']['rating_score'],
            'average' => null,
        ];
        if ($rate_summary['score'] != null) {
            // images.id is the NOT NULL primary key, always a numeric string
            // once fetched (see \Piwigo\Db\MysqliDb::fetchAssoc()'s return type and the
            // matching assert in picture.php).
            $picture_current_id = $picture['current']['id'];
            assert(is_string($picture_current_id));

            $query = '
SELECT COUNT(rate) AS count
     , ROUND(AVG(rate),2) AS average
  FROM ' . Tables::rate() . '
  WHERE element_id = ' . $picture_current_id . '
;';
            $row = \Piwigo\Db\MysqliDb::fetchRow(\Piwigo\Db\MysqliDb::query($query));
            assert($row !== null);
            [$rate_summary['count'], $rate_summary['average']] = $row;
        }
        $template->assign('rate_summary', $rate_summary);

        $user_rate = null;
        if (\Piwigo\Config\Config::rateAnonymous() or \Piwigo\Auth\AccessControl::isAuthorizeStatus(AccessLevel::Classic)) {
            if ($rate_summary['count'] > 0) {
                // $page['image_id'] is always numeric (int or numeric
                // string) -- see the identical narrowing in picture.php.
                $rate_image_id = $page['image_id'];
                $rate_image_id = is_numeric($rate_image_id) ? (int) $rate_image_id : 0;
                $rate_user_id = \Piwigo\Users\CurrentUser::get()->id;

                $query = 'SELECT rate
      FROM ' . Tables::rate() . '
      WHERE element_id = ' . $rate_image_id . '
      AND user_id = ' . $rate_user_id;

                if (! \Piwigo\Auth\AccessControl::isAuthorizeStatus(AccessLevel::Classic)) {
                    $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';
                    $remote_addr = is_string($remote_addr) ? $remote_addr : '';
                    $ip_components = explode('.', $remote_addr);
                    if (count($ip_components) > 3) {
                        array_pop($ip_components);
                    }
                    $anonymous_id = implode('.', $ip_components);
                    $query .= ' AND anonymous_id = \'' . $anonymous_id . '\'';
                }

                $result = \Piwigo\Db\MysqliDb::query($query);
                if (\Piwigo\Db\MysqliDb::numRows($result) > 0) {
                    $row = \Piwigo\Db\MysqliDb::fetchAssoc($result);
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
                    'marks' => \Piwigo\Config\Config::rateItems(),
                ]
            );
        }
    }
}
