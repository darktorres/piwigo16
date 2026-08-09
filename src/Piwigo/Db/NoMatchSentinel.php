<?php

declare(strict_types=1);

namespace Piwigo\Db;

/**
 * The classic Piwigo "avoid an empty SQL `IN (...)` clause" placeholder.
 * Every real id column in this schema is unsigned/auto-increment
 * starting at 1, so `-1` is guaranteed to never equal a real row --
 * `WHERE id IN (-1)` is syntactically valid SQL that matches nothing,
 * unlike `WHERE id IN ()` (a real syntax error on both MySQL and
 * Postgres). Substitute this whenever an id list that would otherwise
 * build an empty `IN (...)` clause needs to stay syntactically safe
 * while guaranteeing zero matches.
 *
 * Was independently reinvented as a bare `-1`/`'-1'` literal at ~8 call
 * sites across Filter/Search/Category/Url/Comment/Picture before this
 * constant existed -- not one shared domain concept, just the same
 * low-level SQL-safety technique applied separately by each feature.
 */
final class NoMatchSentinel
{
    public const int ID = -1;

    /**
     * String form, for contexts that persist/compare a CSV-composed or
     * session-stored value (e.g. {@see \Piwigo\Core\FilterState::visibleImages()}),
     * not a real int/array.
     */
    public const string ID_STRING = '-1';
}
