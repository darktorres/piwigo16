<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

use Piwigo\inc\dblayer\functions_mysqli;

class functions_picture
{
    /**
     * Returns slideshow default params.
     * - period
     * - repeat
     * - play
     */
    public static function get_default_slideshow_params(): array
    {
        global $conf;

        return [
            'period' => $conf['slideshow_period'],
            'repeat' => $conf['slideshow_repeat'],
            'play' => true,
        ];
    }

    /**
     * Checks and corrects slideshow params
     */
    public static function correct_slideshow_params(
        array $params = []
    ): array {
        global $conf;

        if ($params['period'] < $conf['slideshow_period_min']) {
            $params['period'] = $conf['slideshow_period_min'];
        } elseif ($params['period'] > $conf['slideshow_period_max']) {
            $params['period'] = $conf['slideshow_period_max'];
        }

        return $params;
    }

    /**
     * Decodes slideshow string params into array
     */
    public static function decode_slideshow_params(
        ?string $encode_params = null
    ): array {
        global $conf;

        $result = self::get_default_slideshow_params();

        if (is_numeric($encode_params)) {
            $result['period'] = $encode_params;
        } else {
            $matches = [];

            if (preg_match_all('/([a-z]+)-(\d+)/', $encode_params, $matches)) {
                $matchcount = count($matches[1]);

                for ($i = 0; $i < $matchcount; $i++) {
                    $result[$matches[1][$i]] = $matches[2][$i];
                }
            }

            if (preg_match_all('/([a-z]+)-(true|false)/', $encode_params, $matches)) {
                $matchcount = count($matches[1]);

                for ($i = 0; $i < $matchcount; $i++) {
                    $result[$matches[1][$i]] = functions_mysqli::get_boolean($matches[2][$i]);
                }
            }
        }

        return self::correct_slideshow_params($result);
    }

    /**
     * Encodes slideshow array params into a string
     */
    public static function encode_slideshow_params(
        array $decode_params = []
    ): string {
        global $conf;

        $params = array_diff_assoc(self::correct_slideshow_params($decode_params), self::get_default_slideshow_params());
        $result = '';

        foreach ($params as $name => $value) {
            // boolean_to_string return $value, if it's not a bool
            $result .= '+' . $name . '-' . functions_mysqli::boolean_to_string($value);
        }

        return $result;
    }

    /**
     * Increase the number of visits for a given photo.
     *
     * Code moved from picture.php to be used by both the API and picture.php
     */
    public static function increase_image_visit_counter(
        int|string $image_id
    ): void {
        // avoiding auto update of "lastmodified" field
        $query = <<<SQL
            UPDATE images
            SET hit = hit + 1, lastmodified = lastmodified
            WHERE id = {$image_id};
            SQL;
        functions_mysqli::pwg_query($query);
    }
}
