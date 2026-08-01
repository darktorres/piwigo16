<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use Doctrine\DBAL\ParameterType;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\DateHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\WsError;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\SrcImage;
use Piwigo\Permission\SqlCondition;

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
     * $res/return genuinely mixed by design: this is a plugin-style
     * EventDispatcher filter handler, so $res is whatever the previous
     * filter-chain step produced -- same contract as
     * PluginConfig\EventDispatcher's own triggerChange() handlers.
     *
     * @param array<string, mixed> $params
     */
    public static function isInvokeAllowed(mixed $res, string $methodName, array $params): mixed
    {

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
     * returns a "standard" (for our web service) sql condition that
     * filters the images (images table only)
     *
     * Called from every WS method that merges ws.php's shared $f_params into
     * its registration (pwg.images.search, pwg.categories.getImages,
     * pwg.getMissingDerivatives, pwg.tags.getImages) -- all 11 f_* keys are
     * always present, per that shared registration block.
     *
     * SQL-modernization audit: every f_* value used to splice raw into the
     * returned clause strings -- not currently exploitable (f_min_rate/
     * f_max_rate/f_min_hit/f_max_hit/f_min_ratio/f_max_ratio/f_max_level
     * are all gated by is_numeric(), and the 4 date fields are validated
     * above via DateHelper::isValidMysqlDatetime(), which round-trips
     * through DateTime::createFromFormat() and can never let a
     * non-digit/hyphen/space/colon character through) -- but converted
     * regardless, per this initiative's "regardless of exploitability"
     * stance. Returns a single SqlCondition (its own internal clauses
     * ANDed together, same "one more bound fragment for the caller's own
     * $where_clauses list" shape as PermissionService::
     * getSqlConditionFandFAsCondition()) instead of a raw clause list, so
     * every real caller composes it the same way.
     *
     * @param array{f_min_rate: float|null, f_max_rate: float|null, f_min_hit: int|null, f_max_hit: int|null, f_min_ratio: float|null, f_max_ratio: float|null, f_max_level: int|null, f_min_date_available: string|null, f_max_date_available: string|null, f_min_date_created: string|null, f_max_date_created: string|null, ...} $params
     */
    public static function stdImageSqlFilter(array $params, PwgServer $service, string $tbl_name = ''): SqlCondition
    {
        foreach (['f_min_date_available', 'f_max_date_available', 'f_min_date_created', 'f_max_date_created'] as $datefield) {
            if (isset($params[$datefield]) and ! DateHelper::isValidMysqlDatetime($params[$datefield])) {
                $service->sendResponse(new PwgError(WsError::INVALID_PARAM, 'Invalid ' . $datefield));
                exit;
            }
        }

        $suffix = self::nextPlaceholderSuffix();
        $clauses = [];
        $parameters = [];
        $types = [];

        if (is_numeric($params['f_min_rate'])) {
            $clauses[] = $tbl_name . 'rating_score >= :f_min_rate' . $suffix;
            $parameters['f_min_rate' . $suffix] = $params['f_min_rate'];
        }
        if (is_numeric($params['f_max_rate'])) {
            $clauses[] = $tbl_name . 'rating_score <= :f_max_rate' . $suffix;
            $parameters['f_max_rate' . $suffix] = $params['f_max_rate'];
        }
        if (is_numeric($params['f_min_hit'])) {
            $clauses[] = $tbl_name . 'hit >= :f_min_hit' . $suffix;
            $parameters['f_min_hit' . $suffix] = $params['f_min_hit'];
            $types['f_min_hit' . $suffix] = ParameterType::INTEGER;
        }
        if (is_numeric($params['f_max_hit'])) {
            $clauses[] = $tbl_name . 'hit <= :f_max_hit' . $suffix;
            $parameters['f_max_hit' . $suffix] = $params['f_max_hit'];
            $types['f_max_hit' . $suffix] = ParameterType::INTEGER;
        }
        if (isset($params['f_min_date_available'])) {
            $clauses[] = $tbl_name . 'date_available >= :f_min_date_available' . $suffix;
            $parameters['f_min_date_available' . $suffix] = $params['f_min_date_available'];
        }
        if (isset($params['f_max_date_available'])) {
            $clauses[] = $tbl_name . 'date_available < :f_max_date_available' . $suffix;
            $parameters['f_max_date_available' . $suffix] = $params['f_max_date_available'];
        }
        if (isset($params['f_min_date_created'])) {
            $clauses[] = $tbl_name . 'date_creation >= :f_min_date_created' . $suffix;
            $parameters['f_min_date_created' . $suffix] = $params['f_min_date_created'];
        }
        if (isset($params['f_max_date_created'])) {
            $clauses[] = $tbl_name . 'date_creation < :f_max_date_created' . $suffix;
            $parameters['f_max_date_created' . $suffix] = $params['f_max_date_created'];
        }
        if (is_numeric($params['f_min_ratio'])) {
            $clauses[] = $tbl_name . 'width/' . $tbl_name . 'height >= :f_min_ratio' . $suffix;
            $parameters['f_min_ratio' . $suffix] = $params['f_min_ratio'];
        }
        if (is_numeric($params['f_max_ratio'])) {
            $clauses[] = $tbl_name . 'width/' . $tbl_name . 'height <= :f_max_ratio' . $suffix;
            $parameters['f_max_ratio' . $suffix] = $params['f_max_ratio'];
        }
        if (is_numeric($params['f_max_level'])) {
            $clauses[] = $tbl_name . 'level <= :f_max_level' . $suffix;
            $parameters['f_max_level' . $suffix] = $params['f_max_level'];
            $types['f_max_level' . $suffix] = ParameterType::INTEGER;
        }

        return new SqlCondition($clauses === [] ? '' : '(' . implode(' AND ', $clauses) . ')', $parameters, $types);
    }

    /**
     * Monotonic per-process suffix for stdImageSqlFilter()'s placeholder
     * names -- same "static local, not a class property" shape as
     * PermissionService::nextPlaceholderSuffix(), and for the same
     * reason: real callers can combine this with other bound fragments
     * (getSqlConditionFandFAsCondition(), a caller's own `id IN (...)`)
     * in one query, and Doctrine only supports one binding per named
     * placeholder per query.
     */
    private static function nextPlaceholderSuffix(): string
    {
        /** @var int */
        static $counter = 0;

        return '_' . $counter++;
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
                    $matches[1][$i] = \Piwigo\Db\SqlDialect::DB_RANDOM_FUNCTION . '()';
                    break;
            }
            $sortable_fields = ['id', 'file', 'name', 'hit', 'rating_score',
                'date_creation', 'date_available', \Piwigo\Db\SqlDialect::DB_RANDOM_FUNCTION . '()'];
            if (in_array($matches[1][$i], $sortable_fields, true)) {
                if ($ret !== '') {
                    $ret .= ', ';
                }
                if ($matches[1][$i] !== \Piwigo\Db\SqlDialect::DB_RANDOM_FUNCTION . '()') {
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
     * $image_row is genuinely arbitrary by design (built into a SrcImage
     * below, the same cross-domain generic-row-reader shape SrcImage's own
     * docblock documents across its ~17 real construction sites).
     *
     * @param array<string, mixed> $image_row
     * @return array{page_url: string, element_url?: string, download_url: ?string, derivatives: array<string, array{url: string, width: int, height: int}>}
     */
    public static function stdGetUrls(array $image_row, UrlServiceInterface $urlService): array
    {
        $ret = [];

        $ret['page_url'] = $urlService->makePictureUrl(
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
            $ret['element_url'] = $urlService->getElementUrl($image_row);
            $provide_download_url = true;
        }

        $ret['download_url'] = null;
        if ($provide_download_url) {
            $image_id = $image_row['id'];
            if (is_int($image_id) || is_string($image_id)) {
                $ret['download_url'] = $urlService->getActionUrl($image_id, 'e', true);
            }
        }

        $derivatives = DerivativeImage::get_all($src_image);
        $derivatives_arr = [];
        foreach ($derivatives as $type => $derivative) {
            $size = $derivative->get_size();
            if ($size === null) {
                $size = [null, null];
            }
            $derivatives_arr[(string) $type] = [
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
     *
     * Each $categories row is genuinely arbitrary by design (a category
     * row, dynamically augmented in place with a 'sub_categories'
     * PwgNamedArray) -- same rationale as AlbumsPageRenderer's own
     * dynamically-built tree.
     *
     * @param array<int|string, array<string, mixed>> $categories
     * @return list<array<string, mixed>>
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
