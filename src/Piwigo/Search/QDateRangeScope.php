<?php

declare(strict_types=1);

namespace Piwigo\Search;

final class QDateRangeScope extends QSearchScope
{
    /** @param string[] $aliases */
    public function __construct(string $id, array $aliases, bool $nullable = false)
    {
        parent::__construct($id, $aliases, $nullable, false);
    }

    #[\Override]
    public function parse(QSingleToken $token): bool
    {
        $str = $token->term;
        $strict = [0,0];
        if (($pos = strpos($str, '..')) !== false) {
            $range = [ substr($str, 0, $pos), substr($str, $pos + 2)];
        } elseif ('>' === ($str[0] ?? '')) {
            $range = [ substr($str, 1), ''];
            $strict[0] = 1;
        } elseif ('<' === ($str[0] ?? '')) {
            $range = ['', substr($str, 1)];
            $strict[1] = 1;
        } elseif (($token->modifier & QST_WILDCARD_BEGIN)) {
            $range = ['', $str];
        } elseif (($token->modifier & QST_WILDCARD_END)) {
            $range = [$str, ''];
        } else {
            $range = [$str, $str];
        }

        foreach ($range as $i => &$val) {
            if (preg_match('/([0-9]{4})-?((?:1[0-2])|(?:0?[1-9]))?-?((?:(?:[1-3][0-9])|(?:0?[1-9])))?/', $val, $matches)) {
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
            } elseif (strlen($val)) {
                return false;
            }
        }

        if (!$this->nullable && $range[0] == '' && $range[1] == '') {
            return false;
        }

        $token->scope_data = $range;
        return true;
    }

    #[\Override]
    public function getSql(string $field, QSingleToken $token): string
    {
        $clauses = [];
        $scopeArr = is_array($token->scope_data) ? $token->scope_data : ['', ''];
        $sd0 = is_string($scopeArr[0] ?? null) ? $scopeArr[0] : '';
        $sd1 = is_string($scopeArr[1] ?? null) ? $scopeArr[1] : '';
        if ($sd0 != '') {
            $clauses[] = $field.' >= \'' . $sd0.'\'';
        }
        if ($sd1 != '') {
            $clauses[] = $field.' <= \'' . $sd1.'\'';
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
