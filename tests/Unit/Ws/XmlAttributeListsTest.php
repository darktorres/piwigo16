<?php

declare(strict_types=1);

use Piwigo\Ws\XmlAttributeLists;

/**
 * Piwigo\Ws\XmlAttributeLists -- the three lists of image/category/tag
 * attributes that are to be encoded as XML attributes instead of XML
 * elements, split out of the former WsHelper god-class (P25 Stage 1
 * step 6).
 */
test('stdGetImageXmlAttributes returns the fixed image attribute list', function (): void {
    $lists = new XmlAttributeLists();

    expect($lists->stdGetImageXmlAttributes())
        ->toBe([
            'id', 'element_url', 'page_url', 'file', 'width', 'height', 'hit', 'date_available', 'date_creation',
        ]);
});

test('stdGetCategoryXmlAttributes returns the fixed category attribute list', function (): void {
    $lists = new XmlAttributeLists();

    expect($lists->stdGetCategoryXmlAttributes())
        ->toBe([
            'id', 'url', 'nb_images', 'total_nb_images', 'nb_categories', 'date_last', 'max_date_last', 'status',
        ]);
});

test('stdGetTagXmlAttributes returns the fixed tag attribute list', function (): void {
    $lists = new XmlAttributeLists();

    expect($lists->stdGetTagXmlAttributes())
        ->toBe([
            'id', 'name', 'url_name', 'counter', 'url', 'page_url',
        ]);
});
