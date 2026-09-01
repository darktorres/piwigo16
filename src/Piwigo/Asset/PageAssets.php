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
 * yet (66 of the real 78 theme JS files as of docs/PLAN.md's P46-B;
 * `vitals` plus P46-B's own 12 entries already resolve here).
 *
 * All three sources are real and wired together now (docs/PLAN.md's
 * P42: a 945-call-site migration put every real page's own
 * `pageAssets()`/plugin `GetPageAssets` contribution through this exact
 * class -- the note this docblock used to carry about no real caller
 * existing describes P36's own original, not-yet-wired scaffolding,
 * long since superseded). Every behavior below is preserved from
 * `ScriptLoader`/`CssLoader`'s real, then-live logic, confirmed against
 * the real 76-template corpus rather than reinvented:
 *
 * - CSS: plain stable sort by `$order` (real range found in templates:
 *   -999 to 100). `CssLoader::cmpByOrder()`'s own `order * 1000 +
 *   $counter` scaling existed only to fake stability on a PHP version
 *   where `uasort()` wasn't guaranteed stable -- `uasort()`/`usort()`
 *   have been stable since PHP 8.0, so registration order is already
 *   preserved for equal `$order` values without that trick.
 * - Scripts: head/footer/async partition, each group
 *   dependency-respecting topologically sorted
 *   (`ScriptLoader::computeScriptTopologicalOrder()`'s real logic). A
 *   dependency can't load more loosely than its dependent
 *   (`ScriptLoader::checkLoadDep()`'s real "if B requires A, A->loadMode
 *   <= B->loadMode" promotion). The real multi-level chain this once
 *   had to handle (`jquery.ui.timepicker-addon` -> `jquery.ui` ->
 *   `jquery`) is gone with jQuery itself (P49-C) -- the only real
 *   `dependsOn` left anywhere is single-level (`cat_search` ->
 *   `albums`, `AlbumsView`), but the topological sort stays generic
 *   rather than narrowed to that, since a future multi-level need is a
 *   real possibility this mechanism already handles for free.
 * - Both kinds dedupe by id: a later `add()` with an id already
 *   present merges into the existing contribution rather than
 *   replacing or duplicating it (`ScriptLoader::add()`'s real merge --
 *   union `dependsOn`, promote to the more eager `loadMode`, keep the
 *   higher `version` -- and `CssLoader::add()`'s real "keep whichever
 *   has the higher order, or higher version on a tie").
 *
 * The jQuery-UI known-script-by-naming-convention resolver
 * (`ScriptLoader::fillWellKnown()`/`loadKnownRequiredScript()`,
 * `$knownPaths`/`isKnownId()`/`knownPath()`/`knownRequires()`/
 * `resolveMissingDependencies()`/`fillKnownScript()` here) is gone
 * outright (P49-C): its only 2 real entries ever, `'jquery'` and
 * `'jquery.ui'`, are unreachable from any real `dependsOn` or bare
 * registration anywhere in the app now (confirmed via a repo-wide
 * grep) -- this was never a generic "any well-known library" system,
 * only ever jQuery's own naming convention, so nothing generic survives
 * to keep.
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
     * `ScriptLoader::addInline()`'s own replacement: no dedup, every call
     * unconditionally appends.
     */
    private function addInlineScript(AssetContribution $contribution): void
    {
        $this->inlineScripts[] = $contribution;
    }

    private function addScript(AssetContribution $contribution): void
    {
        $existing = $this->scripts[$contribution->id] ?? null;
        $merged = $existing === null ? $contribution : self::mergeScripts($existing, $contribution);

        $this->scripts[$merged->id] = $merged;
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
            $resolved[] = ResolvedAsset::file($this->resolvePath($contribution->path), $contribution->loadMode, false);
        }
        foreach ($this->topologicalSort($byMode['footer']) as $contribution) {
            $resolved[] = ResolvedAsset::file($this->resolvePath($contribution->path), $contribution->loadMode, false);
        }
        foreach ($this->inlineScripts as $inline) {
            $resolved[] = ResolvedAsset::inline((string) $inline->code);
        }
        foreach ($this->topologicalSort($byMode['async']) as $contribution) {
            $resolved[] = ResolvedAsset::file($this->resolvePath($contribution->path), $contribution->loadMode, false);
        }

        return $resolved;
    }

    /**
     * Every shared chunk this page's built entries import, transitively
     * and deduplicated, ready to render as `<link rel="modulepreload">`.
     *
     * Without these the browser cannot discover a chunk until it has
     * fetched *and parsed* the entry that imports it, turning what used
     * to be one self-contained request per entry into a waterfall.
     *
     * `version: false` is load-bearing, not tidiness. A chunk is reached
     * from inside its importer by a relative specifier that carries no
     * query string, so a `?v`-suffixed preload href would be a
     * *different URL*: the browser would fetch the chunk twice and the
     * hint would cost more than it saves. Content-hashed filenames make
     * cache-busting redundant anyway -- the same reasoning `resolveCss()`
     * already applies to manifest-derived CSS chunks below.
     *
     * @return list<ResolvedAsset>
     */
    public function resolveModulePreloads(): array
    {
        $seen = [];
        $resolved = [];
        foreach ($this->scripts as $contribution) {
            $entry = $this->viteManifest->resolve($contribution->path);
            if ($entry === null) {
                continue;
            }

            $this->collectChunkImports($entry, $seen, $resolved);
        }

        return $resolved;
    }

    /**
     * `$entry->imports` holds *manifest keys* (`_common-vA1Nr0H_.js`),
     * which index the same map entries do, so one `resolve()` per key
     * walks the graph. Recurses because a chunk may import another.
     *
     * @param array<string, true> $seen
     * @param list<ResolvedAsset> $resolved
     */
    private function collectChunkImports(ViteManifestEntry $entry, array &$seen, array &$resolved): void
    {
        foreach ($entry->imports as $key) {
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $chunk = $this->viteManifest->resolve($key);
            if ($chunk === null) {
                continue;
            }

            $resolved[] = ResolvedAsset::file('dist/' . $chunk->file, null, false);
            $this->collectChunkImports($chunk, $seen, $resolved);
        }
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
                // Same `dist/`-relative-not-root-relative fix as
                // `resolvePath()` above -- these paths come from the same
                // manifest entry.
                $rootRelativeCssPath = 'dist/' . $cssPath;
                if (! isset($seenCssPaths[$rootRelativeCssPath])) {
                    $resolved[] = ResolvedAsset::file($rootRelativeCssPath, null, false);
                    $seenCssPaths[$rootRelativeCssPath] = true;
                }
            }
        }

        return $resolved;
    }

    private function resolvePath(string $sourcePath): string
    {
        $entry = $this->viteManifest->resolve($sourcePath);

        // `ViteManifestEntry::$file` is relative to Vite's own `outDir`
        // ("dist"), not the site root every other resolved path (the raw
        // fallback below, every un-bundled `themes/...` path) is relative
        // to -- confirmed missing here only once real themes/**/*.ts
        // entries existed to expose it (P46-B): every request 404'd,
        // since nothing had ever exercised this branch with a populated
        // manifest before. `PageTailRenderer::render()`'s own hand-rolled
        // `vitals` resolution already prepends `'dist/'` for exactly this
        // reason -- this is that same fix, generalized to every entry.
        return $entry !== null ? 'dist/' . $entry->file : $sourcePath;
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
     * migration, not a hypothetical: `PictureView`'s own former
     * `AssetContribution::script('rating', ..., dependsOn: ['core.scripts'])`
     * had both ends `LoadMode::Async`, and `<script async>` tags execute
     * whenever they finish downloading, in no guaranteed order --
     * caught via a real, intermittent `picture-1` VR failure
     * (`Uncaught ReferenceError: pwgAddEventListener is not defined`,
     * defined in `core.scripts`'s own `scripts.js`). That specific
     * `dependsOn` no longer exists (docs/PLAN.md P48, scripts.ts's own
     * module conversion made it structurally impossible instead: a real
     * `import` guarantees evaluation order, no script-tag race left to
     * promote around) -- this method itself stays, for every other real
     * Async-depends-on-Async pair still registered elsewhere.
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
