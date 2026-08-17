<?php

declare(strict_types=1);

namespace Piwigo\Common\ValueObject;

use Piwigo\Common\Enum\SortOrder;

/**
 * A photo sort order, as a value -- vocabulary and direction, never a raw
 * `"ORDER BY file ASC, id ASC"` string.
 *
 * Vocabulary and direction only -- no columns, no dialect functions, no DQL
 * paths, no connection. {@see \Piwigo\Db\SortRenderer} turns one of these
 * into SQL or DQL, because only it knows the platform. That split is what
 * lets this be an L0 value: `Config\CurrentConfig` holds two of them as
 * plain properties, which is a dependency downward, where the fused
 * predecessor forced the whole sort namespace up into L1 alongside the
 * config that reads it.
 *
 * The vocabulary is closed. `Controller\Admin\ConfigurationSubController`
 * builds `order_by`/`order_by_inside_category` from its own `$sort_fields`
 * allow-list, and the web-service `order` parameter maps through
 * {@see PhotoSortField}. There is no escape hatch for text outside it:
 * `order_by_custom` doesn't count -- it's reachable only as a `config`
 * row no code writes and no form exposes, and its only effects are to
 * disable the admin sort form and force four repositories onto raw DBAL. An
 * order this class cannot represent is invalid config data, and
 * {@see fromConfigFragment()} substitutes {@see default()} for it. A sort a
 * site genuinely needs belongs in `$sort_fields` and {@see PhotoSortField}.
 */
final readonly class PhotoSortOrder
{
    /**
     * @param list<array{field: PhotoSortField, dir: SortOrder}> $entries
     */
    private function __construct(
        private array $entries,
    ) {}

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * The default applied when no `order_by` has been configured -- matches
     * install/config.sql's own seed row.
     *
     * Parses the literal directly rather than through
     * {@see fromConfigFragment()}: that method falls back to this one, so
     * routing through it would recurse forever if the literal ever left the
     * vocabulary. The `?? []` is that guard, not a reachable branch.
     */
    public static function default(): self
    {
        return new self(self::parseConfigFragment('ORDER BY date_available DESC, file ASC, id ASC') ?? []);
    }

    /**
     * Parses a stored `order_by`/`order_by_inside_category` fragment.
     *
     * Text outside the vocabulary falls back to {@see default()} rather
     * than being spliced through verbatim: the only writer is the admin
     * form, which validates against `$sort_fields`, so unparseable text is
     * a corrupt or hand-edited `config` row. Substituting the default is
     * what an absent row already does, and matches how this config layer
     * treats every other invalid stored value (see
     * `CurrentConfig::$metadataKeywordSeparatorRegex`); throwing would take
     * the gallery down over one row read on every page render.
     */
    public static function fromConfigFragment(string $fragment): self
    {
        return self::tryFromConfigFragment($fragment) ?? self::default();
    }

    /**
     * {@see fromConfigFragment()} without the fallback: null means "this
     * text is not an order I can represent".
     *
     * For a stored config value the fallback is right; for a fragment a
     * caller *composed* it is not, because silently replacing it with the
     * default would reorder a listing the caller explicitly ordered. The one
     * such caller is `Calendar\CalendarRenderer::render()`, which prepends
     * the calendar's own date field ahead of the configured order and hands
     * the result to {@see \Piwigo\Db\SortRenderer::resolveDqlOrderBy()}.
     */
    public static function tryFromConfigFragment(string $fragment): ?self
    {
        if (trim($fragment) === '') {
            return self::none();
        }

        $parsed = self::parseConfigFragment($fragment);

        return $parsed === null ? null : new self($parsed);
    }

    /**
     * The web-service `order` parameter vocabulary. Unknown tokens are
     * dropped, matching the original `stdImageSqlOrder()` allow-list.
     */
    public static function fromWsOrderParam(string $order): self
    {
        if (trim($order) === '') {
            return self::none();
        }

        $matches = [];
        preg_match_all('/([a-z_]+) *(?:(asc|desc)(?:ending)?)? *(?:, *|$)/i', $order, $matches);

        $entries = [];
        for ($i = 0; $i < count($matches[1]); $i++) {
            $field = PhotoSortField::fromWsToken(strtolower($matches[1][$i]));
            if (! $field instanceof PhotoSortField) {
                continue;
            }

            $entries[] = [
                'field' => $field,
                'dir' => strtoupper($matches[2][$i]) === 'DESC' ? SortOrder::Desc : SortOrder::Asc,
            ];
        }

        return new self($entries);
    }

    /**
     * Parses a `"ORDER BY field dir, field dir"` fragment strictly against
     * the stored-config vocabulary, or null if any entry falls outside it.
     *
     * Kept here rather than on {@see PhotoSortField} because it is the inverse of
     * {@see toSortFieldTokens()} -- both speak the stored format, and a
     * parser that disagrees with the writer is how a round-trip silently
     * loses an order.
     *
     * @return list<array{field: PhotoSortField, dir: SortOrder}>|null
     */
    private static function parseConfigFragment(string $fragment): ?array
    {
        $body = preg_replace('/^\s*ORDER BY\s+/i', '', trim($fragment));
        if ($body === null || $body === '') {
            return null;
        }

        $entries = [];
        foreach (explode(',', $body) as $rawEntry) {
            // `RAND()`/`RANDOM()` is a function call carrying no direction,
            // so it never matches the "<field> ASC|DESC" shape below.
            // Recognising it keeps a random order structured instead of
            // dropping the whole fragment -- which matters because the two
            // platforms spell it differently and only the structured path
            // is rendered per platform.
            if (preg_match('/^\s*(?:RAND|RANDOM)\s*\(\s*\)\s*$/i', $rawEntry) === 1) {
                $entries[] = [
                    'field' => PhotoSortField::Random,
                    'dir' => SortOrder::Asc,
                ];

                continue;
            }

            if (preg_match('/^\s*`?([a-z_]+)`?\s+(ASC|DESC)\s*$/i', $rawEntry, $matches) !== 1) {
                return null;
            }

            $field = PhotoSortField::fromConfigToken(strtolower($matches[1]));
            if (! $field instanceof PhotoSortField) {
                return null;
            }

            $entries[] = [
                'field' => $field,
                'dir' => strtoupper($matches[2]) === 'DESC' ? SortOrder::Desc : SortOrder::Asc,
            ];
        }

        return $entries;
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * The ordered field/direction pairs, for {@see \Piwigo\Db\SortRenderer}
     * and for callers that need the order's structure rather than its text
     * (the first sort field, whether it mentions `rank`, ...).
     *
     * @return list<array{field: PhotoSortField, dir: SortOrder}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * The `$sort_fields`-shaped `"<field> <dir>"` tokens the admin
     * configuration form pre-selects from, in the stored format -- see
     * {@see PhotoSortField::configToken()} for why these are fixed literals
     * rather than platform-quoted identifiers.
     *
     * @return list<string>
     */
    public function toSortFieldTokens(): array
    {
        return array_map(
            static fn (array $entry): string => $entry['field']->configToken() . ' ' . $entry['dir']->value,
            $this->entries,
        );
    }

    /**
     * {@see toSortFieldTokens()} joined the way a stored `image_order` value
     * is written -- for the callers that persist or expose the order as
     * text rather than execute it (the WS album rows' `image_order`, which
     * otherwise reports an album's own stored string).
     *
     * Not SQL for execution: that is {@see \Piwigo\Db\SortRenderer::toSql()}.
     */
    public function toStoredBody(): string
    {
        return implode(', ', $this->toSortFieldTokens());
    }
}
