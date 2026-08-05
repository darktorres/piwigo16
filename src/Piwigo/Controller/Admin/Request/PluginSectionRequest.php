<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Request;

use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET['section']` for PluginSubController::handle() (page slug
 * "plugin") -- the first Request DTO of this rewrite's own
 * `{Module}/Request/{Name}` convention (P26/SEC-40), mirroring the
 * `Projection` convention's `fromRow()`/`fromEntity()`/`toArray()` shape
 * with `fromGlobals()`/`fromArray()` instead: validates at construction,
 * built on `InputValidator::validate()`/`InputValidator::fail()` (per-item
 * pattern checks and the structural "at least 2 segments" check
 * respectively), and never returns a malformed instance -- every rejection
 * path is `never`-typed, same security-gate contract every other
 * `InputValidator` call site already has.
 *
 * Rejection messages now go through `InputValidator`'s own standard
 * "[Hacking attempt] ..." wording instead of this page's 3 original
 * bespoke messages ("invalid section token [...]" / "Invalid plugin URL" /
 * "Invalid plugin identifier") -- a deliberate, in-scope behavior change
 * for P26/SEC-40: no existing test asserted on the old wording (confirmed
 * via grep), and a uniform rejection message across every Request DTO is
 * the whole point of building on the shared validator instead of ad hoc
 * per-site fatalError() calls.
 *
 * Real bug found and fixed during the original PluginSubController port
 * (P23 sub-batch 6i-3), preserved here: the original file's
 * empty-segment-filtering loop mutated the array being iterated
 * (`unset($sections[$i]); $i--;`) without ever reindexing it. `unset()`
 * leaves a gap in the integer keys rather than shifting them down, so a
 * `section` value with a middle empty segment (e.g. `foo//bar`, two
 * consecutive slashes) made `$i` permanently point at an already-removed
 * key -- `empty()` on a missing offset returns true without warning, so
 * the loop re-entered the same unset()/$i--/continue branch forever,
 * hanging the PHP worker on every such request. Reproduced in isolation
 * (a 3-line PHP snippet outside the app, `timeout 3 php -r '...'`) before
 * concluding this was a real bug, not a hypothetical one -- confirmed the
 * process never returns. `admin.php?page=plugin&section=x//y` from any
 * Administrator-level (not even webmaster) session would trigger it, so
 * while access-gated, it's still a real, previously-undiscovered
 * self-inflictable denial-of-service. Fixed by filtering empty segments
 * with `array_filter()`/`array_values()` (which reindexes) instead of the
 * manual index-decrement loop -- verified behaviorally equivalent for
 * every non-buggy input (plain segments, a trailing empty segment from a
 * trailing slash, a literal `..` segment) and additionally handles the
 * previously-hanging middle-empty-segment case exactly the way the
 * original code's own intent (skip empty segments) already implied it
 * should.
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
                $validator->fail('[Hacking attempt] the input parameter "section" is not valid');
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
            $validator->fail('[Hacking attempt] the input parameter "section" is not valid');
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
