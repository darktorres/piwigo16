<?php

declare(strict_types=1);

namespace Piwigo\Lang;

use Gettext\Loader\PoLoader;
use Gettext\Translation;
use Gettext\Translations;
use Gettext\Translator as GettextTranslator;
use Piwigo\Cache\TranslationsCachePool;
use Piwigo\Config\CurrentConfig;
use Psr\Cache\CacheItemInterface;

/**
 * Piwigo translation service backed by gettext PO files.
 *
 * Wraps gettext/translator's Translator (pure-PHP, no ext-gettext required)
 * and uses gettext/gettext's PoLoader to parse PO files.
 *
 * `Gettext\Generator\ArrayGenerator` -- the class gettext/translator's own
 * `Translator::createFromTranslations()` expects to bridge a `Translations`
 * object into its internal dictionary array -- is absent from the
 * installed gettext/gettext v5.7.3 (only Generator/GeneratorInterface/
 * MoGenerator/PoGenerator remain under `Generator/`), even though
 * gettext/translator v1.2.3's own composer.json still declares
 * `"gettext/gettext": "^5.0.0"` as compatible. `load()` below builds
 * gettext/translator's expected `['domain', 'plural-forms', 'messages']`
 * array shape directly instead of going through the missing class --
 * same dictionary shape, one fewer (broken) indirection.
 *
 * Multiple load() calls accumulate translations (common + admin + upgrade
 * etc). $mirror is a flat string map kept in sync (via mirror()) as
 * translate()'s own fallback for keys gettext has no entry for. It is a
 * real private property; `Piwigo\Core\Lang` pulls from mirroredStrings()
 * rather than reading a global.
 */
final class Translator
{
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
        private readonly TranslationsCachePool $translationsCachePool,
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
    public function __clone()
    {
        $this->inner = clone $this->inner;
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
     * tests, matching FilterState/CoreTabs's own instance reset() precedent.
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
     * Cached via $translationsCachePool -- raw PO parsing plus two full
     * passes over every translation entry (toDictionaryEntry() and
     * mirror()) cost ~18-19% of a bootstrap request's server-side time with
     * no caching at all, per a real Xdebug profile. Cache
     * key folds in $poFile's own mtime, so an edited PO file busts its own
     * entry on the very next load() -- no manual clear, no staleness risk.
     * A cache hit skips PoLoader::loadFile() and both entry-iteration
     * passes entirely; the returned Translations is reconstructed with only
     * the cached headers (the sole thing any real caller reads off it --
     * Piwigo\Core\Lang's own load_language() only ever calls ->getHeaders()
     * on this return value), not the full entry list.
     */
    public function load(string $locale, string $poFile): ?Translations
    {
        if (! is_readable($poFile)) {
            return null;
        }

        $mtime = filemtime($poFile);
        $pool = $mtime !== false ? $this->translationsCachePool : null;
        $item = $pool?->getItem(md5($poFile . '_' . (string) $mtime));

        if ($item instanceof CacheItemInterface && $item->isHit()) {
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

        if ($item instanceof CacheItemInterface) {
            $item->set([
                'dictionary' => $dictionary,
                'mirror' => $mirrorContribution,
                'headers' => $translations->getHeaders()
                    ->toArray(),
            ]);
            // $item came from $pool?->getItem(...) above -- a real
            // CacheItemInterface here (just confirmed) is only possible
            // when $pool itself was non-null. PHPStan narrows $pool
            // backward through the nullsafe call; Psalm doesn't.
            /** @psalm-suppress PossiblyNullReference */
            $pool->save($item);
        }

        return $translations;
    }

    /**
     * Rebuilds a Translations object carrying only cached headers, for a
     * load() cache hit -- the only thing any real caller reads
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

        // Testing $val === $key only after both real resolution paths
        // above (gettext() then the $mirror fallback) are exhausted keeps
        // this diagnostic accurate for every Lang::t() caller.
        if ($this->currentConfig->debugL10n && $val === $key && $key !== '') {
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
     * list<string>]]]`. Mirrors what the removed ArrayGenerator produced,
     * per gettext/translator's own dictionary reader,
     * `Translator::getTranslation()`/`getPluralIndex()`: msgstr[0]
     * (`getTranslation()`) is a slot of its own, NOT included in
     * `getPluralTranslations()` (a 3-plural-form PO file's
     * `getPluralTranslations()` returns only `[msgstr1, msgstr2]`, msgstr0
     * comes from `getTranslation()`), contradicting an assumption the
     * reference's own equivalent code made.
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
            // docblock for the full reasoning. gettext/gettext's own
            // Translation::$pluralTranslations has no property/return type
            // (bare `array`), so $pluralForms[0] is genuinely unverified
            // mixed to PHPStan -- a real gap $mirror's own typed property
            // now surfaces.
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
