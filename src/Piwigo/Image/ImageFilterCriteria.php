<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\DBAL\ParameterType;
use Piwigo\Permission\SqlCondition;

/**
 * SQL-modernization audit, Item 14 Sub-phase C3: typed replacement for
 * {@see \Piwigo\Ws\WsHelper::stdImageSqlFilterCriteria()}'s own former raw
 * `Piwigo\Permission\SqlCondition` return -- the shared "f_*" range-filter
 * set merged into every WS method that exposes generic image filtering
 * (`pwg.images.search`, `pwg.categories.getImages`,
 * `pwg.getMissingDerivatives`, `pwg.tags.getImages`), confirmed exactly 11
 * fields by reading that method's own former full body: 5 simple numeric
 * range pairs (rate/hit/ratio, each min+max), 1 upper-bound-only level
 * check, and 2 date range pairs (available/created). Every field null =
 * "no restriction on this dimension" -- each consuming repository method
 * applies only the ones actually set, same "each repository translates its
 * own subset" pattern {@see \Piwigo\Permission\PermissionCriteria} (Item 14
 * Sub-phase C1) also uses.
 */
final readonly class ImageFilterCriteria
{
    public function __construct(
        public ?float $minRate = null,
        public ?float $maxRate = null,
        public ?int $minHit = null,
        public ?int $maxHit = null,
        public ?string $minDateAvailable = null,
        public ?string $maxDateAvailable = null,
        public ?string $minDateCreated = null,
        public ?string $maxDateCreated = null,
        public ?float $minRatio = null,
        public ?float $maxRatio = null,
        public ?int $maxLevel = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->minRate === null
            && $this->maxRate === null
            && $this->minHit === null
            && $this->maxHit === null
            && $this->minDateAvailable === null
            && $this->maxDateAvailable === null
            && $this->minDateCreated === null
            && $this->maxDateCreated === null
            && $this->minRatio === null
            && $this->maxRatio === null
            && $this->maxLevel === null;
    }

    /**
     * Raw-SQL fragment form, for the consumers that stay on plain DBAL for
     * their own unrelated reasons (`SQL_CALC_FOUND_ROWS`, a DBAL
     * `QueryBuilder`-based repository) rather than DQL --
     * {@see \Piwigo\Image\ImageRepository::findWithConditionsPaginated()},
     * {@see \Piwigo\Tag\TagRepository::findImageIdsForTags()}. Every bound
     * placeholder is prefixed `imgFilter` so this fragment can always be
     * combined with a caller's other bound fragments (permission
     * conditions, an `id IN (...)` restriction, ...) in the same query
     * with no name collision -- the same guarantee the old runtime
     * monotonic-suffix counter gave, now just a fixed, readable prefix
     * since each call site only ever applies one `ImageFilterCriteria` to
     * one query.
     */
    public function toSqlCondition(string $tblPrefix = ''): SqlCondition
    {
        $clauses = [];
        $parameters = [];
        $types = [];

        if ($this->minRate !== null) {
            $clauses[] = $tblPrefix . 'rating_score >= :imgFilterMinRate';
            $parameters['imgFilterMinRate'] = $this->minRate;
        }
        if ($this->maxRate !== null) {
            $clauses[] = $tblPrefix . 'rating_score <= :imgFilterMaxRate';
            $parameters['imgFilterMaxRate'] = $this->maxRate;
        }
        if ($this->minHit !== null) {
            $clauses[] = $tblPrefix . 'hit >= :imgFilterMinHit';
            $parameters['imgFilterMinHit'] = $this->minHit;
            $types['imgFilterMinHit'] = ParameterType::INTEGER;
        }
        if ($this->maxHit !== null) {
            $clauses[] = $tblPrefix . 'hit <= :imgFilterMaxHit';
            $parameters['imgFilterMaxHit'] = $this->maxHit;
            $types['imgFilterMaxHit'] = ParameterType::INTEGER;
        }
        if ($this->minDateAvailable !== null) {
            $clauses[] = $tblPrefix . 'date_available >= :imgFilterMinDateAvailable';
            $parameters['imgFilterMinDateAvailable'] = $this->minDateAvailable;
        }
        if ($this->maxDateAvailable !== null) {
            $clauses[] = $tblPrefix . 'date_available < :imgFilterMaxDateAvailable';
            $parameters['imgFilterMaxDateAvailable'] = $this->maxDateAvailable;
        }
        if ($this->minDateCreated !== null) {
            $clauses[] = $tblPrefix . 'date_creation >= :imgFilterMinDateCreated';
            $parameters['imgFilterMinDateCreated'] = $this->minDateCreated;
        }
        if ($this->maxDateCreated !== null) {
            $clauses[] = $tblPrefix . 'date_creation < :imgFilterMaxDateCreated';
            $parameters['imgFilterMaxDateCreated'] = $this->maxDateCreated;
        }
        // pgsql support pass: real bug found live -- width/height are
        // plain integer columns, and while MySQL's `/` operator always
        // computes in decimal context, PostgreSQL's `/` on two integer
        // operands truncates to an integer (same real bug already fixed
        // in SearchService's/FilterResolver's/ImageRepository's own
        // ratio filters). `width*1.0` forces decimal-context arithmetic
        // on both platforms without needing a CAST. NULLIF(height, 0)
        // additionally guards a genuinely zero height (a real, if
        // degenerate, row) -- MySQL's `/` silently returns NULL for a
        // zero divisor, but Postgres raises a real "division by zero"
        // error instead, confirmed live via SearchService's own identical
        // ratio-bucket clause.
        if ($this->minRatio !== null) {
            $clauses[] = $tblPrefix . 'width*1.0/NULLIF(' . $tblPrefix . 'height, 0) >= :imgFilterMinRatio';
            $parameters['imgFilterMinRatio'] = $this->minRatio;
        }
        if ($this->maxRatio !== null) {
            $clauses[] = $tblPrefix . 'width*1.0/NULLIF(' . $tblPrefix . 'height, 0) <= :imgFilterMaxRatio';
            $parameters['imgFilterMaxRatio'] = $this->maxRatio;
        }
        if ($this->maxLevel !== null) {
            $clauses[] = $tblPrefix . 'level <= :imgFilterMaxLevel';
            $parameters['imgFilterMaxLevel'] = $this->maxLevel;
            $types['imgFilterMaxLevel'] = ParameterType::INTEGER;
        }

        return new SqlCondition($clauses === [] ? '' : '(' . implode(' AND ', $clauses) . ')', $parameters, $types);
    }
}
