<?php

declare(strict_types=1);
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+
/**
 * @package functions\picture
 */
/**
 * Returns slideshow default params.
 * - period
 * - repeat
 * - play
 */
/** @return array<string, mixed> */
function get_default_slideshow_params(): array
{
    return [
      'period' => \Piwigo\Core\Config::slideshowPeriod(),
      'repeat' => \Piwigo\Core\Config::slideshowRepeat(),
      'play' => true,
      ];
}

/**
 * Checks and corrects slideshow params
 */
/**
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function correct_slideshow_params(array $params = []): array
{
    if ($params['period'] < \Piwigo\Core\Config::slideshowPeriodMin()) {
        $params['period'] = \Piwigo\Core\Config::slideshowPeriodMin();
    } elseif ($params['period'] > \Piwigo\Core\Config::slideshowPeriodMax()) {
        $params['period'] = \Piwigo\Core\Config::slideshowPeriodMax();
    }

    return $params;
}

/**
 * Decodes slideshow string params into array
 *
 * @param string $encode_params
 */
/** @return array<string,mixed> */
function decode_slideshow_params(?string $encode_params = null): array
{
    $result = get_default_slideshow_params();

    if (is_numeric($encode_params)) {
        $result['period'] = $encode_params;
    } else {
        $matches = [];
        if (preg_match_all('/([a-z]+)-(\d+)/', (string) $encode_params, $matches)) {
            $matchcount = count($matches[1]);
            for ($i = 0; $i < $matchcount; $i++) {
                $result[$matches[1][$i]] = $matches[2][$i];
            }
        }

        if (preg_match_all('/([a-z]+)-(true|false)/', (string) $encode_params, $matches)) {
            $matchcount = count($matches[1]);
            for ($i = 0; $i < $matchcount; $i++) {
                $result[$matches[1][$i]] = get_boolean($matches[2][$i]);
            }
        }
    }

    return correct_slideshow_params($result);
}

/**
 * Encodes slideshow array params into a string
 */
/** @param array<string,mixed> $decode_params */
function encode_slideshow_params(array $decode_params = []): string
{
    $corrected = correct_slideshow_params($decode_params);
    $default = get_default_slideshow_params();
    $result = '';

    foreach ($corrected as $name => $value) {
        if (array_key_exists($name, $default) && $default[$name] === $value) {
            continue;
        }
        $bool_val = is_bool($value) ? $value : (is_string($value) ? $value : '');
        $result .= '+'.$name.'-'.boolean_to_string($bool_val);
    }

    return $result;
}

/**
 * Increase the number of visits for a given photo.
 *
 * Code moved from picture.php to be used by both the API and picture.php
 *
 * @since 14
 * @param int $image_id
 */
function increase_image_visit_counter($image_id): void
{
    // avoiding auto update of "lastmodified" field
    $query = '
UPDATE
  '.IMAGES_TABLE.'
  SET hit = hit+1, lastmodified = lastmodified
  WHERE id = '.$image_id.'
;';
    pwg_query($query);
}

/**
 * Returns the number of pages of a PDF file
 *
 * @param string $pdfPath
 * @return int
 */
function count_pdf_pages($pdfPath): int|false
{
    $pdftext = file_get_contents($pdfPath);
    if ($pdftext === false) {
        return false;
    }
    $num = preg_match_all("/\/Page\W/", $pdftext, $dummy);

    return $num;
}
