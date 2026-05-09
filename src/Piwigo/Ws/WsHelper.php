<?php

declare(strict_types=1);

namespace Piwigo\Ws;

use Piwigo\Core\AccessLevel;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;
use Piwigo\Db\Dml;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\SrcImage;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;

final class WsHelper
{
    /** @param array<mixed> $params */
    public function isInvokeAllowed(mixed $res, string $methodName, array $params): mixed
    {
        if (str_starts_with($methodName, 'reflection.')) {
            return $res;
        }

        if (!PermissionService::get()->isAutorizeStatus(AccessLevel::Guest) &&
            !str_starts_with($methodName, 'pwg.session.')) {
            return new PwgError(401, 'Access denied');
        }

        return $res;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public function imageSqlFilter(array $params, string $tbl_name = ''): array
    {
        foreach (['f_min_date_available', 'f_max_date_available', 'f_min_date_created', 'f_max_date_created'] as $datefield) {
            if (isset($params[$datefield]) && !ServiceLocator::get(StringUtil::class)->isValidMysqlDatetime(is_scalar($params[$datefield]) ? (string) $params[$datefield] : '')) {
                PwgServerRegistry::current()->sendResponse(new PwgError(WS_ERR_INVALID_PARAM, 'Invalid ' . $datefield));
                exit;
            }
        }

        $clauses = [];
        if (is_numeric($params['f_min_rate'])) {
            $clauses[] = $tbl_name . 'rating_score>=' . (string) $params['f_min_rate'];
        }
        if (is_numeric($params['f_max_rate'])) {
            $clauses[] = $tbl_name . 'rating_score<=' . (string) $params['f_max_rate'];
        }
        if (is_numeric($params['f_min_hit'])) {
            $clauses[] = $tbl_name . 'hit>=' . (string) $params['f_min_hit'];
        }
        if (is_numeric($params['f_max_hit'])) {
            $clauses[] = $tbl_name . 'hit<=' . (string) $params['f_max_hit'];
        }
        if (isset($params['f_min_date_available'])) {
            $clauses[] = $tbl_name . "date_available>='" . (is_string($params['f_min_date_available']) ? $params['f_min_date_available'] : '') . "'";
        }
        if (isset($params['f_max_date_available'])) {
            $clauses[] = $tbl_name . "date_available<'" . (is_string($params['f_max_date_available']) ? $params['f_max_date_available'] : '') . "'";
        }
        if (isset($params['f_min_date_created'])) {
            $clauses[] = $tbl_name . "date_creation>='" . (is_string($params['f_min_date_created']) ? $params['f_min_date_created'] : '') . "'";
        }
        if (isset($params['f_max_date_created'])) {
            $clauses[] = $tbl_name . "date_creation<'" . (is_string($params['f_max_date_created']) ? $params['f_max_date_created'] : '') . "'";
        }
        if (is_numeric($params['f_min_ratio'])) {
            $clauses[] = $tbl_name . 'width/' . $tbl_name . 'height>=' . (string) $params['f_min_ratio'];
        }
        if (is_numeric($params['f_max_ratio'])) {
            $clauses[] = $tbl_name . 'width/' . $tbl_name . 'height<=' . (string) $params['f_max_ratio'];
        }
        if (is_numeric($params['f_max_level'])) {
            $clauses[] = $tbl_name . 'level <= ' . (string) $params['f_max_level'];
        }
        return $clauses;
    }

    /** @param array<mixed> $params */
    public function imageSqlOrder(array $params, string $tbl_name = ''): string
    {
        $ret = '';
        if (empty($params['order'])) {
            return $ret;
        }
        $matches = [];
        preg_match_all(
            '/([a-z_]+) *(?:(asc|desc)(?:ending)?)? *(?:, *|$)/i',
            is_string($params['order']) ? $params['order'] : '',
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
                    $matches[1][$i] = Dml::RANDOM_FUNCTION . '()';
                    break;
            }
            $sortable_fields = ['id', 'file', 'name', 'hit', 'rating_score', 'date_creation', 'date_available', Dml::RANDOM_FUNCTION . '()'];
            if (in_array($matches[1][$i], $sortable_fields)) {
                if (!empty($ret)) {
                    $ret .= ', ';
                }
                if ($matches[1][$i] != Dml::RANDOM_FUNCTION . '()') {
                    $ret .= $tbl_name;
                }
                $ret .= $matches[1][$i];
                $ret .= ' ' . $matches[2][$i];
            }
        }
        return $ret;
    }

    /**
     * @param array<string, mixed> $image_row
     * @return array<mixed>
     */
    public function getUrls(array $image_row): array
    {
        $ret = [];

        $ret['page_url'] = UrlService::get()->makePictureUrl([
            'image_id' => $image_row['id'],
            'image_file' => $image_row['file'],
        ]);

        $src_image = new SrcImage($image_row);
        $provide_download_url = false;

        if ($src_image->isOriginal()) {
            if (CurrentUser::get()->enabledHigh) {
                $ret['element_url'] = $src_image->getUrl();
                $provide_download_url = true;
            }
        } else {
            $ret['element_url'] = UrlService::get()->getElementUrl($image_row);
            $provide_download_url = true;
        }

        $ret['download_url'] = null;
        if ($provide_download_url) {
            $ret['download_url'] = UrlService::get()->getActionUrl(is_int($image_row['id']) || is_string($image_row['id']) ? $image_row['id'] : 0, 'e', true);
        }

        $derivatives = DerivativeImage::getAll($src_image);
        $derivatives_arr = [];
        foreach ($derivatives as $type => $derivative) {
            if (!($derivative instanceof DerivativeImage)) {
                continue;
            }
            $size = $derivative->getSize();
            if ($size === null) {
                $size = [null, null];
            }
            $derivatives_arr[$type] = ['url' => $derivative->getUrl(), 'width' => (int) $size[0], 'height' => (int) $size[1]];
        }
        $ret['derivatives'] = $derivatives_arr;

        return $ret;
    }

    /** @return string[] */
    public function getImageXmlAttributes(): array
    {
        return ['id', 'element_url', 'page_url', 'file', 'width', 'height', 'hit', 'date_available', 'date_creation'];
    }

    /** @return string[] */
    public function getCategoryXmlAttributes(): array
    {
        return ['id', 'url', 'nb_images', 'total_nb_images', 'nb_categories', 'date_last', 'max_date_last', 'status'];
    }

    /** @return string[] */
    public function getTagXmlAttributes(): array
    {
        return ['id', 'name', 'url_name', 'counter', 'url', 'page_url'];
    }

    /**
     * @param array<array<string, mixed>> $categories
     * @return array<mixed>
     */
    public function categoriesFlatlistToTree(array $categories): array
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
                /** @psalm-suppress UnsupportedReferenceUsage */
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
                        new PwgNamedArray([], 'category', $this->getCategoryXmlAttributes());
                }

                $sub = $categories[$parent_key]['sub_categories'];
                if ($sub instanceof PwgNamedArray) {
                    $sub->appendItem($node);
                }
            }
        }

        return $tree;
    }
}
