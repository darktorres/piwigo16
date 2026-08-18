<?php

declare(strict_types=1);

namespace Piwigo\Page;

use Piwigo\Core\Lang;
use Piwigo\Core\PageState;

/**
 * Builds the typed `{data, strings}` JSON-data-island payload
 * (docs/PLAN.md's P37), replacing the old `PageState::$bodyData`/
 * `BODY_DATA` `data-infos` attribute and the hand-rolled
 * `escapeJavascript`/`json_encode(translate(...))` embedding pattern.
 *
 * `data` merges `PageState::$bodyData` (the pre-existing legacy bag,
 * still written in bulk by `SectionPopulator`) with `$exposedData`
 * (the new, incremental per-key declaration surface) into one flat
 * object -- `$exposedData` wins on a key collision, the more specific,
 * intentional declaration overriding the bulk legacy one.
 *
 * `strings` resolves every declared translation key through
 * `Piwigo\Core\Lang::t()` -- the same real path every `{_ '...'}`
 * template tag already goes through
 * (`PiwigoExtension::translate()` -> `Lang::t()` ->
 * `Translator::translate()`), so a key exposed here resolves
 * identically to what the same key would give a template tag on the
 * same page. No args are ever forwarded (`Lang::t($key)` only) --
 * every real call site in this codebase leaves `%d`/`%s` placeholders
 * literal for client JS to substitute itself, matching how
 * `escapeJavascript`/`json_encode(translate(...))` sites already work
 * today.
 *
 * Constructed fresh at payload-build time (`Template::getPageDataScript()`,
 * called from `footer.latte` -- always the last template parsed in
 * every real render sequence this codebase has, so every
 * `exposeData()`/`exposeString()` call made anywhere earlier in the
 * same request, across any number of `Template::flush()` cycles, has
 * already landed in `PageState` by the time this reads it).
 */
final readonly class PageDataPayload
{
    public function __construct(
        private PageState $pageState,
        private Lang $lang,
    ) {}

    /**
     * @return array{data: array<string, mixed>, strings: array<string, string>}
     */
    public function toArray(): array
    {
        $strings = [];
        foreach (array_keys($this->pageState->exposedStringKeys) as $key) {
            $strings[$key] = $this->lang->t($key);
        }

        return [
            'data' => [...$this->pageState->bodyData, ...$this->pageState->exposedData],
            'strings' => $strings,
        ];
    }

    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP,
        );
    }
}
