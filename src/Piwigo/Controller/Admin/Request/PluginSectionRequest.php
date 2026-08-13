<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Request;

use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET['section']` for PluginSubController::handle() (page
 * slug "plugin"), part of this codebase's `{Module}/Request/{Name}`
 * convention: validates at construction, built on
 * `InputValidator::validate()`/`InputValidator::fail()` (per-item pattern
 * checks and the structural "at least 2 segments" check respectively),
 * and never returns a malformed instance -- every rejection path is
 * `never`-typed.
 *
 * Per-segment charset rejections go through `InputValidator`'s own
 * standard "Invalid request parameter ..." wording; the path-traversal
 * and segment-count guards below reject with their own firmer
 * "Request rejected ..." wording instead.
 *
 * Empty segments (e.g. from a `foo//bar` value with two consecutive
 * slashes, or a trailing slash) are dropped via
 * `array_filter()`/`array_values()`, which reindexes the resulting array.
 * A manual index-decrementing loop over a simultaneously-`unset()`
 * array does not reindex, so it can spin forever on a middle empty
 * segment.
 */
final readonly class PluginSectionRequest
{
    /**
     * @param non-empty-list<string> $sections slash-separated path segments,
     *   $sections[0] === $pluginId
     */
    private function __construct(
        public string $pluginId,
        public array $sections,
    ) {}

    public static function fromGlobals(InputValidator $inputValidator): self
    {
        return self::fromArray($_GET, $inputValidator);
    }

    /**
     * @param array<int|string, mixed> $source
     */
    public static function fromArray(array $source, InputValidator $inputValidator): self
    {
        $section_param = $source['section'] ?? '';
        $section_str = is_string($section_param) ? $section_param : '';

        $sections = array_values(array_filter(
            explode('/', $section_str),
            static fn (string $segment): bool => $segment !== '',
        ));

        $validator = $inputValidator;
        foreach ($sections as $section) {
            // Explicit "not literally .." check (not folded into the regex
            // below): a bare charset pattern can't distinguish "a dot
            // appears somewhere in this segment" from "this whole segment
            // is exactly the two-dot path-traversal token" without a
            // fragile lookahead -- kept as a plain, obviously-correct
            // comparison instead.
            if ($section === '..') {
                $validator->fail('Request rejected: invalid characters in "section"');
            }
            // Known, accepted trade-off: InputValidator::emptyValue()
            // treats a literal '0' the same as an absent value, so a
            // (vanishingly unlikely) plugin path segment named exactly "0"
            // would be rejected here even though it matches the pattern --
            // the original bare preg_match() had no such case. Not worth
            // special-casing for a segment name real plugins never use.
            $validator->validate('segment', [
                'segment' => $section,
            ], false, '/^[a-zA-Z0-9_.-]+$/', true);
        }

        if (count($sections) < 2) {
            $validator->fail('Request rejected: invalid characters in "section"');
        }

        $plugin_id = $sections[0];
        // plugin_id itself is stricter than a general segment (no dots),
        // matching PluginLoader's own plugin_id charset assumption.
        $validator->validate('plugin_id', [
            'plugin_id' => $plugin_id,
        ], false, '/^[\w-]+$/', true);

        return new self($plugin_id, $sections);
    }
}
