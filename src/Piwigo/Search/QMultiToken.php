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

    // In-flight parser state. Reset at the start of parseExpression()
    // and mutated by each per-char-class handler. Each QMultiToken
    // instance carries its own — recursion (sub-expressions via '(')
    // constructs a fresh QMultiToken with its own crt* slots.
    private string $crtToken = '';
    private int $crtModifier = 0;
    private ?QSearchScope $crtScope = null;

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

    private function push(): void
    {
        if (strlen($this->crtToken) || ($this->crtScope !== null && $this->crtScope->nullable)) {
            if ($this->crtScope !== null) {
                $this->crtModifier |= QST_BREAK;
            }
            $this->tokens[] = new QSingleToken($this->crtToken, $this->crtModifier, $this->crtScope);
        }
        $this->crtToken = '';
        $this->crtModifier = 0;
        $this->crtScope = null;
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
        $this->crtToken = '';
        $this->crtModifier = 0;
        $this->crtScope = null;

        for (; $qi < strlen($q); $qi++) {
            $ch = $q[$qi];
            if (($this->crtModifier & QST_QUOTED) !== 0) {
                $this->handleQuotedChar($ch, $q, $qi);
                continue;
            }
            $exitLoop = match ($ch) {
                '(' => $this->handleOpenParen($q, $qi, $level, $root),
                ')' => $this->handleCloseParen($level),
                ':' => $this->handleColon($root),
                '"' => $this->handleQuote(),
                '-' => $this->handleMinus(),
                '*' => $this->handleAsterisk(),
                '.' => $this->handleDot($q, $qi),
                default => $this->handleDefault($ch),
            };
            if ($exitLoop) {
                break;
            }
        }

        $this->push();
        $this->postProcessTokens($level);
    }

    // The handle* methods below all mutate $this->crtToken / crtModifier /
    // crtScope and are flagged @phpstan-impure so PHPStan re-reads those
    // properties after each call instead of remembering their pre-call
    // value (the loop's QST_QUOTED check would otherwise be const-folded).

    /** @phpstan-impure */
    private function handleOpenParen(string $q, int &$qi, int $level, QExpression $root): bool
    {
        if (strlen($this->crtToken)) {
            $this->push();
        }
        $sub = new QMultiToken();
        $qi++;
        $sub->parseExpression($q, $qi, $level + 1, $root);
        $sub->modifier = $this->crtModifier;
        if ($this->crtScope instanceof QSearchScope && $this->crtScope->is_text) {
            $sub->applyScope($this->crtScope); // eg. 'tag:(John OR Bill)'
        }
        $this->tokens[] = $sub;
        $this->crtModifier = 0;
        $this->crtScope = null;
        return false;
    }

    /** Returns true when the caller should exit the outer parse loop. */
    private function handleCloseParen(int $level): bool
    {
        return $level > 0;
    }

    /** @phpstan-impure */
    private function handleColon(QExpression $root): bool
    {
        $scope = $root->scopes[strtolower($this->crtToken)] ?? null;
        if (!isset($scope) || isset($this->crtScope)) { // white space
            $this->push();
        } else {
            $this->crtToken = '';
            $this->crtScope = $scope;
        }
        return false;
    }

    /** @phpstan-impure */
    private function handleQuote(): bool
    {
        if (strlen($this->crtToken)) {
            $this->push();
        }
        $this->crtModifier |= QST_QUOTED;
        return false;
    }

    /** @phpstan-impure */
    private function handleMinus(): bool
    {
        if (strlen($this->crtToken) || isset($this->crtScope)) {
            $this->crtToken .= '-';
        } else {
            $this->crtModifier |= QST_NOT;
        }
        return false;
    }

    /** @phpstan-impure */
    private function handleAsterisk(): bool
    {
        if (strlen($this->crtToken)) {
            $this->crtToken .= '*'; // wildcard end later
        } else {
            $this->crtModifier |= QST_WILDCARD_BEGIN;
        }
        return false;
    }

    /** @phpstan-impure */
    private function handleDot(string $q, int $qi): bool
    {
        if ($this->crtScope instanceof QSearchScope && !$this->crtScope->is_text) {
            $this->crtToken .= '.';
            return false;
        }
        if (strlen($this->crtToken) && preg_match('/[0-9]/', substr($this->crtToken, -1))
          && $qi + 1 < strlen($q) && preg_match('/[0-9]/', $q[$qi + 1])) { // dot between digits is not a separator e.g. F2.8
            $this->crtToken .= '.';
            return false;
        }
        // else white space go on..
        return $this->handleDefault('.');
    }

    /** @phpstan-impure */
    private function handleDefault(string $ch): bool
    {
        if ($this->crtScope instanceof QSearchScope && $this->crtScope->processChar($ch, $this->crtToken)) {
            return false;
        }
        if (in_array($ch, [' ', ',', '.', ';', '!', '?'], true)) { // white space
            $this->push();
        } else {
            $this->crtToken .= $ch;
        }
        return false;
    }

    /** @phpstan-impure */
    private function handleQuotedChar(string $ch, string $q, int &$qi): void
    {
        if ($ch !== '"') {
            $this->crtToken .= $ch;
            return;
        }
        if ($qi + 1 < strlen($q) && $q[$qi + 1] == '*') {
            $this->crtModifier |= QST_WILDCARD_END;
            $qi++;
        }
        $this->push();
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
