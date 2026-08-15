<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

/**
 * The three lists of image/category/tag attributes that are to be
 * encoded as XML attributes instead of XML elements. Split out of the
 * former WsHelper god-class (P25 Stage 1 step 6).
 */
final readonly class XmlAttributeLists
{
    /**
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
}
