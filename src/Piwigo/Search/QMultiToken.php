<?php

declare(strict_types=1);

namespace Piwigo\Search;

/** Represents an expression of several words or sub expressions to be searched.*/
class QMultiToken implements \Stringable
{
    public bool $is_single = false;
    public int $modifier = 0;
    /** @var array<QSingleToken|QMultiToken> */
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

    private function push(string &$token, int &$modifier, mixed &$scope): void
    {
        $typedScope = $scope instanceof QSearchScope ? $scope : null;
        if (strlen((string) $token) || ($typedScope !== null && $typedScope->nullable)) {
            if ($typedScope !== null) {
                $modifier |= QST_BREAK;
            }
            $this->tokens[] = new QSingleToken($token, $modifier, $typedScope);
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
    public function parse_expression(string $q, int &$qi, int $level, QExpression $root): void
    {
        $crt_token = '';
        $crt_modifier = 0;
        $crt_scope = null; // ?QSearchScope

        for ($stop = false; !$stop && $qi < strlen($q); $qi++) {
            $ch = $q[$qi];
            if (($crt_modifier & QST_QUOTED) == 0) {
                switch ($ch) {
                    case '(':
                        if (strlen((string) $crt_token)) {
                            $this->push($crt_token, $crt_modifier, $crt_scope);
                        }
                        $sub = new QMultiToken();
                        $qi++;
                        $sub->parse_expression($q, $qi, $level + 1, $root);
                        $sub->modifier = $crt_modifier;
                        if ($crt_scope instanceof QSearchScope && $crt_scope->is_text) {
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
                        if ($crt_scope instanceof QSearchScope && !$crt_scope->is_text) {
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
                        if (!($crt_scope instanceof QSearchScope) || !$crt_scope->process_char($ch, $crt_token)) {
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
            if ($token instanceof QSingleToken) {
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
                if ($i < count($this->tokens) && $this->tokens[$i] instanceof QSingleToken) {
                    $this->tokens[$i]->modifier |= QST_BREAK;
                }
                $i--;
            }
        }

        if ($level > 0 && count($this->tokens) && $this->tokens[0] instanceof QSingleToken) {
            $this->tokens[0]->modifier |= QST_BREAK;
        }
    }

    /**
    * Applies recursively a search scope to all sub single tokens. We allow 'tag:(John Bill)' but we cannot evaluate
    * scopes on expressions so we rewrite as '(tag:John tag:Bill)'
    */
    public function apply_scope(QSearchScope $scope): void
    {
        for ($i = 0; $i < count($this->tokens); $i++) {
            if ($this->tokens[$i] instanceof QSingleToken) {
                if (!isset($this->tokens[$i]->scope)) {
                    $this->tokens[$i]->scope = $scope;
                }
            } elseif ($this->tokens[$i] instanceof QMultiToken) {
                $this->tokens[$i]->apply_scope($scope);
            }
        }
    }

    private static function priority(int $modifier): int
    {
        return $modifier & QST_OR ? 0 : 1;
    }

    /* because evaluations occur left to right, we ensure that 'a OR b c d' is interpreted as 'a OR (b c d)'*/
    public function check_operator_priority(): void
    {
        $crt_prio = 0;
        for ($i = 0; $i < count($this->tokens); $i++) {
            if ($this->tokens[$i] instanceof QMultiToken) {
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
                $sub = new QMultiToken();
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
