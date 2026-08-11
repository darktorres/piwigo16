<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Search;

use Override;

final class QDateRangeScope extends QSearchScope
{
    /**
     * @param string[] $aliases
     */
    public function __construct(
        string $id,
        array $aliases,
        bool $nullable = false
    ) {
        parent::__construct($id, $aliases, $nullable, false);
    }

    #[Override]
    public function parse(QSingleToken $token): bool
    {
        $str = $token->term;
        $strict = [0, 0];
        if (($pos = strpos($str, '..')) !== false) {
            $range = [substr($str, 0, $pos), substr($str, $pos + 2)];
        } elseif (($str[0] ?? null) === '>') {
            $range = [substr($str, 1), ''];
            $strict[0] = 1;
        } elseif (($str[0] ?? null) === '<') {
            $range = ['', substr($str, 1)];
            $strict[1] = 1;
        } elseif ((bool) ($token->modifier & QSingleToken::QST_WILDCARD_BEGIN)) {
            $range = ['', $str];
        } elseif ((bool) ($token->modifier & QSingleToken::QST_WILDCARD_END)) {
            $range = [$str, ''];
        } else {
            $range = [$str, $str];
        }

        foreach ($range as $i => &$val) {
            if ((bool) preg_match('/([0-9]{4})-?((?:1[0-2])|(?:0?[1-9]))?-?((?:(?:[1-3][0-9])|(?:0?[1-9])))?/', $val, $matches)) {
                array_shift($matches);
                if (! isset($matches[1])) {
                    $matches[1] = ((bool) ($i ^ $strict[$i])) ? 12 : 1;
                }
                if (! isset($matches[2])) {
                    $matches[2] = ((bool) ($i ^ $strict[$i])) ? 31 : 1;
                }
                $val = implode('-', $matches);
                if ((bool) ($i ^ $strict[$i])) {
                    $val .= ' 23:59:59';
                }
            } elseif ((bool) strlen($val)) {
                return false;
            }
        }

        if (! $this->nullable && $range[0] === '' && $range[1] === '') {
            return false;
        }

        $token->scope_data = $range;
        return true;
    }

    #[Override]
    public function getSql(string $field, QSingleToken $token): string
    {
        // QSingleToken::$scope_data's own declared type already covers this
        // shape; still discriminated at runtime since the property is a
        // union across both real writers.
        $scope_data = $token->scope_data;
        $date_range = is_array($scope_data) ? $scope_data : ['', ''];
        $date_range_0 = $date_range[0] ?? '';
        $date_range_1 = $date_range[1] ?? '';

        $clauses = [];
        if ($date_range_0 !== '') {
            $clauses[] = $field . ' >= \'' . $date_range_0 . '\'';
        }
        if ($date_range_1 !== '') {
            $clauses[] = $field . ' <= \'' . $date_range_1 . '\'';
        }

        if ($clauses === []) {
            if ((bool) ($token->modifier & QSingleToken::QST_WILDCARD)) {
                return $field . ' IS NOT NULL';
            } else {
                return $field . ' IS NULL';
            }
        }
        return '(' . implode(' AND ', $clauses) . ')';
    }
}
