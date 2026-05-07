<?php

declare(strict_types=1);

namespace Piwigo\Search;

class QExpression extends QMultiToken
{
    /** @var array<string, QSearchScope> */
    public array $scopes = [];
    /** @var array<QSingleToken> */
    public array $stokens = [];
    /** @var int[] */
    public array $stoken_modifiers = [];

    /** @param array<QSearchScope> $scopes */
    public function __construct(string $q, array $scopes)
    {
        foreach ($scopes as $scope) {
            $this->scopes[$scope->id] = $scope;
            foreach ($scope->aliases as $alias) {
                $this->scopes[strtolower($alias)] = $scope;
            }
        }
        $i = 0;
        $this->parseExpression($q, $i, 0, $this);
        //manipulate the tree so that 'a OR b c' is the same as 'b c OR a'
        $this->checkOperatorPriority();
        $this->buildSingleTokens($this, 0);
    }

    private function buildSingleTokens(QMultiToken $expr, int $this_is_not): void
    {
        for ($i = 0; $i < count($expr->tokens); $i++) {
            $token = $expr->tokens[$i];
            $crt_is_not = ($token->modifier ^ $this_is_not) & QST_NOT; // no negation OR double negation -> no negation;

            if ($token instanceof QSingleToken) {
                $token->idx = count($this->stokens);
                $this->stokens[] = $token;

                $modifier = $token->modifier;
                if ($crt_is_not) {
                    $modifier |= QST_NOT;
                } else {
                    $modifier &= ~QST_NOT;
                }
                $this->stoken_modifiers[] = $modifier;
            } elseif ($token instanceof QMultiToken) {
                $this->buildSingleTokens($token, $crt_is_not);
            }
        }
    }
}
