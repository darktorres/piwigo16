<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use Piwigo\Auth\AccessControl;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\DateHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\WsError;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageFilterCriteria;
use Piwigo\Image\PhotoSortField;
use Piwigo\Image\SrcImage;
use Piwigo\Users\CurrentUser;
use Piwigo\Ws\Event\WsInvokeAllowed;

/**
 * Shared web-service helpers, called from 2-4 of the Ws\Pwg* classes each.
 * isInvokeAllowed()/stdGetUrls() are the only methods that need a
 * collaborator (AccessControl/CurrentUser respectively).
 */
final readonly class WsHelper
{
    public function __construct(
        private AccessControl $accessControl,
        private CurrentUser $currentUser,
    ) {}

    /**
     * Event handler for method invocation security check. Sets $event->value
     * to a WsErrorResponse if the preconditions are not satisfied for method
     * invocation.
     */
    public function isInvokeAllowed(WsInvokeAllowed $event): WsInvokeAllowed
    {
        if (str_starts_with($event->methodName, 'reflection.')) { // OK for reflection
            return $event;
        }

        if (! $this->accessControl->isAuthorizeStatus(AccessLevel::Guest) and
            ! str_starts_with($event->methodName, 'pwg.session.')) {
            $event->value = new WsErrorResponse(401, 'Access denied');
            return $event;
        }

        return $event;
    }

    /**
     * Builds the "standard" (for our web service) image-filter criteria --
     * the shared f_* range-filter set (images table only).
     *
     * Called from every WS method that merges ws.php's shared $f_params into
     * its registration (pwg.images.search, pwg.categories.getImages,
     * pwg.getMissingDerivatives, pwg.tags.getImages) -- all 11 f_* keys are
     * always present, per that shared registration block.
     *
     * $params's own float|null/int|null typing (Server's own
     * WsParamType::FLOAT/WsParamType::INT coercion, per this method's own
     * $params shape below) already guarantees each f_* value's real type --
     * the 4 date fields are validated below via
     * DateHelper::isValidMysqlDatetime(), which round-trips through
     * DateTime::createFromFormat() and can never let a
     * non-digit/hyphen/space/colon character through.
     *
     * @param array{f_min_rate: float|null, f_max_rate: float|null, f_min_hit: int|null, f_max_hit: int|null, f_min_ratio: float|null, f_max_ratio: float|null, f_max_level: int|null, f_min_date_available: string|null, f_max_date_available: string|null, f_min_date_created: string|null, f_max_date_created: string|null, ...} $params
     */
    public function stdImageSqlFilterCriteria(array $params): ImageFilterCriteria|WsErrorResponse
    {
        foreach (['f_min_date_available', 'f_max_date_available', 'f_min_date_created', 'f_max_date_created'] as $datefield) {
            if (isset($params[$datefield]) and ! DateHelper::isValidMysqlDatetime($params[$datefield])) {
                return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid ' . $datefield);
            }
        }

        return new ImageFilterCriteria(
            minRate: $params['f_min_rate'],
            maxRate: $params['f_max_rate'],
            minHit: $params['f_min_hit'],
            maxHit: $params['f_max_hit'],
            minDateAvailable: $params['f_min_date_available'],
            maxDateAvailable: $params['f_max_date_available'],
            minDateCreated: $params['f_min_date_created'],
            maxDateCreated: $params['f_max_date_created'],
            minRatio: $params['f_min_ratio'],
            maxRatio: $params['f_max_ratio'],
            maxLevel: $params['f_max_level'],
        );
    }

    /**
     * returns a "standard" (for our web service) ORDER BY sql clause for images
     *
     * Each token in $params['order'] is resolved via
     * {@see \Piwigo\Image\PhotoSortField::fromToken()}; see that enum's own
     * docblock for why this is scoped to just this one method.
     *
     * @param array{order: string|null, ...} $params order has no WS_TYPE flag
     *   and a null default, but Server::invoke() still guarantees a plain
     *   scalar (rejects arrays for any registered param lacking
     *   WsParamFlag::ACCEPT_ARRAY)
     */
    public function stdImageSqlOrder(array $params, string $tbl_name = ''): string
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
            $field = PhotoSortField::fromToken($matches[1][$i]);
            if ($field instanceof PhotoSortField) {
                if ($ret !== '') {
                    $ret .= ', ';
                }
                if ($field !== PhotoSortField::Random) {
                    $ret .= $tbl_name;
                }
                $ret .= $field->column();
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
    public function stdGetUrls(array $image_row, UrlServiceInterface $urlService): array
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

        if ($src_image->isOriginal()) {// we have a photo
            if ($this->currentUser->get()->enabledHigh) {
                $ret['element_url'] = $src_image->getUrl();
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

        $derivatives = DerivativeImage::getAll($src_image);
        $derivatives_arr = [];
        foreach ($derivatives as $type => $derivative) {
            $size = $derivative->getSize();
            if ($size === null) {
                $size = [null, null];
            }
            $derivatives_arr[(string) $type] = [
                'url' => $derivative->getUrl(),
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
    public function stdGetImageXmlAttributes(): array
    {
        return [
            'id', 'element_url', 'page_url', 'file', 'width', 'height', 'hit', 'date_available', 'date_creation',
        ];
    }

    /**
     * @return string[]
     */
    public function stdGetCategoryXmlAttributes(): array
    {
        return [
            'id', 'url', 'nb_images', 'total_nb_images', 'nb_categories', 'date_last', 'max_date_last', 'status',
        ];
    }

    /**
     * @return string[]
     */
    public function stdGetTagXmlAttributes(): array
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
     * NamedArray) -- same rationale as AlbumsPageRenderer's own
     * dynamically-built tree.
     *
     * @param array<int|string, array<string, mixed>> $categories
     * @return list<array<string, mixed>>
     */
    public function categoriesFlatlistToTree(array $categories): array
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
                      new NamedArray([], 'category', $this->stdGetCategoryXmlAttributes());
                }

                $sub_categories = $categories[$uppercat_key]['sub_categories'];
                if ($sub_categories instanceof NamedArray) {
                    $sub_categories->content[] = &$node;
                }
            }
        }

        return $tree;
    }
}
