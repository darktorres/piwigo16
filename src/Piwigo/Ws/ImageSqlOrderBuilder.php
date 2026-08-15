<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use Piwigo\Sort\PhotoSortField;

/**
 * Returns a "standard" (for our web service) ORDER BY sql clause for
 * images. Split out of the former WsHelper god-class (P25 Stage 1 step
 * 6).
 *
 * Still returns a raw SQL fragment -- retyping this to a typed sort spec
 * is a separate, larger change (touches Db\SqlDialect, Sort\OrderBy,
 * Sort\PhotoSortField, UserRepository and Users\GetListHandler) not
 * attempted in this split.
 */
final readonly class ImageSqlOrderBuilder
{
    /**
     * Each token in $params['order'] is resolved via
     * {@see \Piwigo\Sort\PhotoSortField::fromToken()}; see that enum's own
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
}
