<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use Piwigo\Core\AccessLevel;
use Piwigo\Core\DateHelper;
use Piwigo\Core\WsError;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\SrcImage;

/**
 * P23 batch 8e: relocated verbatim from include/ws_functions.inc.php's 8
 * free functions -- shared helpers called from 2-4 of the
 * include/ws_functions/pwg.*.php namespace files each.
 */
final class WsHelper
{
    /**
     * Event handler for method invocation security check. Should return a PwgError
     * if the preconditions are not satifsied for method invocation.
     *
     * @param array<string, mixed> $params
     */
    public static function isInvokeAllowed(mixed $res, string $methodName, array $params): mixed
    {
        global $conf;

        if (str_starts_with($methodName, 'reflection.')) { // OK for reflection
            return $res;
        }

        if (! \Piwigo\Auth\AccessControl::isAuthorizeStatus(AccessLevel::Guest) and
            ! str_starts_with($methodName, 'pwg.session.')) {
            return new PwgError(401, 'Access denied');
        }

        return $res;
    }

    /**
     * returns a "standard" (for our web service) array of sql where clauses that
     * filters the images (images table only)
     *
     * Called from every WS method that merges ws.php's shared $f_params into
     * its registration (pwg.images.search, pwg.categories.getImages,
     * pwg.getMissingDerivatives, pwg.tags.getImages) -- all 11 f_* keys are
     * always present, per that shared registration block.
     *
     * @param array{f_min_rate: float|null, f_max_rate: float|null, f_min_hit: int|null, f_max_hit: int|null, f_min_ratio: float|null, f_max_ratio: float|null, f_max_level: int|null, f_min_date_available: string|null, f_max_date_available: string|null, f_min_date_created: string|null, f_max_date_created: string|null, ...} $params
     * @return list{0: non-falsy-string, 1?: non-falsy-string, 2?: non-falsy-string, 3?: non-falsy-string, 4?: non-falsy-string, 5?: non-falsy-string, 6?: non-falsy-string, 7?: non-falsy-string, 8?: non-falsy-string, 9?: non-falsy-string, 10?: non-falsy-string}|array{}
     */
    public static function stdImageSqlFilter(array $params, string $tbl_name = ''): array
    {
        foreach (['f_min_date_available', 'f_max_date_available', 'f_min_date_created', 'f_max_date_created'] as $datefield) {
            if (isset($params[$datefield]) and ! DateHelper::isValidMysqlDatetime($params[$datefield])) {
                /** @var PwgServer $service */
                global $service;
                $service->sendResponse(new PwgError(WsError::INVALID_PARAM, 'Invalid ' . $datefield));
                exit;
            }
        }

        $clauses = [];
        if (is_numeric($params['f_min_rate'])) {
            $clauses[] = $tbl_name . 'rating_score>=' . $params['f_min_rate'];
        }
        if (is_numeric($params['f_max_rate'])) {
            $clauses[] = $tbl_name . 'rating_score<=' . $params['f_max_rate'];
        }
        if (is_numeric($params['f_min_hit'])) {
            $clauses[] = $tbl_name . 'hit>=' . $params['f_min_hit'];
        }
        if (is_numeric($params['f_max_hit'])) {
            $clauses[] = $tbl_name . 'hit<=' . $params['f_max_hit'];
        }
        if (isset($params['f_min_date_available'])) {
            $clauses[] = $tbl_name . "date_available>='" . $params['f_min_date_available'] . "'";
        }
        if (isset($params['f_max_date_available'])) {
            $clauses[] = $tbl_name . "date_available<'" . $params['f_max_date_available'] . "'";
        }
        if (isset($params['f_min_date_created'])) {
            $clauses[] = $tbl_name . "date_creation>='" . $params['f_min_date_created'] . "'";
        }
        if (isset($params['f_max_date_created'])) {
            $clauses[] = $tbl_name . "date_creation<'" . $params['f_max_date_created'] . "'";
        }
        if (is_numeric($params['f_min_ratio'])) {
            $clauses[] = $tbl_name . 'width/' . $tbl_name . 'height>=' . $params['f_min_ratio'];
        }
        if (is_numeric($params['f_max_ratio'])) {
            $clauses[] = $tbl_name . 'width/' . $tbl_name . 'height<=' . $params['f_max_ratio'];
        }
        if (is_numeric($params['f_max_level'])) {
            $clauses[] = $tbl_name . 'level <= ' . $params['f_max_level'];
        }
        return $clauses;
    }

    /**
     * returns a "standard" (for our web service) ORDER BY sql clause for images
     *
     * @param array{order: string|null, ...} $params order has no WS_TYPE flag
     *   and a null default, but PwgServer::invoke() still guarantees a plain
     *   scalar (rejects arrays for any registered param lacking
     *   WsParamFlag::ACCEPT_ARRAY)
     */
    public static function stdImageSqlOrder(array $params, string $tbl_name = ''): string
    {
        $ret = '';
        $order = $params['order'];
        if ($order === null || $order === '' || $order === '0') {
            return $ret;
        }
        $matches = [];
        preg_match_all(
            '/([a-z_]+) *(?:(asc|desc)(?:ending)?)? *(?:, *|$)/i',
            $order,
            $matches
        );
        for ($i = 0; $i < count($matches[1]); $i++) {
            switch ($matches[1][$i]) {
                case 'date_created':
                    $matches[1][$i] = 'date_creation';
                    break;
                case 'date_posted':
                    $matches[1][$i] = 'date_available';
                    break;
                case 'rand': case 'random':
                    $matches[1][$i] = DB_RANDOM_FUNCTION . '()';
                    break;
            }
            $sortable_fields = ['id', 'file', 'name', 'hit', 'rating_score',
                'date_creation', 'date_available', DB_RANDOM_FUNCTION . '()'];
            if (in_array($matches[1][$i], $sortable_fields, true)) {
                if ($ret !== '') {
                    $ret .= ', ';
                }
                if ($matches[1][$i] !== DB_RANDOM_FUNCTION . '()') {
                    $ret .= $tbl_name;
                }
                $ret .= $matches[1][$i];
                $ret .= ' ' . $matches[2][$i];
            }
        }
        return $ret;
    }

    /**
     * returns an array map of urls (thumb/element) for image_row - to be returned
     * in a standard way by different web service methods
     *
     * @param array<string, mixed> $image_row
     * @return array<string, mixed>
     */
    public static function stdGetUrls(array $image_row): array
    {
        $ret = [];

        $ret['page_url'] = make_picture_url(
            [
                'image_id' => $image_row['id'],
                'image_file' => $image_row['file'],
            ]
        );

        $src_image = new SrcImage($image_row);

        $provide_download_url = false;

        if ($src_image->is_original()) {// we have a photo
            /** @var array<string, mixed> $user */
            global $user;
            if ((bool) $user['enabled_high']) {
                $ret['element_url'] = $src_image->get_url();
                $provide_download_url = true;
            }
        } else {
            $ret['element_url'] = get_element_url($image_row);
            $provide_download_url = true;
        }

        $ret['download_url'] = null;
        if ($provide_download_url) {
            $image_id = $image_row['id'];
            if (is_int($image_id) || is_string($image_id)) {
                $ret['download_url'] = get_action_url($image_id, 'e', true);
            }
        }

        $derivatives = DerivativeImage::get_all($src_image);
        $derivatives_arr = [];
        foreach ($derivatives as $type => $derivative) {
            $size = $derivative->get_size();
            if ($size === null) {
                $size = [null, null];
            }
            $derivatives_arr[$type] = [
                'url' => $derivative->get_url(),
                'width' => (int) $size[0],
                'height' => (int) $size[1],
            ];
        }
        $ret['derivatives'] = $derivatives_arr;
        return $ret;
    }

    /**
     * returns an array of image attributes that are to be encoded as xml attributes
     * instead of xml elements
     *
     * @return string[]
     */
    public static function stdGetImageXmlAttributes(): array
    {
        return [
            'id', 'element_url', 'page_url', 'file', 'width', 'height', 'hit', 'date_available', 'date_creation',
        ];
    }

    /**
     * @return string[]
     */
    public static function stdGetCategoryXmlAttributes(): array
    {
        return [
            'id', 'url', 'nb_images', 'total_nb_images', 'nb_categories', 'date_last', 'max_date_last', 'status',
        ];
    }

    /**
     * @return string[]
     */
    public static function stdGetTagXmlAttributes(): array
    {
        return [
            'id', 'name', 'url_name', 'counter', 'url', 'page_url',
        ];
    }

    /**
     * create a tree from a flat list of categories, no recursivity for high speed
     * @param array<int|string, array<string, mixed>> $categories
     * @return mixed[]
     */
    public static function categoriesFlatlistToTree(array $categories): array
    {
        $tree = [];
        $key_of_cat = [];

        foreach ($categories as $key => &$node) {
            $cat_id = $node['id'];
            if (! is_int($cat_id) && ! is_string($cat_id)) {
                // malformed category row (missing/non-scalar id) -- cannot be
                // indexed or attached to a parent, skip it
                continue;
            }
            $key_of_cat[$cat_id] = $key;

            if (! isset($node['id_uppercat'])) {
                $tree[] = &$node;
            } else {
                $uppercat_id = $node['id_uppercat'];
                if (! is_int($uppercat_id) && ! is_string($uppercat_id)) {
                    continue;
                }
                $uppercat_key = $key_of_cat[$uppercat_id];
                if (! isset($categories[$uppercat_key]['sub_categories'])) {
                    $categories[$uppercat_key]['sub_categories'] =
                      new PwgNamedArray([], 'category', self::stdGetCategoryXmlAttributes());
                }

                $sub_categories = $categories[$uppercat_key]['sub_categories'];
                if ($sub_categories instanceof PwgNamedArray) {
                    $sub_categories->_content[] = &$node;
                }
            }
        }

        return $tree;
    }
}
