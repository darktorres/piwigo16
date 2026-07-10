<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Search;

class QExpression extends QMultiToken
{
    /**
     * @var array<string, QSearchScope>
     */
    public $scopes = [];

    /**
     * @var array<int, QSingleToken>
     */
    public $stokens = [];

    /**
     * @var array<int, int>
     */
    public $stoken_modifiers = [];

    /**
     * @param QSearchScope[] $scopes
     */
    public function __construct(
        string $q,
        array $scopes
    ) {
        foreach ($scopes as $scope) {
            $this->scopes[$scope->id] = $scope;
            foreach ($scope->aliases as $alias) {
                $this->scopes[strtolower($alias)] = $scope;
            }
        }
        $i = 0;
        $this->parse_expression($q, $i, 0, $this);
        // manipulate the tree so that 'a OR b c' is the same as 'b c OR a'
        $this->check_operator_priority();
        $this->build_single_tokens($this, 0);
    }

    private function build_single_tokens(QMultiToken $expr, int $this_is_not): void
    {
        for ($i = 0; $i < count($expr->tokens); $i++) {
            $token = $expr->tokens[$i];
            $crt_is_not = ($token->modifier ^ $this_is_not) & QST_NOT; // no negation OR double negation -> no negation;

            if ($token instanceof QSingleToken) {
                $token->idx = count($this->stokens);
                $this->stokens[] = $token;

                $modifier = $token->modifier;
                if ((bool) $crt_is_not) {
                    $modifier |= QST_NOT;
                } else {
                    $modifier &= ~QST_NOT;
                }
                $this->stoken_modifiers[] = $modifier;
            } else {
                $this->build_single_tokens($token, $crt_is_not);
            }
        }
    }
}
