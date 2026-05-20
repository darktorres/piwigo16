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

    #[\Override]
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
                $s .= (string) $this->tokens[$i];
                $s .= ')';
            } else {
                $s .= (string) $this->tokens[$i];
            }
        }
        return $s;
    }

    private function push(QParserState $state): void
    {
        if (strlen($state->token) || ($state->scope !== null && $state->scope->nullable)) {
            if ($state->scope !== null) {
                $state->modifier |= QST_BREAK;
            }
            $this->tokens[] = new QSingleToken($state->token, $state->modifier, $state->scope);
        }
        $state->token = '';
        $state->modifier = 0;
        $state->scope = null;
    }

    /**
    * Parses the input query string by tokenizing the input, generating the modifiers (and/or/not/quotation/wildcards...).
    * Recursivity occurs when parsing ()
    * @param string $q the actual query to be parsed
    * @param int $qi the character index in $q where to start parsing
    * @param int $level the depth from root in the tree (number of opened and unclosed opening brackets)
    */
    public function parseExpression(string $q, int &$qi, int $level, QExpression $root): void
    {
        $state = new QParserState();

        for (; $qi < strlen($q); $qi++) {
            $ch = $q[$qi];
            if (($state->modifier & QST_QUOTED) !== 0) {
                $this->handleQuotedChar($state, $ch, $q, $qi);
                continue;
            }
            $exitLoop = match ($ch) {
                '(' => $this->handleOpenParen($state, $q, $qi, $level, $root),
                ')' => $level > 0,
                ':' => $this->handleColon($state, $root),
                '"' => $this->handleQuote($state),
                '-' => $this->handleMinus($state),
                '*' => $this->handleAsterisk($state),
                '.' => $this->handleDot($state, $q, $qi),
                default => $this->handleDefault($state, $ch),
            };
            if ($exitLoop) {
                break;
            }
        }

        $this->push($state);
        $this->postProcessTokens($level);
    }

    private function handleOpenParen(QParserState $state, string $q, int &$qi, int $level, QExpression $root): bool
    {
        if (strlen($state->token)) {
            $this->push($state);
        }
        $sub = new QMultiToken();
        $qi++;
        $sub->parseExpression($q, $qi, $level + 1, $root);
        $sub->modifier = $state->modifier;
        if ($state->scope instanceof QSearchScope && $state->scope->is_text) {
            $sub->applyScope($state->scope); // eg. 'tag:(John OR Bill)'
        }
        $this->tokens[] = $sub;
        $state->modifier = 0;
        $state->scope = null;
        return false;
    }

    private function handleColon(QParserState $state, QExpression $root): bool
    {
        $scope = $root->scopes[strtolower($state->token)] ?? null;
        if (!isset($scope) || isset($state->scope)) { // white space
            $this->push($state);
        } else {
            $state->token = '';
            $state->scope = $scope;
        }
        return false;
    }

    private function handleQuote(QParserState $state): bool
    {
        if (strlen($state->token)) {
            $this->push($state);
        }
        $state->modifier |= QST_QUOTED;
        return false;
    }

    private function handleMinus(QParserState $state): bool
    {
        if (strlen($state->token) || isset($state->scope)) {
            $state->token .= '-';
        } else {
            $state->modifier |= QST_NOT;
        }
        return false;
    }

    private function handleAsterisk(QParserState $state): bool
    {
        if (strlen($state->token)) {
            $state->token .= '*'; // wildcard end later
        } else {
            $state->modifier |= QST_WILDCARD_BEGIN;
        }
        return false;
    }

    private function handleDot(QParserState $state, string $q, int $qi): bool
    {
        if ($state->scope instanceof QSearchScope && !$state->scope->is_text) {
            $state->token .= '.';
            return false;
        }
        if (strlen($state->token) && preg_match('/[0-9]/', substr($state->token, -1))
          && $qi + 1 < strlen($q) && preg_match('/[0-9]/', $q[$qi + 1])) { // dot between digits is not a separator e.g. F2.8
            $state->token .= '.';
            return false;
        }
        // else white space go on..
        return $this->handleDefault($state, '.');
    }

    private function handleDefault(QParserState $state, string $ch): bool
    {
        if ($state->scope instanceof QSearchScope && $state->scope->processChar($ch, $state->token)) {
            return false;
        }
        if (in_array($ch, [' ', ',', '.', ';', '!', '?'], true)) { // white space
            $this->push($state);
        } else {
            $state->token .= $ch;
        }
        return false;
    }

    private function handleQuotedChar(QParserState $state, string $ch, string $q, int &$qi): void
    {
        if ($ch !== '"') {
            $state->token .= $ch;
            return;
        }
        if ($qi + 1 < strlen($q) && $q[$qi + 1] == '*') {
            $state->modifier |= QST_WILDCARD_END;
            $qi++;
        }
        $this->push($state);
    }

    private function postProcessTokens(int $level): void
    {
        for ($i = 0; $i < count($this->tokens); $i++) {
            $token = $this->tokens[$i];
            $remove = false;
            if ($token instanceof QSingleToken) {
                if (($token->modifier & QST_QUOTED) == 0
                  && str_ends_with($token->term, '*')) {
                    $token->term = rtrim($token->term, '*');
                    $token->modifier |= QST_WILDCARD_END;
                }

                if (!isset($token->scope)
                  && ($token->modifier & (QST_QUOTED | QST_WILDCARD)) == 0) {
                    $lowerTerm = strtolower($token->term);
                    if ($lowerTerm === 'not') {
                        if ($i + 1 < count($this->tokens)) {
                            $this->tokens[$i + 1]->modifier |= QST_NOT;
                        }
                        $token->term = '';
                    } elseif ($lowerTerm === 'or') {
                        if ($i + 1 < count($this->tokens)) {
                            $this->tokens[$i + 1]->modifier |= QST_OR;
                        }
                        $token->term = '';
                    } elseif ($lowerTerm === 'and') {
                        $token->term = '';
                    }
                }

                if (!strlen($token->term)
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
    public function applyScope(QSearchScope $scope): void
    {
        for ($i = 0; $i < count($this->tokens); $i++) {
            if ($this->tokens[$i] instanceof QSingleToken) {
                if (!isset($this->tokens[$i]->scope)) {
                    $this->tokens[$i]->scope = $scope;
                }
            } else {
                $this->tokens[$i]->applyScope($scope);
            }
        }
    }

    private static function priority(int $modifier): int
    {
        return $modifier & QST_OR ? 0 : 1;
    }

    /* because evaluations occur left to right, we ensure that 'a OR b c d' is interpreted as 'a OR (b c d)'*/
    public function checkOperatorPriority(): void
    {
        $crt_prio = 0;
        for ($i = 0; $i < count($this->tokens); $i++) {
            if ($this->tokens[$i] instanceof QMultiToken) {
                $this->tokens[$i]->checkOperatorPriority();
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

                $sub->checkOperatorPriority();
            } else {
                $crt_prio = $prio;
            }
        }
    }
}
