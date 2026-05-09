<?php

declare(strict_types=1);

namespace Piwigo\Search;

final class QNumericRangeScope extends QSearchScope
{
    /** @param string[] $aliases */
    public function __construct(string $id, array $aliases, bool $nullable = false, private readonly int|float $epsilon = 0)
    {
        parent::__construct($id, $aliases, $nullable, false);
    }

    #[\Override]
    public function parse(QSingleToken $token): bool
    {
        $str = $token->term;
        $strict = [0,0];
        $range_requested = true;
        if (($pos = strpos($str, '..')) !== false) {
            $range = [ substr($str, 0, $pos), substr($str, $pos + 2)];
        } elseif ('>' === ($str[0] ?? '')) {// ratio:>1
            $range = [ substr($str, 1), ''];
            $strict[0] = 1;
        } elseif ('<' === ($str[0] ?? '')) { // size:<5mp
            $range = ['', substr($str, 1)];
            $strict[1] = 1;
        } elseif (($token->modifier & QST_WILDCARD_BEGIN)) {
            $range = ['', $str];
        } elseif (($token->modifier & QST_WILDCARD_END)) {
            $range = [$str, ''];
        } else {
            $range = [$str, $str];
            $range_requested = false;
        }

        foreach ($range as $i => &$val) {
            if (preg_match('#^(-?[0-9.]+)/([0-9.]+)$#i', $val, $matches)) {
                $val = floatval((float)$matches[1] / (float)$matches[2]);
            } elseif (preg_match('/^(-?[0-9.]+)([km])?/i', $val, $matches)) {
                $val = floatval($matches[1]);
                if (isset($matches[2])) {
                    $mult = 1.0;
                    if ($matches[2] == 'k' || $matches[2] == 'K') {
                        $mult = 1000.0;
                    } else {
                        $mult = 1000000.0;
                    }
                    $val *= $mult;
                    if ($i && !$range_requested) {// round up the upper limit if possible - e.g 6k goes up to 6999, but 6.12k goes only up to 6129
                        if (($dot_pos = strpos($matches[1], '.')) !== false) {
                            $requested_precision = strlen($matches[1]) - $dot_pos - 1;
                            $mult /= 10.0 ** (float) $requested_precision;
                        }
                        if ($mult > 1.0) {
                            $val += $mult - 1.0;
                        }
                    }
                }
            } else {
                $val = '';
            }
            if (is_numeric($val)) {
                if ($i ^ $strict[$i]) {
                    $val = (float) $val + (float) $this->epsilon;
                } else {
                    $val = (float) $val - (float) $this->epsilon;
                }
            }
        }

        if (!$this->nullable && $range[0] === '' && $range[1] === '') {
            return false;
        }
        $token->scope_data = [ 'range' => $range, 'strict' => $strict ];
        return true;
    }

    #[\Override]
    public function getSql(string $field, QSingleToken $token): string
    {
        $clauses = [];
        /** @var array{range: array<int,mixed>, strict: array<int,mixed>} $sd */
        $sd = is_array($token->scope_data) ? $token->scope_data : ['range' => ['', ''], 'strict' => [false, false]];
        $range = $sd['range'];
        $strict = $sd['strict'];
        if (($range[0] ?? '') !== '') {
            $clauses[] = $field.' >'.($strict[0] ? '' : '=').(is_numeric($range[0]) ? (string) $range[0] : '').' ';
        }
        if (($range[1] ?? '') !== '') {
            $clauses[] = $field.' <'.($strict[1] ? '' : '=').(is_numeric($range[1]) ? (string) $range[1] : '').' ';
        }

        if (empty($clauses)) {
            if ($token->modifier & QST_WILDCARD) {
                return $field.' IS NOT NULL';
            } else {
                return $field.' IS NULL';
            }
        }
        return '('.implode(' AND ', $clauses).')';
    }
}
