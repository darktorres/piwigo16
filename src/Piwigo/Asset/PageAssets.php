<?php

declare(strict_types=1);

namespace Piwigo\Asset;

use LogicException;

/**
 * Collects `AssetContribution`s from the three sources
 * `docs/PLAN.md`'s P36 section describes (a theme's own base assets, a
 * page's own conditional assets, plugin contributions via
 * `Event\GetPageAssets`), then runs one final ordering pass and
 * resolves each surviving entry through `ViteManifest` -- falling back
 * to the raw, un-bundled path for anything that isn't a real Vite entry
 * yet (everything except `vitals` today).
 *
 * No real caller wires all three sources together yet -- see
 * `docs/PLAN.md`'s P36 section on why real template migration is
 * explicitly out of scope for this phase. Every behavior below is
 * preserved from `ScriptLoader`/`CssLoader`'s real, currently-live
 * logic, confirmed against the real 76-template corpus rather than
 * reinvented:
 *
 * - CSS: plain stable sort by `$order` (real range found in templates:
 *   -999 to 100). `CssLoader::cmpByOrder()`'s own `order * 1000 +
 *   $counter` scaling existed only to fake stability on a PHP version
 *   where `uasort()` wasn't guaranteed stable -- `uasort()`/`usort()`
 *   have been stable since PHP 8.0, so registration order is already
 *   preserved for equal `$order` values without that trick.
 * - Scripts: head/footer/async partition, each group
 *   dependency-respecting topologically sorted
 *   (`ScriptLoader::computeScriptTopologicalOrder()`'s real logic --
 *   confirmed real multi-level chains exist, e.g.
 *   `jquery.ui.timepicker-addon` -> `jquery.ui.datepicker` -> its own
 *   transitive deps). A dependency can't load more loosely than its
 *   dependent (`ScriptLoader::checkLoadDep()`'s real "if B requires A,
 *   A->loadMode <= B->loadMode" promotion).
 * - The jQuery-UI known-script resolver
 *   (`ScriptLoader::fillWellKnown()`/`loadKnownRequiredScript()`) is
 *   preserved, not dropped: confirmed live that `rating_user.latte`
 *   registers `jquery.ui.tooltip` with zero explicit path/require,
 *   `jquery.ui.datepicker` is never explicitly registered anywhere, and
 *   `jquery.ui.effect-blind` (required by 3 real call sites, e.g.
 *   `updates_ext.latte`'s `footerScript($tmp, 'jquery.ui.effect-blind,
 *   ...')`) is also never explicitly registered -- all three resolve
 *   purely by naming convention today.
 * - Both kinds dedupe by id: a later `add()` with an id already
 *   present merges into the existing contribution rather than
 *   replacing or duplicating it (`ScriptLoader::add()`'s real merge --
 *   union `dependsOn`, promote to the more eager `loadMode`, keep the
 *   higher `version` -- and `CssLoader::add()`'s real "keep whichever
 *   has the higher order, or higher version on a tie").
 */
final class PageAssets
{
    /**
     * @var array<string, AssetContribution>
     */
    private array $scripts = [];

    /**
     * @var array<string, AssetContribution>
     */
    private array $css = [];

    /**
     * `ScriptLoader::$inlineScripts`'s own replacement -- no id-based
     * dedup (`addInline()`'s real behavior: every call unconditionally
     * appends), so a plain list rather than keyed like `$scripts`/`$css`
     * above.
     *
     * @var list<AssetContribution>
     */
    private array $inlineScripts = [];

    /**
     * Path resolved by id (once), matching
     * `ScriptLoader::$known_paths` -- both sides of a dependency chain
     * (`jquery.ui.slider` requiring `jquery.ui`, `jquery.ui.datepicker`
     * required by `jquery.ui.timepicker-addon`) can be registered
     * without ever passing an explicit `path:`.
     *
     * @var array<string, string>
     */
    private static array $knownPaths = [
        'core.scripts' => 'themes/default/js/scripts.js',
        'jquery' => 'themes/default/js/jquery.min.js',
        'jquery.ui' => 'themes/default/js/ui/minified/jquery.ui.core.min.js',
        'jquery.ui.effect' => 'themes/default/js/ui/minified/jquery.ui.effect.min.js',
    ];

    /**
     * Matches `ScriptLoader::$ui_core_dependencies` -- the 3 jQuery-UI
     * "core" scripts every other `jquery.ui.*` script transitively
     * needs.
     *
     * @var array<string, list<string>>
     */
    private static array $uiCoreDependencies = [
        'jquery.ui.widget' => ['jquery'],
        'jquery.ui.position' => ['jquery'],
        'jquery.ui.mouse' => ['jquery', 'jquery.ui', 'jquery.ui.widget'],
    ];

    public function __construct(
        private readonly ViteManifest $viteManifest,
    ) {}

    /**
     * `CssLoader::clear()`'s own scope -- CSS only, not scripts: matches
     * `Template::finalizeHtml()`'s own real, test-covered contract
     * ("a second call does not re-emit already-flushed CSS"), which has
     * no script-side equivalent (`Template` locks script-placeholder
     * substitution behind its own one-shot flag instead of clearing
     * `PageAssets`' registered scripts).
     */
    public function clearCss(): void
    {
        $this->css = [];
    }

    public function add(AssetContribution $contribution): void
    {
        match ($contribution->kind) {
            AssetKind::Script => $this->addScript($contribution),
            AssetKind::Css => $this->addCss($contribution),
            AssetKind::InlineScript => $this->addInlineScript($contribution),
        };
    }

    /**
     * `ScriptLoader::addInline()`'s own replacement: no dedup (plain
     * append), but a `dependsOn` id still needs the same
     * known-by-naming-convention auto-registration `resolveMissingDependencies()`
     * already gives a real script's own `dependsOn` below.
     */
    private function addInlineScript(AssetContribution $contribution): void
    {
        $this->inlineScripts[] = $contribution;
        $this->resolveMissingDependencies($contribution);
    }

    private function addScript(AssetContribution $contribution): void
    {
        $existing = $this->scripts[$contribution->id] ?? null;
        $merged = $existing === null ? self::fillKnownScript($contribution) : self::mergeScripts($existing, $contribution);

        $this->scripts[$merged->id] = $merged;
        $this->resolveMissingDependencies($merged);
    }

    /**
     * Fills in a script's path/dependencies from the naming-convention
     * resolver (`knownPath()`/`knownRequires()` below) the first time
     * it's registered -- `ScriptLoader::add()`'s real behavior: every
     * newly-added script goes through `fillWellKnown()` regardless of
     * whether it was registered directly (e.g. `rating_user.latte`'s
     * bare `combineScript(id: 'jquery.ui.tooltip', load: 'footer')`,
     * with no `path:`/`require:` at all) or reached only as someone
     * else's dependency.
     */
    private static function fillKnownScript(AssetContribution $contribution): AssetContribution
    {
        if (! self::isKnownId($contribution->id)) {
            return $contribution;
        }

        $path = $contribution->path !== '' ? $contribution->path : self::knownPath($contribution->id);
        $dependsOn = array_values(array_unique([...$contribution->dependsOn, ...self::knownRequires($contribution->id)]));

        if ($path === $contribution->path && $dependsOn === $contribution->dependsOn) {
            return $contribution;
        }

        return AssetContribution::script(
            id: $contribution->id,
            path: $path,
            loadMode: $contribution->loadMode ?? LoadMode::Header,
            dependsOn: $dependsOn,
            version: $contribution->version,
        );
    }

    private static function mergeScripts(AssetContribution $existing, AssetContribution $incoming): AssetContribution
    {
        $dependsOn = array_values(array_unique([...$existing->dependsOn, ...$incoming->dependsOn]));

        $loadMode = $existing->loadMode ?? $incoming->loadMode;
        if ($incoming->loadMode !== null && ($loadMode === null || $incoming->loadMode->value < $loadMode->value)) {
            $loadMode = $incoming->loadMode;
        }

        return AssetContribution::script(
            id: $existing->id,
            path: $incoming->path !== '' ? $incoming->path : $existing->path,
            loadMode: $loadMode ?? LoadMode::Header,
            dependsOn: $dependsOn,
            version: self::higherVersion($existing->version, $incoming->version),
        );
    }

    private function addCss(AssetContribution $contribution): void
    {
        $existing = $this->css[$contribution->id] ?? null;
        if ($existing === null || self::shouldReplaceCss($existing, $contribution)) {
            $this->css[$contribution->id] = $contribution;
        }
    }

    private static function shouldReplaceCss(AssetContribution $existing, AssetContribution $incoming): bool
    {
        if ($existing->order !== $incoming->order) {
            return $incoming->order > $existing->order;
        }

        return self::higherVersion($existing->version, $incoming->version) === $incoming->version
            && $existing->version !== $incoming->version;
    }

    private static function higherVersion(string|false $a, string|false $b): string|false
    {
        if ($a === false || $b === false) {
            return false;
        }

        return version_compare($a, $b) >= 0 ? $a : $b;
    }

    /**
     * Auto-registers an undeclared known-by-naming-convention dependency
     * -- `ScriptLoader::loadKnownRequiredScript()`'s real, live
     * behavior. Recurses through `$uiCoreDependencies` (via
     * `addScript()` -> `fillKnownScript()` -> back here) so a chain like
     * `jquery.ui.datepicker` -> `jquery.ui.widget` -> `jquery` resolves
     * fully without any of them ever being explicitly registered.
     */
    private function resolveMissingDependencies(AssetContribution $contribution): void
    {
        foreach ($contribution->dependsOn as $id) {
            if (isset($this->scripts[$id]) || ! self::isKnownId($id)) {
                continue;
            }

            $this->addScript(AssetContribution::script(id: $id, path: ''));
        }
    }

    private static function isKnownId(string $id): bool
    {
        return isset(self::$knownPaths[$id]) || str_starts_with($id, 'jquery.ui.');
    }

    private static function knownPath(string $id): string
    {
        if (isset(self::$knownPaths[$id])) {
            return self::$knownPaths[$id];
        }

        if (str_starts_with($id, 'jquery.ui.effect-')) {
            return dirname(self::$knownPaths['jquery.ui.effect']) . "/{$id}.min.js";
        }

        return dirname(self::$knownPaths['jquery.ui']) . "/{$id}.min.js";
    }

    /**
     * @return list<string>
     */
    private static function knownRequires(string $id): array
    {
        if (! str_starts_with($id, 'jquery.')) {
            return [];
        }

        if (isset(self::$uiCoreDependencies[$id])) {
            return self::$uiCoreDependencies[$id];
        }

        if (str_starts_with($id, 'jquery.ui.effect-')) {
            return ['jquery', 'jquery.ui.effect'];
        }

        if (str_starts_with($id, 'jquery.ui.')) {
            return ['jquery', 'jquery.ui', ...array_keys(self::$uiCoreDependencies)];
        }

        return ['jquery'];
    }

    /**
     * Header, footer, inline (always footer-positioned -- see
     * `AssetContribution::inlineScript()`'s own docblock), then async,
     * in that fixed order -- `Template`'s own asset-tag-rendering step
     * (P41-G, docs/PLAN.md) filters this one list by `loadMode`/
     * `inlineCode` to build the head placeholder (header entries) and
     * the footer placeholder (everything else) separately, rather than
     * this method returning two separate lists.
     *
     * @return list<ResolvedAsset>
     */
    public function resolveScripts(): array
    {
        $this->promoteLoadModes();

        $byMode = [
            'header' => [],
            'footer' => [],
            'async' => [],
        ];
        foreach ($this->scripts as $id => $contribution) {
            $byMode[self::modeKey($contribution->loadMode ?? LoadMode::Header)][$id] = $contribution;
        }

        $resolved = [];
        foreach ($this->topologicalSort($byMode['header']) as $contribution) {
            $resolved[] = ResolvedAsset::file($this->resolvePath($contribution->path), $contribution->loadMode, $contribution->version);
        }
        foreach ($this->topologicalSort($byMode['footer']) as $contribution) {
            $resolved[] = ResolvedAsset::file($this->resolvePath($contribution->path), $contribution->loadMode, $contribution->version);
        }
        foreach ($this->inlineScripts as $inline) {
            $resolved[] = ResolvedAsset::inline((string) $inline->code);
        }
        foreach ($this->topologicalSort($byMode['async']) as $contribution) {
            $resolved[] = ResolvedAsset::file($this->resolvePath($contribution->path), $contribution->loadMode, $contribution->version);
        }

        return $resolved;
    }

    /**
     * @return list<ResolvedAsset>
     */
    public function resolveCss(): array
    {
        $ordered = $this->css;
        uasort($ordered, static fn (AssetContribution $a, AssetContribution $b): int => $a->order <=> $b->order);

        $resolved = [];
        $seenCssPaths = [];
        foreach ($ordered as $contribution) {
            $path = $this->resolvePath($contribution->path);
            $resolved[] = ResolvedAsset::file($path, null, $contribution->version);
            $seenCssPaths[$path] = true;
        }

        // A resolved Vite entry can pull its own CSS chunks along with it
        // (real manifest.json shape: `"css": [...]` on an entry) --
        // these render after the explicit CSS list, in script
        // registration order, deduped against anything already listed.
        foreach ($this->scripts as $contribution) {
            $entry = $this->viteManifest->resolve($contribution->path);
            if ($entry === null) {
                continue;
            }
            foreach ($entry->css as $cssPath) {
                if (! isset($seenCssPaths[$cssPath])) {
                    $resolved[] = ResolvedAsset::file($cssPath, null, false);
                    $seenCssPaths[$cssPath] = true;
                }
            }
        }

        return $resolved;
    }

    private function resolvePath(string $sourcePath): string
    {
        $entry = $this->viteManifest->resolve($sourcePath);

        return $entry !== null ? $entry->file : $sourcePath;
    }

    private static function modeKey(LoadMode $mode): string
    {
        return match ($mode) {
            LoadMode::Header => 'header',
            LoadMode::Footer => 'footer',
            LoadMode::Async => 'async',
        };
    }

    /**
     * `ScriptLoader::checkLoadDep()`'s real behavior: a dependency can't
     * load more loosely than whatever depends on it -- an async script
     * can't safely precede a footer script that requires it, since two
     * async tags have no guaranteed relative order.
     *
     * That "no guaranteed relative order" also holds between TWO async
     * scripts -- confirmed against `ScriptLoader::checkLoadDep()`'s own
     * real, pre-P41-G/H logic (`git show 00fd301ac5~1:.../ScriptLoader.php`),
     * which had a second, dedicated check for exactly this case: `$load
     * === 2 && $scripts[$precedent]->loadMode === 2` (both async)
     * demoted the dependency to Footer, unless the dependency could be
     * file-combined into the same bundle as its dependent (P41-G/H's own
     * intentional drop of file-combining -- `docs/PLAN.md`'s P41-G/H
     * section -- makes that exception permanently moot, not something
     * to port forward). The general loop below only fires on strictly
     * `>`, so an Async-depends-on-Async pair (both value 2) silently
     * passed through unpromoted -- a real regression from that
     * migration, not a hypothetical: `PictureView`'s own
     * `AssetContribution::script('rating', ..., dependsOn: ['core.scripts'])`
     * has both ends `LoadMode::Async`, and `<script async>` tags execute
     * whenever they finish downloading, in no guaranteed order --
     * caught via a real, intermittent `picture-1` VR failure
     * (`Uncaught ReferenceError: pwgAddEventListener is not defined`,
     * defined in `core.scripts`'s own `scripts.js`).
     */
    private function promoteLoadModes(): void
    {
        // ScriptLoader::addInline()'s own real behavior: an inline
        // script always runs after every footer-sync <script src> tag
        // but has no guaranteed ordering against a separate <script
        // async> tag, so a dependency it requires can't stay async.
        foreach ($this->inlineScripts as $inline) {
            foreach ($inline->dependsOn as $id) {
                $dependency = $this->scripts[$id] ?? null;
                if ($dependency !== null && ($dependency->loadMode ?? LoadMode::Header) === LoadMode::Async) {
                    $this->scripts[$id] = self::withLoadMode($dependency, LoadMode::Footer);
                }
            }
        }

        do {
            $changed = false;
            foreach ($this->scripts as $contribution) {
                $mode = $contribution->loadMode ?? LoadMode::Header;
                foreach ($contribution->dependsOn as $dependencyId) {
                    $dependency = $this->scripts[$dependencyId] ?? null;
                    if ($dependency === null) {
                        continue;
                    }
                    $dependencyMode = $dependency->loadMode ?? LoadMode::Header;
                    /**
                     * @psalm-suppress TypeDoesNotContainType Psalm's flow
                     *   analysis over this self-referencing
                     *   `$this->scripts[$dependencyId] = ...` loop
                     *   incorrectly narrows $dependencyMode to always
                     *   LoadMode::Header; PageAssetsTest.php's own "a
                     *   dependency is promoted to its dependent's stricter
                     *   load mode" test proves $dependencyMode really can
                     *   be Footer/Async here and this branch really does
                     *   fire.
                     */
                    if ($dependencyMode->value > $mode->value) {
                        $this->scripts[$dependencyId] = self::withLoadMode($dependency, $mode);
                        $changed = true;
                    } elseif ($dependencyMode === LoadMode::Async && $mode === LoadMode::Async) {
                        $this->scripts[$dependencyId] = self::withLoadMode($dependency, LoadMode::Footer);
                        $changed = true;
                    }
                }
            }
        } while ($changed);
    }

    private static function withLoadMode(AssetContribution $contribution, LoadMode $loadMode): AssetContribution
    {
        return AssetContribution::script(
            id: $contribution->id,
            path: $contribution->path,
            loadMode: $loadMode,
            dependsOn: $contribution->dependsOn,
            version: $contribution->version,
        );
    }

    /**
     * @param array<string, AssetContribution> $group
     * @return list<AssetContribution>
     */
    private function topologicalSort(array $group): array
    {
        $order = [];
        foreach (array_keys($group) as $id) {
            self::computeOrder($id, $group, $order, []);
        }

        $ids = array_keys($group);
        usort($ids, static fn (string $a, string $b): int => ($order[$a] ?? 0) <=> ($order[$b] ?? 0));

        return array_map(static fn (string $id): AssetContribution => $group[$id], $ids);
    }

    /**
     * @param array<string, AssetContribution> $group
     * @param array<string, int> $order
     * @param list<string> $stack
     */
    private static function computeOrder(string $id, array $group, array &$order, array $stack): int
    {
        if (isset($order[$id])) {
            return $order[$id];
        }
        if (in_array($id, $stack, true)) {
            throw new LogicException("PageAssets: circular script dependency involving '{$id}'");
        }
        $contribution = $group[$id] ?? null;
        if ($contribution === null) {
            return $order[$id] = 0;
        }

        $max = 0;
        foreach ($contribution->dependsOn as $dependencyId) {
            if (isset($group[$dependencyId])) {
                $max = max($max, self::computeOrder($dependencyId, $group, $order, [...$stack, $id]) + 1);
            }
        }

        return $order[$id] = $max;
    }
}
