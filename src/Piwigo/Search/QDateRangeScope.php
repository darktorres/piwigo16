<?php

declare(strict_types=1);

namespace Piwigo\Search;

class QDateRangeScope extends QSearchScope
{
    public function __construct($id, $aliases, $nullable = false)
    {
        parent::__construct($id, $aliases, $nullable, false);
    }

    #[\Override]
    public function parse($token): bool
    {
        $str = $token->term;
        $strict = [0,0];
        if (($pos = strpos((string) $str, '..')) !== false) {
            $range = [ substr((string) $str, 0, $pos), substr((string) $str, $pos + 2)];
        } elseif ('>' == @$str[0]) {
            $range = [ substr((string) $str, 1), ''];
            $strict[0] = 1;
        } elseif ('<' == @$str[0]) {
            $range = ['', substr((string) $str, 1)];
            $strict[1] = 1;
        } elseif (($token->modifier & QST_WILDCARD_BEGIN)) {
            $range = ['', $str];
        } elseif (($token->modifier & QST_WILDCARD_END)) {
            $range = [$str, ''];
        } else {
            $range = [$str, $str];
        }

        foreach ($range as $i => &$val) {
            if (preg_match('/([0-9]{4})-?((?:1[0-2])|(?:0?[1-9]))?-?((?:(?:[1-3][0-9])|(?:0?[1-9])))?/', (string) $val, $matches)) {
                array_shift($matches);
                if (!isset($matches[1])) {
                    $matches[1] = ($i ^ $strict[$i]) ? 12 : 1;
                }
                if (!isset($matches[2])) {
                    $matches[2] = ($i ^ $strict[$i]) ? 31 : 1;
                }
                $val = implode('-', $matches);
                if ($i ^ $strict[$i]) {
                    $val .= ' 23:59:59';
                }
            } elseif (strlen((string) $val)) {
                return false;
            }
        }

        if (!$this->nullable && $range[0] == '' && $range[1] == '') {
            return false;
        }

        $token->scope_data = $range;
        return true;
    }

    public function get_sql(string $field, $token): string
    {
        $clauses = [];
        if ($token->scope_data[0] != '') {
            $clauses[] = $field.' >= \'' . $token->scope_data[0].'\'';
        }
        if ($token->scope_data[1] != '') {
            $clauses[] = $field.' <= \'' . $token->scope_data[1].'\'';
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
