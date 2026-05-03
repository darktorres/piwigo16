<?php

declare(strict_types=1);

use Piwigo\Image\DerivativeImage;
use Piwigo\Image\SrcImage;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * Event handler for method invocation security check. Should return a PwgError
 * if the preconditions are not satifsied for method invocation.
 */
/** @param array<mixed> $params */
function ws_isInvokeAllowed(mixed $res, string $methodName, array $params): mixed
{
    if (str_starts_with((string) $methodName, 'reflection.')) { // OK for reflection
        return $res;
    }

    if (!is_autorize_status(ACCESS_GUEST) and
        !str_starts_with((string) $methodName, 'pwg.session.')) {
        return new PwgError(401, 'Access denied');
    }

    return $res;
}

/**
 * returns a "standard" (for our web service) array of sql where clauses that
 * filters the images (images table only)
 * @return array{}|list{0: non-falsy-string, 1?: non-falsy-string, 2?: non-falsy-string, 3?: non-falsy-string, 4?: non-falsy-string, 5?: non-falsy-string, 6?: non-falsy-string, 7?: non-falsy-string, 8?: non-falsy-string, 9?: non-falsy-string, 10?: non-falsy-string}
 */
/**
 * @param array<mixed> $params
 * @return array<mixed>
 */
function ws_std_image_sql_filter(array $params, string $tbl_name = ''): array
{
    foreach (['f_min_date_available', 'f_max_date_available', 'f_min_date_created', 'f_max_date_created'] as $datefield) {
        if (isset($params[$datefield]) and !is_valid_mysql_datetime(is_scalar($params[$datefield]) ? (string) $params[$datefield] : '')) {
            global $service;
            $service->sendResponse(new PwgError(WS_ERR_INVALID_PARAM, 'Invalid '.$datefield));
            exit;
        }
    }

    $clauses = [];
    if (is_numeric($params['f_min_rate'])) {
        $clauses[] = $tbl_name.'rating_score>='.$params['f_min_rate'];
    }
    if (is_numeric($params['f_max_rate'])) {
        $clauses[] = $tbl_name.'rating_score<='.$params['f_max_rate'];
    }
    if (is_numeric($params['f_min_hit'])) {
        $clauses[] = $tbl_name.'hit>='.$params['f_min_hit'];
    }
    if (is_numeric($params['f_max_hit'])) {
        $clauses[] = $tbl_name.'hit<='.$params['f_max_hit'];
    }
    if (isset($params['f_min_date_available'])) {
        $clauses[] = $tbl_name."date_available>='". (is_scalar($params['f_min_date_available']) ? (string) $params['f_min_date_available'] : '') ."'";
    }
    if (isset($params['f_max_date_available'])) {
        $clauses[] = $tbl_name."date_available<'". (is_scalar($params['f_max_date_available']) ? (string) $params['f_max_date_available'] : '') ."'";
    }
    if (isset($params['f_min_date_created'])) {
        $clauses[] = $tbl_name."date_creation>='". (is_scalar($params['f_min_date_created']) ? (string) $params['f_min_date_created'] : '') ."'";
    }
    if (isset($params['f_max_date_created'])) {
        $clauses[] = $tbl_name."date_creation<'". (is_scalar($params['f_max_date_created']) ? (string) $params['f_max_date_created'] : '') ."'";
    }
    if (is_numeric($params['f_min_ratio'])) {
        $clauses[] = $tbl_name.'width/'.$tbl_name.'height>='.$params['f_min_ratio'];
    }
    if (is_numeric($params['f_max_ratio'])) {
        $clauses[] = $tbl_name.'width/'.$tbl_name.'height<='.$params['f_max_ratio'];
    }
    if (is_numeric($params['f_max_level'])) {
        $clauses[] = $tbl_name.'level <= '.$params['f_max_level'];
    }
    return $clauses;
}

/**
 * returns a "standard" (for our web service) ORDER BY sql clause for images
 */
/** @param array<mixed> $params */
function ws_std_image_sql_order(array $params, string $tbl_name = ''): string
{
    $ret = '';
    if (empty($params['order'])) {
        return $ret;
    }
    $matches = [];
    preg_match_all(
        '/([a-z_]+) *(?:(asc|desc)(?:ending)?)? *(?:, *|$)/i',
        is_scalar($params['order']) ? (string) $params['order'] : '',
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
                $matches[1][$i] = DB_RANDOM_FUNCTION.'()';
                break;
        }
        $sortable_fields = ['id', 'file', 'name', 'hit', 'rating_score',
          'date_creation', 'date_available', DB_RANDOM_FUNCTION.'()' ];
        if (in_array($matches[1][$i], $sortable_fields)) {
            if (!empty($ret)) {
                $ret .= ', ';
            }
            if ($matches[1][$i] != DB_RANDOM_FUNCTION.'()') {
                $ret .= $tbl_name;
            }
            $ret .= $matches[1][$i];
            $ret .= ' '.$matches[2][$i];
        }
    }
    return $ret;
}

/**
 * returns an array map of urls (thumb/element) for image_row - to be returned
 * in a standard way by different web service methods
 */
/**
 * @param array<string,mixed> $image_row
 * @return array<mixed>
 */
function ws_std_get_urls(array $image_row): array
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
        if (\Piwigo\Users\CurrentUser::get()->enabledHigh) {
            $ret['element_url'] = $src_image->get_url();
            $provide_download_url = true;
        }
    } else {
        $ret['element_url'] = get_element_url($image_row);
        $provide_download_url = true;
    }

    $ret['download_url'] = null;
    if ($provide_download_url) {
        $ret['download_url'] = get_action_url(is_int($image_row['id']) || is_string($image_row['id']) ? $image_row['id'] : 0, 'e', true);
    }

    $derivatives = DerivativeImage::get_all($src_image);
    $derivatives_arr = [];
    foreach ($derivatives as $type => $derivative) {
        if (!($derivative instanceof DerivativeImage)) {
            continue;
        }
        $size = $derivative->get_size();
        if ($size === null) {
            $size = [null, null];
        }
        $derivatives_arr[$type] = ['url' => $derivative->get_url(), 'width' => (int)$size[0], 'height' => (int)$size[1] ];
    }
    $ret['derivatives'] = $derivatives_arr;
    ;
    return $ret;
}

/**
 * returns an array of image attributes that are to be encoded as xml attributes
 * instead of xml elements
 */
/** @return string[] */
function ws_std_get_image_xml_attributes(): array
{
    return [
      'id','element_url', 'page_url', 'file','width','height','hit','date_available','date_creation',
      ];
}

/** @return string[] */
function ws_std_get_category_xml_attributes(): array
{
    return [
      'id', 'url', 'nb_images', 'total_nb_images', 'nb_categories', 'date_last', 'max_date_last', 'status',
      ];
}

/** @return string[] */
function ws_std_get_tag_xml_attributes(): array
{
    return [
      'id', 'name', 'url_name', 'counter', 'url', 'page_url',
      ];
}

/**
 * create a tree from a flat list of categories, no recursivity for high speed
 * @param array<array<string,mixed>> $categories
 * @return array<mixed>
 */
function categories_flatlist_to_tree(array $categories): array
{
    $tree = [];
    $key_of_cat = [];

    foreach ($categories as $key => &$node) {
        $node_id_raw = $node['id'] ?? null;
        $node_id = is_int($node_id_raw) || is_string($node_id_raw) ? $node_id_raw : null;
        if ($node_id === null) {
            continue;
        }
        $key_of_cat[$node_id] = $key;

        if (!isset($node['id_uppercat'])) {
            $tree[] = &$node;
        } else {
            $upper_id_raw = $node['id_uppercat'];
            $upper_id = is_int($upper_id_raw) || is_string($upper_id_raw) ? $upper_id_raw : null;
            if ($upper_id === null || !isset($key_of_cat[$upper_id])) {
                continue;
            }
            $parent_key = $key_of_cat[$upper_id];
            if (!isset($categories[$parent_key]['sub_categories'])) {
                $categories[$parent_key]['sub_categories'] =
                  new PwgNamedArray([], 'category', ws_std_get_category_xml_attributes());
            }

            $sub = $categories[$parent_key]['sub_categories'];
            if ($sub instanceof PwgNamedArray) {
                $sub->appendItem($node);
            }
        }
    }

    return $tree;
}
