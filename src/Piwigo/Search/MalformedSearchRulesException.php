<?php

declare(strict_types=1);

namespace Piwigo\Search;

/**
 * Thrown by {@see Rules\SearchRules::fromJson()} / fromArray() when the
 * stored rules blob doesn't satisfy the schema.
 *
 * The WS layer catches this and surfaces a clean 400 instead of silently
 * returning empty results.
 */
final class MalformedSearchRulesException extends \RuntimeException
{
}
