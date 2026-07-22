<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Search;

class QNumericRangeScope extends QSearchScope
{
    /**
     * @param string[] $aliases
     */
    public function __construct(
        string $id,
        array $aliases,
        bool $nullable = false,
        private readonly int|float $epsilon = 0
    ) {
        parent::__construct($id, $aliases, $nullable, false);
    }

    #[\Override]
    public function parse(QSingleToken $token): bool
    {
        $str = $token->term;
        $strict = [0, 0];
        $range_requested = true;
        if (($pos = strpos($str, '..')) !== false) {
            $range = [substr($str, 0, $pos), substr($str, $pos + 2)];
        } elseif (@$str[0] === '>') {// ratio:>1
            $range = [substr($str, 1), ''];
            $strict[0] = 1;
        } elseif (@$str[0] === '<') { // size:<5mp
            $range = ['', substr($str, 1)];
            $strict[1] = 1;
        } elseif ((bool) ($token->modifier & QSingleToken::QST_WILDCARD_BEGIN)) {
            $range = ['', $str];
        } elseif ((bool) ($token->modifier & QSingleToken::QST_WILDCARD_END)) {
            $range = [$str, ''];
        } else {
            $range = [$str, $str];
            $range_requested = false;
        }

        foreach ($range as $i => &$val) {
            if ((bool) preg_match('#^(-?[0-9.]+)/([0-9.]+)$#i', $val, $matches)) {
                $val = floatval((float) $matches[1] / (float) $matches[2]);
            } elseif ((bool) preg_match('/^(-?[0-9.]+)([km])?/i', $val, $matches)) {
                $val = floatval($matches[1]);
                if (isset($matches[2])) {
                    $mult = 1;
                    if ($matches[2] === 'k' || $matches[2] === 'K') {
                        $mult = 1000;
                    } else {
                        $mult = 1000000;
                    }
                    $val *= $mult;
                    if (((bool) $i) && ! $range_requested) {// round up the upper limit if possible - e.g 6k goes up to 6999, but 6.12k goes only up to 6129
                        if (($dot_pos = strpos($matches[1], '.')) !== false) {
                            $requested_precision = strlen($matches[1]) - $dot_pos - 1;
                            $mult /= 10 ** $requested_precision;
                        }
                        if ($mult > 1) {
                            $val += $mult - 1;
                        }
                    }
                }
            } else {
                $val = '';
            }
            if (is_numeric($val)) {
                if ((bool) ($i ^ $strict[$i])) {
                    $val += $this->epsilon;
                } else {
                    $val -= $this->epsilon;
                }
            }
        }

        if (! $this->nullable && $range[0] === '' && $range[1] === '') {
            return false;
        }
        $token->scope_data = [
            'range' => $range,
            'strict' => $strict,
        ];
        return true;
    }

    #[\Override]
    public function get_sql(string $field, QSingleToken $token): string
    {
        // set by parse() above as ['range' => array{0: int|float|string, 1:
        // int|float|string}, 'strict' => array{0: int, 1: int}] — see
        // QSingleToken::$scope_data's own docblock for why this can't be a
        // static property type.
        $scope_data = $token->scope_data;
        $range = is_array($scope_data) && is_array($scope_data['range'] ?? null) ? $scope_data['range'] : ['', ''];
        $strict = is_array($scope_data) && is_array($scope_data['strict'] ?? null) ? $scope_data['strict'] : [0, 0];
        $range_0 = $range[0] ?? '';
        $range_1 = $range[1] ?? '';

        $clauses = [];
        if ($range_0 !== '') {
            $clauses[] = $field . ' >' . (empty($strict[0]) ? '=' : '') . (is_scalar($range_0) ? (string) $range_0 : '') . ' ';
        }
        if ($range_1 !== '') {
            $clauses[] = $field . ' <' . (empty($strict[1]) ? '=' : '') . (is_scalar($range_1) ? (string) $range_1 : '') . ' ';
        }

        if (empty($clauses)) {
            if ((bool) ($token->modifier & QSingleToken::QST_WILDCARD)) {
                return $field . ' IS NOT NULL';
            } else {
                return $field . ' IS NULL';
            }
        }
        return '(' . implode(' AND ', $clauses) . ')';
    }
}
