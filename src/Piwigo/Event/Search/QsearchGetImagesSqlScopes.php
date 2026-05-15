<?php

declare(strict_types=1);

namespace Piwigo\Event\Search;

/**
 * Typed event for legacy `qsearch_get_images_sql_scopes` (dispatch).
 *
 * Dispatched from: src/Piwigo/Search/SearchService.php
 */
final readonly class QsearchGetImagesSqlScopes
{
    /**
     * @param array<mixed> $clauses
     */
    public function __construct(
        public array $clauses,
        public \Piwigo\Search\QSingleToken $token,
        public \Piwigo\Search\QExpression $expr,
    ) {
    }

    /**
     * @param array<mixed> $clauses
     */
    public function withClauses(array $clauses): self
    {
        return new self($clauses, $this->token, $this->expr);
    }
}
