<?php

declare(strict_types=1);

namespace Piwigo\Lang;

use Gettext\Loader\PoLoader;
use Gettext\Translation;
use Gettext\Translations;
use Gettext\Translator as GettextTranslator;
use LogicException;
use Piwigo\Cache\CachePools;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;

/**
 * Piwigo translation service backed by gettext PO files.
 *
 * Wraps gettext/translator's Translator (pure-PHP, no ext-gettext required)
 * and uses gettext/gettext's PoLoader to parse PO files.
 *
 * `Gettext\Generator\ArrayGenerator` -- the class gettext/translator's own
 * `Translator::createFromTranslations()` expects to bridge a `Translations`
 * object into its internal dictionary array -- was removed from
 * gettext/gettext by v5.7 (confirmed absent from the installed v5.7.3:
 * only Generator/GeneratorInterface/MoGenerator/PoGenerator remain under
 * `Generator/`), even though gettext/translator v1.2.3's own composer.json
 * still declares `"gettext/gettext": "^5.0.0"` as compatible. `load()`
 * below builds gettext/translator's expected `['domain', 'plural-forms',
 * 'messages']` array shape directly instead of going through the missing
 * class -- same dictionary shape, one fewer (broken) indirection.
 *
 * Multiple load() calls accumulate translations (common + admin + upgrade
 * etc). $mirror is a flat string map kept in sync (via mirror()) as
 * translate()'s own fallback for keys gettext has no entry for -- "nothing
 * is frozen" gap-closure (2026-07-22): this used to be $GLOBALS['lang'],
 * justified as "for plugin/theme code that reads it directly," but nothing
 * in-tree (nor any *code*, as opposed to a docblock claim) ever read that
 * global independently of this class and Piwigo\Core\Lang -- external
 * plugin/theme compatibility is never a valid reason to keep an in-tree
 * global either way. Converted to a real private property; Piwigo\Core\
 * Lang now pulls from mirroredStrings() instead of reading the global.
 */
final class Translator
{
    /**
     * Fallback instance for callers reached before/without Kernel::boot()
     * -- see get()'s own docblock for why this is memoized (unlike most
     * of this campaign's other shims) rather than a fresh instance per
     * call.
     */
    private static ?self $fallback = null;

    private GettextTranslator $inner;

    /**
     * Flat string map, kept in sync by mirror() -- see this class's own
     * docblock. 'day'/'month' entries are nested list<string>, matching
     * Piwigo\Core\Lang::$data's own documented exception.
     *
     * @var array<string, string|array<int, string>>
     */
    private array $mirror = [];

    public function __construct(
        private readonly CurrentConfig $currentConfig,
    ) {
        $this->inner = new GettextTranslator();
    }

    /**
     * $inner's own properties (domain/dictionary/plurals) are plain
     * scalars/arrays -- PHP's value semantics already deep-copy those on
     * assignment, so cloning $inner itself is enough to fully decouple the
     * clone from the original (no further nested __clone() needed on
     * $inner). Needed so MailService::switchLangTo()/switchLangBack() can
     * snapshot and restore a language's full translation state, not just
     * Piwigo\Core\Lang's own parallel $data/$langInfo bookkeeping.
     */
    public function __clone(): void
    {
        $this->inner = clone $this->inner;
    }

    /**
     * @deprecated transitional bridge for callers not yet converted to
     * constructor injection -- singleton/service-locator elimination
     * campaign, Phase 4. Resolves the container-shared instance once
     * Kernel::boot() has run, same shape as DbCredentials::current()/
     * SessionService::get(). Unlike those, the Kernel-not-booted fallback
     * is memoized (not fresh per call): Translator is a "load once, read
     * many times" class (load() populates $inner/$mirror, translate()/
     * plural() read them back) -- a fresh instance per call would silently
     * lose every loaded PO file between calls, exactly the "compute-then-
     * blind-read-back" corruption shape this campaign's own ProcessCache
     * execution note already found and fixed once. TranslatorTest.php's
     * ~30 tests all rely on this memoization (load() then translate() in
     * the same test, no Kernel::boot() anywhere in the file).
     */
    public static function get(): self
    {
        if (Kernel::isBooted()) {
            $translator = Kernel::container()->get(self::class);
            if (! $translator instanceof self) {
                throw new LogicException('Container returned an unexpected type for ' . self::class);
            }

            return $translator;
        }

        return self::$fallback ??= new self(new CurrentConfig());
    }

    /**
     * Restores this container-shared instance's translation state from a
     * snapshot taken via `clone` -- mutates $this in place rather than
     * replacing which object every other constructor-injected holder's
     * reference points at (same "mutate the shared instance" reasoning as
     * DbCredentials::reload()/CurrentUser::updateLanguage()). Replaces the
     * former `Translator::set(clone $snapshot)` call MailService::
     * switchLangTo()/switchLangBack() used under the static-singleton
     * design.
     */
    public function restoreFrom(self $snapshot): void
    {
        $this->inner = clone $snapshot->inner;
        $this->mirror = $snapshot->mirror;
    }

    /**
     * Test-only. Clears all loaded translations back to a pristine state --
     * same observable end state as a fresh `new self()`, but mutates the
     * shared instance in place instead of replacing which object every
     * other constructor-injected holder's reference points at (real
     * callers -- Kernel::container()->get(self::class)->reset() -- need
     * this so a booted container's shared instance can be reset between
     * tests, matching FilterState/CoreTabs's own instance reset() precedent;
     * the get() shim's own Kernel-not-booted fallback branch is covered too
     * since it returns the same kind of object).
     */
    public function reset(): void
    {
        $this->inner = new GettextTranslator();
        $this->mirror = [];
    }

    /**
     * Load a PO file and merge its translations into the active set. Also
     * mirrors all strings into $mirror, translate()'s own fallback.
     *
     * Returns the parsed Translations (or null if the file wasn't
     * readable) so callers that also need header metadata --
     * load_language()'s $lang_info population, e.g. X-Piwigo-Parent/
     * X-Piwigo-Zero-Plural -- don't have to parse the same file twice.
     *
     * Cached via CachePools::translations() (confirmed via a real Xdebug
     * profile: raw PO parsing plus two full passes over every translation
     * entry -- toDictionaryEntry() and mirror() -- cost ~18-19% of a
     * bootstrap request's server-side time with no caching at all). Cache
     * key folds in $poFile's own mtime, so an edited PO file busts its own
     * entry on the very next load() -- no manual clear, no staleness risk.
     * A cache hit skips PoLoader::loadFile() and both entry-iteration
     * passes entirely; the returned Translations is reconstructed with only
     * the cached headers (the sole thing any real caller reads off it --
     * confirmed via Piwigo\Core\Lang's own load_language(), which only ever
     * calls ->getHeaders() on this return value), not the full entry list.
     */
    public function load(string $locale, string $poFile): ?Translations
    {
        if (! is_readable($poFile)) {
            return null;
        }

        $mtime = filemtime($poFile);
        $pool = $mtime !== false ? CachePools::translations() : null;
        $item = $pool?->getItem(md5($poFile . '_' . $mtime));

        if ($item !== null && $item->isHit()) {
            /**
             * @var array{
             *     dictionary: array{domain: string, plural-forms: string, messages: array<string, array<string, list<string>>>},
             *     mirror: array<string, string|array<int, string>>,
             *     headers: array<string, string>
             * } $cached
             */
            $cached = $item->get();

            $this->inner->addTranslations($cached['dictionary']);
            foreach ($cached['mirror'] as $mirrorKey => $mirrorValue) {
                $this->mirror[$mirrorKey] = $mirrorValue;
            }

            return $this->translationsFromCachedHeaders($cached['headers']);
        }

        $translations = new PoLoader()
            ->loadFile($poFile);

        $dictionary = $this->toDictionaryEntry($translations);
        $this->inner->addTranslations($dictionary);

        $mirrorContribution = $this->mirror($translations);
        foreach ($mirrorContribution as $mirrorKey => $mirrorValue) {
            $this->mirror[$mirrorKey] = $mirrorValue;
        }

        if ($item !== null) {
            $item->set([
                'dictionary' => $dictionary,
                'mirror' => $mirrorContribution,
                'headers' => $translations->getHeaders()
                    ->toArray(),
            ]);
            $pool->save($item);
        }

        return $translations;
    }

    /**
     * Rebuilds a Translations object carrying only cached headers, for a
     * load() cache hit -- confirmed the only thing any real caller reads
     * off load()'s return value (see load()'s own docblock), so there is no
     * need to reconstruct the full parsed entry list.
     *
     * @param array<string, string> $headers
     */
    private function translationsFromCachedHeaders(array $headers): Translations
    {
        $translations = Translations::create();
        foreach ($headers as $name => $value) {
            $translations->getHeaders()
                ->set($name, $value);
        }

        return $translations;
    }

    /**
     * The flat string map load() maintains as translate()'s own fallback --
     * see this class's own docblock. Piwigo\Core\Lang::attachGlobals()
     * pulls from here.
     *
     * @return array<string, string|array<int, string>>
     */
    public function mirroredStrings(): array
    {
        return $this->mirror;
    }

    public function translate(string $key, mixed ...$args): string
    {
        $val = $this->inner->gettext($key);

        // gettext() returns the original when not found -- fall back to
        // $mirror, which may have been populated by a PHP lang file (e.g.
        // from a plugin without a .po file yet).
        if ($val === $key && isset($this->mirror[$key]) && is_string($this->mirror[$key])) {
            $val = $this->mirror[$key];
        }

        // Legacy Coupling Retirement Phase 4d: moved here (from the
        // deleted l10n() free function) rather than staying in Lang::t() --
        // testing $val === $key after both real resolution paths above
        // (gettext() then $GLOBALS['lang']) are exhausted is strictly more
        // accurate than l10n()'s own former shallow `! isset($lang[$key])`
        // check (which only ever looked at the fallback array), and this
        // way every Lang::t() caller gets the diagnostic too, not just
        // former l10n() callers.
        if ($this->currentConfig->debugL10n() && $val === $key && $key !== '') {
            trigger_error('[l10n] language key "' . $key . '" not defined', E_USER_WARNING);
        }

        if ($args === []) {
            return $val;
        }

        $scalarArgs = array_map(
            static fn (mixed $a): string => is_scalar($a) || $a === null ? (string) $a : '',
            $args,
        );

        return vsprintf($val, $scalarArgs);
    }

    public function plural(string $singular, string $plural, int $n, mixed ...$args): string
    {
        $val = $this->inner->ngettext($singular, $plural, $n);

        if ($args === []) {
            return sprintf($val, $n);
        }

        $scalarArgs = array_map(
            static fn (mixed $a): string => is_scalar($a) || $a === null ? (string) $a : '',
            $args,
        );

        return vsprintf($val, [$n, ...$scalarArgs]);
    }

    /**
     * Builds the array shape gettext/translator's Translator::
     * addTranslations() expects: `['domain' => string, 'plural-forms' =>
     * 'nplurals=N; plural=EXPR;', 'messages' => [context => [original =>
     * list<string>]]]`. Mirrors what the removed ArrayGenerator produced
     * (verified against gettext/translator's own dictionary reader,
     * `Translator::getTranslation()`/`getPluralIndex()`): msgstr[0]
     * (`getTranslation()`) is a slot of its own, NOT included in
     * `getPluralTranslations()` -- confirmed empirically (a 3-plural-form
     * PO file's `getPluralTranslations()` returned only `[msgstr1,
     * msgstr2]`, msgstr0 came from `getTranslation()`), contradicting an
     * assumption the reference's own equivalent code made.
     *
     * @return array{domain: string, plural-forms: string, messages: array<string, array<string, list<string>>>}
     */
    private function toDictionaryEntry(Translations $translations): array
    {
        $messages = [];

        foreach ($translations->getTranslations() as $entry) {
            if (! ($entry instanceof Translation)) {
                continue;
            }

            $original = $entry->getOriginal();
            if ($original === '') {
                continue;
            }

            $context = $entry->getContext() ?? '';
            $plural = $entry->getPlural();
            $singularForm = $entry->getTranslation() ?? '';

            $forms = ($plural !== null && $plural !== '')
                ? [$singularForm, ...$entry->getPluralTranslations()]
                : [$singularForm];

            $messages[$context][$original] = array_values(
                array_map(static fn (mixed $f): string => is_string($f) ? $f : '', $forms),
            );
        }

        return [
            'domain' => $translations->getDomain() ?? '',
            'plural-forms' => $translations->getHeaders()
                ->get('Plural-Forms') ?? '',
            'messages' => $messages,
        ];
    }

    /**
     * Computes this translations set's contribution to $mirror (translate()'s
     * own fallback) without writing it -- load() applies the returned
     * contribution to $this->mirror both on a fresh parse and (replayed
     * from cache) on a cache hit, so the write-through behavior is
     * identical either way.
     *
     * @return array<string, string|array<int, string>>
     */
    private function mirror(Translations $translations): array
    {
        $contribution = [];

        // php-to-po-fn.php flattens $lang['day'][N]/$lang['month'][N] --
        // array-valued entries with no PO equivalent -- into piwigo_day_N/
        // piwigo_month_N string entries. Captured here and reassembled
        // below into the nested shape Lang::day()/month() (via
        // $mirror['day'][N]/['month'][N]) expect -- $mirror's own value
        // type is a string|array<int,string> union, so accumulating in a
        // plain local array first and assigning the whole nested value
        // once avoids PHPStan needing to narrow $mirror['day'] to its
        // array branch on every element write.
        $days = [];
        $months = [];

        foreach ($translations->getTranslations() as $entry) {
            if (! ($entry instanceof Translation)) {
                continue;
            }

            $original = $entry->getOriginal();
            if ($original === '') {
                continue;
            }

            $str = $entry->getTranslation();
            if ($str !== null && $str !== '') {
                $contribution[$original] = $str;

                if (preg_match('/^piwigo_day_(\d+)$/', $original, $matches) === 1) {
                    $days[(int) $matches[1]] = $str;
                } elseif (preg_match('/^piwigo_month_(\d+)$/', $original, $matches) === 1) {
                    $months[(int) $matches[1]] = $str;
                }
            }

            // getPluralTranslations()[0] is msgstr[1] -- the translation
            // matching the plural English key -- NOT msgstr[0] (that's
            // getTranslation(), handled above). See toDictionaryEntry()'s
            // docblock for how this was confirmed. gettext/gettext's own
            // Translation::$pluralTranslations has no property/return type
            // (bare `array`), so $pluralForms[0] is genuinely unverified
            // mixed to PHPStan -- a real gap $mirror's own now-real typed
            // property surfaces (previously hidden: $GLOBALS['lang'] was
            // mixed to PHPStan regardless).
            $pluralOriginal = $entry->getPlural();
            $pluralForms = $entry->getPluralTranslations();
            $pluralForm0 = $pluralForms[0] ?? null;

            if ($pluralOriginal !== null && $pluralOriginal !== '' && is_string($pluralForm0) && $pluralForm0 !== '') {
                $contribution[$pluralOriginal] = $pluralForm0;
            }
        }

        if ($days !== []) {
            $contribution['day'] = $days;
        }
        if ($months !== []) {
            $contribution['month'] = $months;
        }

        return $contribution;
    }

    /**
     * Test-only. Seeds $mirror directly, matching Piwigo\Core\Lang::
     * loadArray()'s own test-helper shape, for tests that need
     * translate()'s fallback populated without a real PO file.
     *
     * @param array<string, string|array<int, string>> $data
     */
    public function loadArray(array $data): void
    {
        $this->mirror = $data;
    }
}
