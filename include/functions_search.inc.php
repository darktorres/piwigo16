<?php

declare(strict_types=1);

use Piwigo\Core\ServiceLocator;
use Piwigo\Search\SearchService;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * @package functions\search
 */

function get_search_id_pattern(int|string $candidate): ?string
{
    return ServiceLocator::get(SearchService::class)->getSearchIdPattern($candidate);
}

/** @return array<string,mixed>|null */
function get_search_info(int|string $candidate): ?array
{
    return ServiceLocator::get(SearchService::class)->getSearchInfo($candidate);
}

/** @return array<mixed> */
function get_search_array(mixed $search_id): array
{
    return ServiceLocator::get(SearchService::class)->getSearchArray($search_id);
}

/**
 * @param array<mixed> $search
 * @return array<mixed>
 */
function get_regular_search_results(array $search, ?string $images_where = ''): array
{
    return ServiceLocator::get(SearchService::class)->getRegularSearchResults($search, $images_where);
}

function get_clause_for_filter(mixed $filter_name): string
{
    return ServiceLocator::get(SearchService::class)->getClauseForFilter($filter_name);
}

/** @return array<int>|false */
function get_items_for_filter(string $filter_name): array|false
{
    return ServiceLocator::get(SearchService::class)->getItemsForFilter($filter_name);
}


define('QST_QUOTED', 0x01);
define('QST_NOT', 0x02);
define('QST_OR', 0x04);
define('QST_WILDCARD_BEGIN', 0x08);
define('QST_WILDCARD_END', 0x10);
define('QST_WILDCARD', QST_WILDCARD_BEGIN | QST_WILDCARD_END);
define('QST_BREAK', 0x20);

/**
 * A search scope applies to a single token and restricts the search to a subset of searchable fields.
 */
class QSearchScope
{
    /** @param string[] $aliases */
    public function __construct(public string $id, public array $aliases, public bool $nullable = false, public bool $is_text = true)
    {
    }

    public function parse(\Piwigo\Search\QSingleToken $token): bool
    {
        if (!$this->nullable && 0 == strlen((string) $token->term)) {
            return false;
        }
        return true;
    }

    public function process_char(string &$ch, string &$crt_token): bool
    {
        return false;
    }
}

class QNumericRangeScope extends \Piwigo\Search\QSearchScope
{
    /** @param string[] $aliases */
    public function __construct(string $id, array $aliases, bool $nullable = false, private readonly int|float $epsilon = 0)
    {
        parent::__construct($id, $aliases, $nullable, false);
    }

    #[\Override]
    public function parse(\Piwigo\Search\QSingleToken $token): bool
    {
        $str = $token->term;
        $strict = [0,0];
        $range_requested = true;
        if (($pos = strpos((string) $str, '..')) !== false) {
            $range = [ substr((string) $str, 0, $pos), substr((string) $str, $pos + 2)];
        } elseif ('>' === ($str[0] ?? '')) {// ratio:>1
            $range = [ substr((string) $str, 1), ''];
            $strict[0] = 1;
        } elseif ('<' === ($str[0] ?? '')) { // size:<5mp
            $range = ['', substr((string) $str, 1)];
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
            if (preg_match('#^(-?[0-9.]+)/([0-9.]+)$#i', (string) $val, $matches)) {
                $val = floatval((float)$matches[1] / (float)$matches[2]);
            } elseif (preg_match('/^(-?[0-9.]+)([km])?/i', (string) $val, $matches)) {
                $val = floatval($matches[1]);
                if (isset($matches[2])) {
                    $mult = 1;
                    if ($matches[2] == 'k' || $matches[2] == 'K') {
                        $mult = 1000;
                    } else {
                        $mult = 1000000;
                    }
                    $val *= $mult;
                    if ($i && !$range_requested) {// round up the upper limit if possible - e.g 6k goes up to 6999, but 6.12k goes only up to 6129
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
                if ($i ^ $strict[$i]) {
                    $val += $this->epsilon;
                } else {
                    $val -= $this->epsilon;
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
    public function get_sql(string $field, \Piwigo\Search\QSingleToken $token): string
    {
        $scope_data = is_array($token->scope_data) ? $token->scope_data : [];
        $range = is_array($scope_data['range'] ?? null) ? $scope_data['range'] : ['', ''];
        $strict = is_array($scope_data['strict'] ?? null) ? $scope_data['strict'] : [0, 0];
        $range0 = is_scalar($range[0] ?? null) ? (string) $range[0] : '';
        $range1 = is_scalar($range[1] ?? null) ? (string) $range[1] : '';
        $strict0 = !empty($strict[0]);
        $strict1 = !empty($strict[1]);
        $clauses = [];
        if ($range0 !== '') {
            $clauses[] = $field.' >'.($strict0 ? '' : '=').$range0.' ';
        }
        if ($range1 !== '') {
            $clauses[] = $field.' <'.($strict1 ? '' : '=').$range1.' ';
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


class QDateRangeScope extends \Piwigo\Search\QSearchScope
{
    /** @param string[] $aliases */
    public function __construct(string $id, array $aliases, bool $nullable = false)
    {
        parent::__construct($id, $aliases, $nullable, false);
    }

    #[\Override]
    public function parse(\Piwigo\Search\QSingleToken $token): bool
    {
        $str = $token->term;
        $strict = [0,0];
        if (($pos = strpos((string) $str, '..')) !== false) {
            $range = [ substr((string) $str, 0, $pos), substr((string) $str, $pos + 2)];
        } elseif ('>' === ($str[0] ?? '')) {
            $range = [ substr((string) $str, 1), ''];
            $strict[0] = 1;
        } elseif ('<' === ($str[0] ?? '')) {
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

    #[\Override]
    public function get_sql(string $field, \Piwigo\Search\QSingleToken $token): string
    {
        $scope_data = is_array($token->scope_data) ? $token->scope_data : ['', ''];
        $val0 = is_scalar($scope_data[0] ?? null) ? (string) $scope_data[0] : '';
        $val1 = is_scalar($scope_data[1] ?? null) ? (string) $scope_data[1] : '';
        $clauses = [];
        if ($val0 != '') {
            $clauses[] = $field.' >= \'' . $val0.'\'';
        }
        if ($val1 != '') {
            $clauses[] = $field.' <= \'' . $val1.'\'';
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

/** Represents a single word or quoted phrase to be searched.*/
class QSingleToken implements \Stringable
{
    public bool $is_single = true; /* the actual word/phrase string*/
    /** @var string[] */
    public array $variants = [];

    public mixed $scope_data = null;
    public int $idx = 0;

    public function __construct(public string $term, public int $modifier, public ?\Piwigo\Search\QSearchScope $scope)
    {
    }

    public function __toString(): string
    {
        $s = '';
        if (isset($this->scope)) {
            $s .= $this->scope->id .':';
        }
        if ($this->modifier & QST_WILDCARD_BEGIN) {
            $s .= '*';
        }
        if ($this->modifier & QST_QUOTED) {
            $s .= '"';
        }
        $s .= $this->term;
        if ($this->modifier & QST_QUOTED) {
            $s .= '"';
        }
        if ($this->modifier & QST_WILDCARD_END) {
            $s .= '*';
        }
        return $s;
    }
}

/** Represents an expression of several words or sub expressions to be searched.*/
class QMultiToken implements \Stringable
{
    public bool $is_single = false;
    public int $modifier = 0;
    /** @var array<\Piwigo\Search\QSingleToken|\Piwigo\Search\QMultiToken> */
    public array $tokens = []; // the actual array of QSingleToken or QMultiToken

    public function __toString(): string
    {
        $s = '';
        for ($i = 0; $i < count($this->tokens); $i++) {
            $modifier = $this->tokens[$i]->modifier;
            if ($i) {
                $s .= ' ';
            }
            if ($modifier & QST_OR) {
                $s .= 'OR ';
            }
            if ($modifier & QST_NOT) {
                $s .= 'NOT ';
            }
            if (! ($this->tokens[$i]->is_single)) {
                $s .= '(';
                $s .= $this->tokens[$i];
                $s .= ')';
            } else {
                $s .= $this->tokens[$i];
            }
        }
        return $s;
    }

    /**
     * @param-out null $scope
     */
    private function push(string &$token, int &$modifier, ?\Piwigo\Search\QSearchScope &$scope): void
    {
        if (strlen((string) $token) || (isset($scope) && $scope->nullable)) {
            if (isset($scope)) {
                $modifier |= QST_BREAK;
            }
            $this->tokens[] = new \Piwigo\Search\QSingleToken($token, $modifier, $scope);
        }
        $token = '';
        $modifier = 0;
        $scope = null;
    }

    /**
    * Parses the input query string by tokenizing the input, generating the modifiers (and/or/not/quotation/wildcards...).
    * Recursivity occurs when parsing ()
    * @param string $q the actual query to be parsed
    * @param int $qi the character index in $q where to start parsing
    * @param int $level the depth from root in the tree (number of opened and unclosed opening brackets)
    */
    protected function parse_expression(string $q, int &$qi, int $level, \Piwigo\Search\QExpression $root): void
    {
        $crt_token = '';
        $crt_modifier = 0;
        /** @var ?\Piwigo\Search\QSearchScope $crt_scope */
        $crt_scope = null;

        for ($stop = false; !$stop && $qi < strlen($q); $qi++) {
            $ch = $q[$qi];
            if (($crt_modifier & QST_QUOTED) == 0) {
                switch ($ch) {
                    case '(':
                        if (strlen((string) $crt_token)) {
                            $this->push($crt_token, $crt_modifier, $crt_scope);
                        }
                        $sub = new \Piwigo\Search\QMultiToken();
                        $qi++;
                        $sub->parse_expression($q, $qi, $level + 1, $root);
                        $sub->modifier = $crt_modifier;
                        if (isset($crt_scope) && $crt_scope->is_text) {
                            $sub->apply_scope($crt_scope); // eg. 'tag:(John OR Bill)'
                        }
                        $this->tokens[] = $sub;
                        $crt_modifier = 0;
                        $crt_scope = null;
                        break;
                    case ')':
                        if ($level > 0) {
                            $stop = true;
                        }
                        break;
                    case ':':
                        $scope = $root->scopes[strtolower((string) $crt_token)] ?? null;
                        if (!isset($scope) || isset($crt_scope)) { // white space
                            $this->push($crt_token, $crt_modifier, $crt_scope);
                        } else {
                            $crt_token = '';
                            $crt_scope = $scope;
                        }
                        break;
                    case '"':
                        if (strlen((string) $crt_token)) {
                            $this->push($crt_token, $crt_modifier, $crt_scope);
                        }
                        $crt_modifier |= QST_QUOTED;
                        break;
                    case '-':
                        if (strlen((string) $crt_token) || isset($crt_scope)) {
                            $crt_token .= $ch;
                        } else {
                            $crt_modifier |= QST_NOT;
                        }
                        break;
                    case '*':
                        if (strlen((string) $crt_token)) {
                            $crt_token .= $ch;
                        } // wildcard end later
                        else {
                            $crt_modifier |= QST_WILDCARD_BEGIN;
                        }
                        break;
                    case '.':
                        if (isset($crt_scope) && !$crt_scope->is_text) {
                            $crt_token .= $ch;
                            break;
                        }
                        if (strlen((string) $crt_token) && preg_match('/[0-9]/', substr((string) $crt_token, -1))
                          && $qi + 1 < strlen($q) && preg_match('/[0-9]/', $q[$qi + 1])) {// dot between digits is not a separator e.g. F2.8
                            $crt_token .= $ch;
                            break;
                        }
                        // else white space go on..
                        // no break
                    default:
                        if (!$crt_scope || !$crt_scope->process_char($ch, $crt_token)) {
                            if (str_contains(' ,.;!?', $ch)) { // white space
                                $this->push($crt_token, $crt_modifier, $crt_scope);
                            } else {
                                $crt_token .= $ch;
                            }
                        }
                        break;
                }
            } else {// quoted
                if ($ch == '"') {
                    if ($qi + 1 < strlen($q) && $q[$qi + 1] == '*') {
                        $crt_modifier |= QST_WILDCARD_END;
                        $qi++;
                    }
                    $this->push($crt_token, $crt_modifier, $crt_scope);
                } else {
                    $crt_token .= $ch;
                }
            }
        }

        $this->push($crt_token, $crt_modifier, $crt_scope);

        for ($i = 0; $i < count($this->tokens); $i++) {
            $token = $this->tokens[$i];
            $remove = false;
            if ($token instanceof \Piwigo\Search\QSingleToken) {
                if (($token->modifier & QST_QUOTED) == 0
                  && str_ends_with((string) $token->term, '*')) {
                    $token->term = rtrim((string) $token->term, '*');
                    $token->modifier |= QST_WILDCARD_END;
                }

                if (!isset($token->scope)
                  && ($token->modifier & (QST_QUOTED | QST_WILDCARD)) == 0) {
                    if ('not' == strtolower((string) $token->term)) {
                        if ($i + 1 < count($this->tokens)) {
                            $this->tokens[$i + 1]->modifier |= QST_NOT;
                        }
                        $token->term = '';
                    }
                    if ('or' == strtolower((string) $token->term)) {
                        if ($i + 1 < count($this->tokens)) {
                            $this->tokens[$i + 1]->modifier |= QST_OR;
                        }
                        $token->term = '';
                    }
                    if ('and' == strtolower((string) $token->term)) {
                        $token->term = '';
                    }
                }

                if (!strlen((string) $token->term)
                  && (!isset($token->scope) || !$token->scope->nullable)) {
                    $remove = true;
                }

                if (isset($token->scope)
                  && !$token->scope->parse($token)) {
                    $remove = true;
                }
            } elseif (!count($token->tokens)) {
                $remove = true;
            }
            if ($remove) {
                array_splice($this->tokens, $i, 1);
                if ($i < count($this->tokens) && $this->tokens[$i] instanceof \Piwigo\Search\QSingleToken) {
                    $this->tokens[$i]->modifier |= QST_BREAK;
                }
                $i--;
            }
        }

        if ($level > 0 && count($this->tokens) && $this->tokens[0] instanceof \Piwigo\Search\QSingleToken) {
            $this->tokens[0]->modifier |= QST_BREAK;
        }
    }

    /**
    * Applies recursively a search scope to all sub single tokens. We allow 'tag:(John Bill)' but we cannot evaluate
    * scopes on expressions so we rewrite as '(tag:John tag:Bill)'
    */
    protected function apply_scope(\Piwigo\Search\QSearchScope $scope): void
    {
        for ($i = 0; $i < count($this->tokens); $i++) {
            if ($this->tokens[$i] instanceof \Piwigo\Search\QSingleToken) {
                if (!isset($this->tokens[$i]->scope)) {
                    $this->tokens[$i]->scope = $scope;
                }
            } elseif ($this->tokens[$i] instanceof \Piwigo\Search\QMultiToken) {
                $this->tokens[$i]->apply_scope($scope);
            }
        }
    }

    private static function priority(int $modifier): int
    {
        return $modifier & QST_OR ? 0 : 1;
    }

    /* because evaluations occur left to right, we ensure that 'a OR b c d' is interpreted as 'a OR (b c d)'*/
    protected function check_operator_priority(): void
    {
        $crt_prio = 0;
        for ($i = 0; $i < count($this->tokens); $i++) {
            if ($this->tokens[$i] instanceof \Piwigo\Search\QMultiToken) {
                $this->tokens[$i]->check_operator_priority();
            }
            if ($i == 1) {
                $crt_prio = self::priority($this->tokens[$i]->modifier);
            }
            if ($i <= 1) {
                continue;
            }
            $prio = self::priority($this->tokens[$i]->modifier);
            if ($prio > $crt_prio) {// e.g. 'a OR b c d' i=2, operator(c)=AND -> prio(AND) > prio(OR) = operator(b)
                $term_count = 2; // at least b and c to be regrouped
                for ($j = $i + 1; $j < count($this->tokens); $j++) {
                    if (self::priority($this->tokens[$j]->modifier) >= $prio) {
                        $term_count++;
                    } // also take d
                    else {
                        break;
                    }
                }

                $i--; // move pointer to b
                // crate sub expression (b c d)
                $sub = new \Piwigo\Search\QMultiToken();
                $sub->tokens = array_splice($this->tokens, $i, $term_count);

                // rewrite ourseleves as a (b c d)
                array_splice($this->tokens, $i, 0, [$sub]);
                $sub->modifier = $sub->tokens[0]->modifier & QST_OR;
                $sub->tokens[0]->modifier &= ~QST_OR;

                $sub->check_operator_priority();
            } else {
                $crt_prio = $prio;
            }
        }
    }
}

class QExpression extends \Piwigo\Search\QExpression
{
    /** @param array<\Piwigo\Search\QSearchScope> $scopes */
    public function __construct(string $q, array $scopes)
    {
        // replicate parent constructor logic using our extended parse_expression
        foreach ($scopes as $scope) {
            $this->scopes[$scope->id] = $scope;
            foreach ($scope->aliases as $alias) {
                $this->scopes[strtolower($alias)] = $scope;
            }
        }
        $i = 0;
        $this->parse_expression($q, $i, 0, $this);
        //manipulate the tree so that 'a OR b c' is the same as 'b c OR a'
        $this->check_operator_priority();
        $this->build_single_tokens_impl($this, 0);
    }

    private function build_single_tokens_impl(\Piwigo\Search\QMultiToken $expr, int $this_is_not): void
    {
        for ($i = 0; $i < count($expr->tokens); $i++) {
            $token = $expr->tokens[$i];
            $crt_is_not = ($token->modifier ^ $this_is_not) & QST_NOT; // no negation OR double negation -> no negation;

            if ($token instanceof \Piwigo\Search\QSingleToken) {
                $token->idx = count($this->stokens);
                $this->stokens[] = $token;

                $modifier = $token->modifier;
                if ($crt_is_not) {
                    $modifier |= QST_NOT;
                } else {
                    $modifier &= ~QST_NOT;
                }
                $this->stoken_modifiers[] = $modifier;
            } elseif ($token instanceof \Piwigo\Search\QMultiToken) {
                $this->build_single_tokens_impl($token, $this_is_not);
            }
        }
    }
}

/**
  Structure of results being filled from different tables
*/
class QResults
{
    /** @var array<mixed> */
    public array $all_tags = [];
    /** @var array<int, int[]> */
    public array $tag_ids = [];
    /** @var array<int, int[]> */
    public array $tag_iids = [];
    /** @var array<mixed> */
    public array $all_cats = [];
    /** @var array<int, int[]> */
    public array $cat_ids = [];
    /** @var array<int, int[]> */
    public array $cat_iids = [];
    /** @var array<int, int[]> */
    public array $images_iids = [];
    /** @var array<int, int[]> */
    public array $iids = [];
}

/**
 * @param string[] $fields
 * @return non-falsy-string[]
 */
function qsearch_get_text_token_search_sql(\Piwigo\Search\QSingleToken $token, array $fields): array
{
    return ServiceLocator::get(SearchService::class)->qsearchGetTextTokenSearchSql($token, $fields);
}

function qsearch_get_images(\Piwigo\Search\QExpression $expr, \Piwigo\Search\QResults $qsr): void
{
    ServiceLocator::get(SearchService::class)->qsearchGetImages($expr, $qsr);
}

function qsearch_get_tags(\Piwigo\Search\QExpression $expr, \Piwigo\Search\QResults $qsr): void
{
    ServiceLocator::get(SearchService::class)->qsearchGetTags($expr, $qsr);
}

function qsearch_get_categories(\Piwigo\Search\QExpression $expr, \Piwigo\Search\QResults $qsr): void
{
    ServiceLocator::get(SearchService::class)->qsearchGetCategories($expr, $qsr);
}

/**
 * @param string[] $ignored_terms
 * @return int[]
 */
function qsearch_eval(\Piwigo\Search\QMultiToken $expr, \Piwigo\Search\QResults $qsr, bool &$qualifies, array &$ignored_terms): array
{
    return ServiceLocator::get(SearchService::class)->qsearchEval($expr, $qsr, $qualifies, $ignored_terms);
}

/**
 * @param array<mixed> $options
 * @return array<mixed>|null
 */
function get_quick_search_results(string $q, array $options): ?array
{
    return ServiceLocator::get(SearchService::class)->getQuickSearchResults($q, $options);
}

/**
 * @param array<mixed> $options
 * @return array<mixed>
 */
function get_quick_search_results_no_cache(string $q, array $options): array
{
    return ServiceLocator::get(SearchService::class)->getQuickSearchResultsNoCache($q, $options);
}

/** @return array<mixed> */
function get_search_results(int|string $search_id, bool $super_order_by, ?string $images_where = ''): array
{
    return ServiceLocator::get(SearchService::class)->getSearchResults($search_id, $super_order_by, $images_where);
}

/** @return string[]|null */
function split_allwords(string $raw_allwords): ?array
{
    return ServiceLocator::get(SearchService::class)->splitAllwords($raw_allwords);
}

function get_available_search_uuid(): string
{
    return ServiceLocator::get(SearchService::class)->getAvailableSearchUuid();
}

/**
 * @param array<mixed>      $rules
 * @return array<mixed>
 */
function save_search(array $rules, int|string|null $forked_from = null): array
{
    return ServiceLocator::get(SearchService::class)->saveSearch($rules, $forked_from);
}
