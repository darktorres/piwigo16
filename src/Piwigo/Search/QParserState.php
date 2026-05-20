<?php

declare(strict_types=1);

namespace Piwigo\Search;

/**
 * In-flight state of the QMultiToken parser. A fresh instance is
 * constructed at the top of each parseExpression() call (including
 * recursive descents into parenthesised sub-expressions) and the
 * per-character-class handlers mutate it directly.
 */
final class QParserState
{
    public string $token = '';
    public int $modifier = 0;
    public ?QSearchScope $scope = null;
}
