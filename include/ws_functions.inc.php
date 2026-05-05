<?php

declare(strict_types=1);

use Piwigo\Core\ServiceLocator;
use Piwigo\Ws\WsHelper;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

/** @param array<mixed> $params */
function ws_isInvokeAllowed(mixed $res, string $methodName, array $params): mixed
{
    return ServiceLocator::get(WsHelper::class)->isInvokeAllowed($res, $methodName, $params);
}

/**
 * @param array<mixed> $params
 * @return array<mixed>
 */
function ws_std_image_sql_filter(array $params, string $tbl_name = ''): array
{
    return ServiceLocator::get(WsHelper::class)->imageSqlFilter($params, $tbl_name);
}

/** @param array<mixed> $params */
function ws_std_image_sql_order(array $params, string $tbl_name = ''): string
{
    return ServiceLocator::get(WsHelper::class)->imageSqlOrder($params, $tbl_name);
}

/**
 * @param array<string, mixed> $image_row
 * @return array<mixed>
 */
function ws_std_get_urls(array $image_row): array
{
    return ServiceLocator::get(WsHelper::class)->getUrls($image_row);
}

/** @return string[] */
function ws_std_get_image_xml_attributes(): array
{
    return ServiceLocator::get(WsHelper::class)->getImageXmlAttributes();
}

/** @return string[] */
function ws_std_get_category_xml_attributes(): array
{
    return ServiceLocator::get(WsHelper::class)->getCategoryXmlAttributes();
}

/** @return string[] */
function ws_std_get_tag_xml_attributes(): array
{
    return ServiceLocator::get(WsHelper::class)->getTagXmlAttributes();
}

/**
 * @param array<array<string, mixed>> $categories
 * @return array<mixed>
 */
function categories_flatlist_to_tree(array $categories): array
{
    return ServiceLocator::get(WsHelper::class)->categoriesFlatlistToTree($categories);
}
