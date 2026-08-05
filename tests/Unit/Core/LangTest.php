<?php

declare(strict_types=1);

use Gettext\Headers;
use Piwigo\Core\DefaultLanguageProviderInterface;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Lang\Translator;

function langTestRrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $nodes = scandir($dir);
    foreach ($nodes !== false ? $nodes : [] as $node) {
        if ($node === '.' || $node === '..') {
            continue;
        }
        $path = $dir . '/' . $node;
        is_dir($path) ? langTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

/**
 * Writes a minimal, real gettext PO fixture -- exercised through the real
 * Piwigo\Lang\Translator/gettext\gettext PoLoader stack, not a fake, same
 * as the real language/*.po files this class ships with.
 */
function langTestWritePo(string $path, string $language, string $translation, ?string $parent = null): void
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0o777, true);
    }
    $headerLines = [
        'Content-Type: text/plain; charset=UTF-8',
        'Language: ' . $language,
    ];
    if ($parent !== null) {
        $headerLines[] = 'X-Piwigo-Parent: ' . $parent;
    }
    $header = implode('', array_map(static fn (string $l): string => '"' . $l . "\\n\"\n", $headerLines));

    file_put_contents(
        $path,
        "msgid \"\"\nmsgstr \"\"\n{$header}\nmsgid \"Hello\"\nmsgstr \"{$translation}\"\n",
    );
}

/**
 * Like langTestWritePo(), but supports multiple msgid/msgstr pairs -- for
 * proving a *specific* translation key came from a *specific* po file
 * (e.g. a parent-only key absent from the child), where a single
 * "Hello" => translation assertion can't distinguish which file it
 * actually loaded from.
 *
 * @param array<string, string> $translations
 */
function langTestWritePoMulti(string $path, string $language, array $translations, ?string $parent = null): void
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0o777, true);
    }
    $headerLines = [
        'Content-Type: text/plain; charset=UTF-8',
        'Language: ' . $language,
    ];
    if ($parent !== null) {
        $headerLines[] = 'X-Piwigo-Parent: ' . $parent;
    }
    $header = implode('', array_map(static fn (string $l): string => '"' . $l . "\\n\"\n", $headerLines));

    $body = '';
    foreach ($translations as $msgid => $msgstr) {
        $body .= "msgid \"{$msgid}\"\nmsgstr \"{$msgstr}\"\n\n";
    }

    file_put_contents(
        $path,
        "msgid \"\"\nmsgstr \"\"\n{$header}\n{$body}",
    );
}

/**
 * Calls Lang::load() via Reflection with a deliberately malformed
 * $options['language'] (load()'s own declared array-shape type requires
 * a real string there) -- load()'s own code defends against exactly
 * this at runtime regardless of the static type (its own docblock notes
 * $options is built from arbitrary, non-validated caller data in real
 * plugin/theme usage), so this is real, reachable behavior to test, not
 * a hypothetical. Reflection (rather than a direct call) is what lets
 * this pass a shape PHPStan would otherwise reject at the call site.
 */
function langTestLoad(Lang $lang, string $filename, string $dirname, mixed $options): string|bool
{
    $method = new ReflectionMethod(Lang::class, 'load');

    /** @var string|bool */
    return $method->invoke($lang, $filename, $dirname, $options);
}

function langTestMakeFatalRenderer(\stdClass $capture): HtmlRenderingInterface
{
    return new class($capture) implements HtmlRenderingInterface {
        public function __construct(private readonly \stdClass $capture)
        {
        }

        public function getCatDisplayName(array $catInformations, ?string $url = ''): string
        {
            return '';
        }

        public function getCatDisplayNameCache(
            string $uppercats,
            ?string $url = '',
            bool $singleLink = false,
            ?string $linkClass = null,
            ?string $authKey = null,
        ): string {
            return '';
        }

        public function nameCompare(array $a, array $b): int
        {
            return 0;
        }

        public function tagAlphaCompare(array $a, array $b): int
        {
            return 0;
        }

        public function accessDenied(RedirectServiceInterface $redirectService): never
        {
            throw new \RuntimeException('accessDenied');
        }

        public function badRequest(RedirectServiceInterface $redirectService, string $msg, ?string $alternateUrl = null): never
        {
            throw new \RuntimeException('badRequest');
        }

        public function pageNotFound(RedirectServiceInterface $redirectService, ?string $msg, ?string $alternateUrl = null): never
        {
            throw new \RuntimeException('pageNotFound');
        }

        public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never
        {
            $this->capture->lastMessage = $msg;

            throw new \RuntimeException('renderer-fatal:' . $msg);
        }

        public function getTagsContentTitle(array $tags): string
        {
            return '';
        }

        public function getCombinedCategoriesContentTitle(?array $category, array $combinedCategories): string
        {
            return '';
        }

        public function setStatusHeader(int $code, string $text = ''): void
        {
        }

        public function renderElementName(array $info): string
        {
            return '';
        }

        public function renderElementDescription(array $info, string $param = ''): string
        {
            return '';
        }

        public function getThumbnailTitle(array $info, string $title, string $comment = ''): string
        {
            return '';
        }
    };
}

function langTestMakeProvider(?string $defaultLanguage = null, ?string $currentLanguage = null): DefaultLanguageProviderInterface
{
    return new class($defaultLanguage, $currentLanguage) implements DefaultLanguageProviderInterface {
        public function __construct(
            private readonly ?string $defaultLanguage,
            private readonly ?string $currentLanguage,
        ) {
        }

        public function getDefaultLanguage(): string
        {
            return $this->defaultLanguage ?? \Piwigo\Core\AppInfo::DEFAULT_LANGUAGE;
        }

        public function getCurrentLanguage(): ?string
        {
            return $this->currentLanguage;
        }
    };
}

/**
 * @param array<string, string> $headers
 * @return array<string, string|bool>
 */
function langTestCallPoHeadersToLangInfo(Lang $lang, array $headers): array
{
    $headerObject = new Headers();
    foreach ($headers as $name => $value) {
        $headerObject->set($name, $value);
    }

    $method = new ReflectionMethod(Lang::class, 'poHeadersToLangInfo');

    /** @var array<string, string|bool> */
    return $method->invoke($lang, $headerObject);
}

/**
 * Singleton/service-locator elimination campaign, Phase 8: Lang is now a
 * real, constructor-injected instance (Translator/HtmlRenderingInterface/
 * Paths/InstallationFlag), so every test below constructs its own fresh
 * instance via langTestMake() instead of resetting shared static state --
 * strictly simpler than the old beforeEach()/afterEach() reset dance, same
 * simplification every large facade in this campaign has already gone
 * through (see e.g. AccessControlTest.php). $defaultLanguageProvider stays
 * a real, mutable, nullable property set via setDefaultLanguageProvider()
 * (see Lang's own class docblock for why) -- tests that need it call that
 * setter explicitly on their own instance instead of passing it in here.
 *
 * All 4 required collaborators default to cheap, DB-free real objects
 * (new Translator(), \Piwigo\Tests\Support\HtmlServiceTestFactory::build(), Paths::fromRoot(), new
 * InstallationFlag()) -- no test needs a fake for any of them except the
 * one HtmlRenderingInterface args()-fatalError test below, which needs to
 * observe/short-circuit fatalError() rather than let the real HtmlService
 * implementation run (which itself calls Lang::current(), a live container
 * resolve this file deliberately avoids needing for every other test).
 */
function langTestMake(
    ?Translator $translator = null,
    ?HtmlRenderingInterface $htmlRenderer = null,
    ?string $root = null,
    ?InstallationFlag $installationFlag = null,
): Lang {
    return new Lang(
        $translator ?? new Translator(new \Piwigo\Config\CurrentConfig()),
        $htmlRenderer ?? \Piwigo\Tests\Support\HtmlServiceTestFactory::build(),
        Paths::fromRoot($root ?? sys_get_temp_dir()),
        $installationFlag ?? new InstallationFlag(),
    );
}

beforeEach(function (): void {
    $this->langRoot = sys_get_temp_dir() . '/piwigo-lang-test-' . bin2hex(random_bytes(8));
    mkdir($this->langRoot, 0o777, true);
});

afterEach(function (): void {
    langTestRrmdir(is_string($this->langRoot) ? $this->langRoot : '');
    // Only the current() bridge tests below actually boot the Kernel, but
    // reset()ing unconditionally (matching PageStateTest.php/every other
    // converted facade's own test file) is cheap and guarantees a booted
    // Kernel never leaks from one test into the next.
    Kernel::reset();
});

test('t returns the key itself when nothing is loaded', function (): void {
    $lang = langTestMake();

    expect($lang->t('Some_Untranslated_Key'))->toBe('Some_Untranslated_Key');
});

test('t formats sprintf-style args', function (): void {
    $lang = langTestMake();
    $lang->loadArray(['Hello %s' => 'Bonjour %s']);

    expect($lang->t('Hello %s', 'World'))->toBe('Bonjour World');
});

test('plural delegates to the injected Translator, selecting the singular form for n=1', function (): void {
    $lang = langTestMake();

    expect($lang->plural('%d item', '%d items', 1))->toBe('1 item');
});

test('plural delegates to the injected Translator, selecting the plural form for n=0', function (): void {
    $lang = langTestMake();

    expect($lang->plural('%d item', '%d items', 0))->toBe('0 items');
});

test('plural coerces a numeric-string decimal to int rather than passing it through raw', function (): void {
    $lang = langTestMake();

    expect($lang->plural('%d item', '%d items', '0'))->toBe('0 items');
});

test('plural coerces a non-numeric decimal to 0 rather than passing it through raw', function (): void {
    $lang = langTestMake();

    expect($lang->plural('%d item', '%d items', 'not-a-number'))->toBe('0 items');
});

test('has reflects the loaded data set', function (): void {
    $lang = langTestMake();
    $lang->loadArray(['known' => 'value']);

    expect($lang->has('known'))->toBeTrue()
        ->and($lang->has('unknown'))->toBeFalse();
});

test('attachGlobals seeds from Translator\'s already-mirrored strings', function (): void {
    $translator = new Translator(new \Piwigo\Config\CurrentConfig());
    $translator->loadArray(['greeting' => 'hi']);
    $lang = langTestMake(translator: $translator);

    $lang->attachGlobals();

    expect($lang->has('greeting'))->toBeTrue();
});

test('attachGlobals takes a one-time snapshot -- a later Translator mirror change is not retroactively visible', function (): void {
    $translator = new Translator(new \Piwigo\Config\CurrentConfig());
    $translator->loadArray(['greeting' => 'hi']);
    $lang = langTestMake(translator: $translator);
    $lang->attachGlobals();

    $translator->loadArray(['greeting' => 'hi', 'legacy_key' => 'legacy value']);

    expect($lang->has('legacy_key'))->toBeFalse();
});

test('currentUserLanguage returns the installed provider\'s real value, not just null', function (): void {
    $lang = langTestMake();
    $lang->setDefaultLanguageProvider(langTestMakeProvider(currentLanguage: 'de_DE'));

    expect($lang->currentUserLanguage())->toBe('de_DE');
});

test('setDefaultLanguageProvider mutates the instance, flipping currentUserLanguage() from null to the provider\'s own value', function (): void {
    // Distinct from the test above -- this one proves the SAME instance's
    // observable behavior actually changes as a direct result of calling
    // the setter, not just that a pre-wired provider is readable.
    $lang = langTestMake();
    expect($lang->currentUserLanguage())->toBeNull();

    $lang->setDefaultLanguageProvider(langTestMakeProvider(currentLanguage: 'de_DE'));

    expect($lang->currentUserLanguage())->toBe('de_DE');
});

test('setLangInfo flips isLangInfoInitialized to true', function (): void {
    $lang = langTestMake();
    expect($lang->isLangInfoInitialized())->toBeFalse();

    $lang->setLangInfo(['parent' => 'pt_PT']);

    expect($lang->isLangInfoInitialized())->toBeTrue();
});

test('day returns the day name at the given index', function (): void {
    $lang = langTestMake();
    $lang->loadArray(['day' => [0 => 'Sunday', 1 => 'Monday']]);

    expect($lang->day(1))->toBe('Monday')
        ->and($lang->day(9))->toBe('');
});

test('month returns the month name at the given index', function (): void {
    $lang = langTestMake();
    $lang->loadArray(['month' => [1 => 'January']]);

    expect($lang->month(1))->toBe('January')
        ->and($lang->month(13))->toBe('');
});

test('days returns every day name keyed by day-of-week index', function (): void {
    $lang = langTestMake();
    $lang->loadArray(['day' => [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday']]);

    expect($lang->days())->toBe([0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday']);
});

test('days returns an empty array when nothing is loaded', function (): void {
    expect(langTestMake()->days())->toBe([]);
});

test('months returns every month name keyed by month number', function (): void {
    $lang = langTestMake();
    $lang->loadArray(['month' => [1 => 'January', 2 => 'February']]);

    expect($lang->months())->toBe([1 => 'January', 2 => 'February']);
});

test('months returns an empty array when nothing is loaded', function (): void {
    expect(langTestMake()->months())->toBe([]);
});

test('snapshot returns the currently active translation table, and restore replaces it wholesale', function (): void {
    $lang = langTestMake();
    $lang->loadArray(['greeting' => 'hi']);

    $snapshot = $lang->snapshot();
    expect($snapshot)->toBe(['greeting' => 'hi']);

    // Overwriting the live table must not retroactively mutate the
    // already-taken snapshot array (plain PHP array value semantics --
    // proven here, not assumed).
    $lang->loadArray(['other' => 'value']);
    expect($lang->has('greeting'))->toBeFalse()
        ->and($snapshot)->toBe(['greeting' => 'hi']);

    $lang->restore($snapshot);

    expect($lang->has('greeting'))->toBeTrue()
        ->and($lang->has('other'))->toBeFalse();
});

test('restore resets the translation table to empty when given null', function (): void {
    $lang = langTestMake();
    $lang->loadArray(['greeting' => 'hi']);

    $lang->restore(null);

    expect($lang->has('greeting'))->toBeFalse()
        ->and($lang->snapshot())->toBe([]);
});

test('attachGlobals silently drops a mirrored key that PHP auto-casts to an int (a purely numeric msgid)', function (): void {
    // '42' as an array-literal key is auto-cast to int(42) by the PHP
    // engine itself before Translator ever sees it -- filterLangValues()'s
    // is_string($k) guard is a real runtime necessity, not defensive
    // padding, for exactly this shape of gettext msgid. loadArray()'s own
    // declared signature requires string keys (its normal, real contract
    // for real callers) -- this is exactly what PHP's own key-casting
    // makes impossible to satisfy for a literal numeric-string key, so
    // $mirror is seeded directly via reflection here instead of going
    // through that type-checked method.
    $translator = new Translator(new \Piwigo\Config\CurrentConfig());
    $mirror = ['42' => 'forty-two', 'greeting' => 'hi'];
    new ReflectionProperty(Translator::class, 'mirror')->setValue($translator, $mirror);
    $lang = langTestMake(translator: $translator);

    $lang->attachGlobals();

    expect($lang->snapshot())->toBe(['greeting' => 'hi']);
});

test('buildArgs merges an array of positional args after the key', function (): void {
    expect(langTestMake()->buildArgs('%s scored %d points', ['Alice', 42]))->toBe([
        'key_args' => ['%s scored %d points', 'Alice', 42],
    ]);
});

test('buildArgs re-indexes a string-keyed args array into a plain positional list', function (): void {
    expect(langTestMake()->buildArgs('%s scored %d points', ['name' => 'Bob', 'score' => 7]))->toBe([
        'key_args' => ['%s scored %d points', 'Bob', 7],
    ]);
});

/**
 * fatalError()'s own former nullable-collaborator fallback (a plain
 * RuntimeException thrown directly when no HtmlRenderingInterface was
 * installed) is gone since Phase 8's conversion -- a constructed Lang
 * instance always has one, so args()'s invalid-argument path
 * unconditionally reaches the installed renderer's fatalError() now. This
 * single test replaces the pre-Phase-8 file's 2 separate "wired/not
 * wired" scenarios, which tested a state that can no longer exist (see
 * Lang::fatalError()'s own docblock -- PHPStan proves the old fallthrough
 * dead code).
 */
test('args delegates the fatal error to the installed HtmlRenderingInterface', function (): void {
    $capture = new stdClass();
    $lang = langTestMake(htmlRenderer: langTestMakeFatalRenderer($capture));

    expect(fn () => $lang->args('not-an-array'))
        ->toThrow(\RuntimeException::class, 'renderer-fatal:Lang::args: Invalid arguments');

    expect($capture->lastMessage)->toBe('Lang::args: Invalid arguments');
});

test('args skips a key_args entry whose value is not itself an array', function (): void {
    $lang = langTestMake();
    $lang->loadArray(['Hello %s' => 'Bonjour %s']);

    $result = $lang->args([
        'key_args' => 'not-an-array-either',
        ['key_args' => ['Hello %s', 'World']],
    ]);

    expect($result)->toBe("\nBonjour World");
});

test('args skips a key_args entry whose shifted translation key is not a string', function (): void {
    $lang = langTestMake();
    $lang->loadArray(['Hello %s' => 'Bonjour %s']);

    $result = $lang->args([
        'key_args' => [42, 'ignored'],
        ['key_args' => ['Hello %s', 'World']],
    ]);

    expect($result)->toBe("\nBonjour World");
});

test('args concatenates the separator onto an already-non-empty result, not replacing it', function (): void {
    // Every existing args() test above happens to have an empty $result
    // right before the separator is appended (the loop's own $first flag
    // suppresses it on the very first real contribution) -- neither `.=`
    // mutated to `=` on the sep line, nor on the key_args translation
    // line, is distinguishable from an empty string. A real 2-element
    // array where the FIRST element already produced real, non-empty
    // output is the only way to prove `.=` is load-bearing on either
    // line, not just cosmetically identical to `=`.
    $lang = langTestMake();
    $lang->loadArray(['Hello %s' => 'Bonjour %s', 'Bye %s' => 'Au revoir %s']);

    $result = $lang->args([
        ['key_args' => ['Hello %s', 'X']],
        'key_args' => ['Bye %s', 'Y'],
    ], ' | ');

    expect($result)->toBe('Bonjour X | Au revoir Y');
});

test('load tracks every plugin/theme language file it is asked for, keyed by dirname then filename, even when the file is never found', function (): void {
    $lang = langTestMake();

    $result = $lang->load('missing.lang', 'my-plugin/', ['language' => 'xx_XX']);

    expect($result)->toBeFalse()
        ->and($lang->languageFiles())->toBe([
            'my-plugin/' => [
                'missing.lang' => ['language' => 'xx_XX'],
            ],
        ]);
});

test('load resolves the file through the parent language recorded in langInfo when nothing else is asked for', function (): void {
    $lang = langTestMake();
    $lang->setLangInfo(['parent' => 'pt_PT']);
    $dirname = $this->langRoot . '/plugins/parent-only/';
    langTestWritePo($dirname . 'language/pt_PT/menu.po', 'pt_PT', 'Ola Mundo');

    $result = $lang->load('menu.lang', $dirname, ['no_fallback' => true]);

    expect($result)->toBeTrue()
        ->and($lang->t('Hello'))->toBe('Ola Mundo');
});

test('load converts a force_fallback of true into the application default language', function (): void {
    $lang = langTestMake();
    $dirname = $this->langRoot . '/plugins/default-fallback/';
    langTestWritePo($dirname . 'language/en_UK/greeting.po', 'en_UK', 'Hi (default language fixture)');

    $result = $lang->load('greeting.lang', $dirname, ['force_fallback' => true, 'no_fallback' => true]);

    expect($result)->toBeTrue()
        ->and($lang->t('Hello'))->toBe('Hi (default language fixture)');
});

test('load returns false when the matched file\'s .po sibling is not readable', function (): void {
    $lang = langTestMake();
    $dirname = $this->langRoot . '/plugins/no-po-sibling/';
    $rawFile = $dirname . 'language/it_IT/orphan.lang.php';
    @mkdir(dirname($rawFile), 0o777, true);
    file_put_contents($rawFile, '<?php // legacy stub with no .po sibling');

    $result = $lang->load('orphan.lang', $dirname, ['language' => 'it_IT', 'no_fallback' => true]);

    expect($result)->toBeFalse();
});

test('load also loads an explicit force_fallback language distinct from the selected one', function (): void {
    $lang = langTestMake();
    $dirname = $this->langRoot . '/plugins/explicit-fallback/';
    langTestWritePo($dirname . 'language/fr_FR/common.po', 'fr_FR', 'Bonjour le monde');
    langTestWritePo($dirname . 'language/es_ES/common.po', 'es_ES', 'Hola Mundo');

    $result = $lang->load('common.lang', $dirname, [
        'language' => 'fr_FR',
        'force_fallback' => 'es_ES',
        'no_fallback' => true,
    ]);

    expect($result)->toBeTrue()
        // Unlike the X-Piwigo-Parent branch below (which deliberately
        // re-loads the child last so it wins), this force_fallback block
        // has no such compensating re-load: es_ES is loaded strictly
        // after fr_FR, and Translator's underlying dictionary merge
        // (gettext/translator's addTranslations()) lets the later load
        // win for any shared key -- confirmed live, not assumed. The
        // fallback's own translation is what proves both loads actually
        // ran, not fr_FR's.
        ->and($lang->t('Hello'))->toBe('Hola Mundo');
});

/**
 * A mutation-testing sweep found 3 more confirmed-equivalent mutants in
 * the search-candidate loop, none chased further (all verified live via
 * temporary sed-applied mutations):
 * - `array_unique($languages)` unwrapped to a no-op: the search loop
 *   already stops at the FIRST existing candidate and breaks, so a
 *   duplicate value later in the list is simply never reached either
 *   way -- deduping changes nothing about which language ultimately
 *   gets selected. Confirmed with an explicit-language + current-user-
 *   language pair both resolving to the same real 'en_UK' value.
 * - `$source_file = '';`'s own initial value: every loop iteration that
 *   finds a match overwrites it before it's ever read, and the
 *   `$source_file === ''` early-return a few lines below the loop
 *   catches the not-found case identically regardless of what
 *   placeholder that initial value holds (a garbage path just fails the
 *   same is_readable() check a few lines further down instead of the
 *   earlier, equally-final `return false;`).
 * - `$selected_language = '';`'s own initial value: same shape --
 *   always overwritten together with $source_file in the same
 *   conditional block, or never read at all when nothing matches.
 */
test('load chases a parent language recorded in the loaded .po file\'s own X-Piwigo-Parent header, then re-loads the child so it still wins', function (): void {
    $lang = langTestMake();
    $dirname = $this->langRoot . '/plugins/po-parent/';
    langTestWritePo($dirname . 'language/de_DE/common.po', 'de_DE', 'Hallo Welt', parent: 'fr_FR');
    langTestWritePo($dirname . 'language/fr_FR/common.po', 'fr_FR', 'Bonjour le monde');

    $result = $lang->load('common.lang', $dirname, ['language' => 'de_DE', 'no_fallback' => true]);

    expect($result)->toBeTrue()
        ->and($lang->t('Hello'))->toBe('Hallo Welt');
});

test('load treats an empty dirname as "use the site root", both for the file lookup and for NOT tracking it as a language file', function (): void {
    // Kills line 291's BooleanAndToBooleanOr (both variants) + its own
    // EmptyStringToNotEmpty on the '' dirname literal (an empty dirname
    // must NOT be tracked as a plugin/theme language file, unlike every
    // other load() test here which passes a real plugin dirname), and
    // line 299's own EmptyStringToNotEmpty (dirname === '' must actually
    // trigger the $this->paths->root substitution -- proven here by a
    // real fixture only findable through that substituted root). Since
    // Phase 8's conversion, Paths is a plain constructor collaborator, so
    // this is a direct langTestMake(root: ...) instead of the pre-Phase-8
    // KernelContainerOverride::with([Paths::class => ...]) dance.
    $root = $this->langRoot . '/site-root-empty-dirname/';
    langTestWritePo($root . 'language/en_UK/greeting.po', 'en_UK', 'Hi (site root)');
    $lang = langTestMake(root: $root);

    $result = $lang->load('greeting.lang', '', ['language' => 'en_UK', 'no_fallback' => true]);

    expect($result)->toBeTrue()
        ->and($lang->t('Hello'))->toBe('Hi (site root)')
        ->and($lang->languageFiles())->toBe([]);
});

test('load never tracks a call with an empty filename', function (): void {
    $lang = langTestMake();

    $result = $lang->load('', 'my-plugin/', ['language' => 'xx_XX']);

    expect($result)->toBeFalse()
        ->and($lang->languageFiles())->toBe([]);
});

test('load never tracks a call whose options request raw return content', function (): void {
    $lang = langTestMake();

    $result = $lang->load('missing.txt', 'my-plugin/', ['return' => true]);

    expect($result)->toBeFalse()
        ->and($lang->languageFiles())->toBe([]);
});

test('load does not re-track (overwrite) a dirname/filename pair it has already tracked', function (): void {
    $lang = langTestMake();
    $lang->load('menu.lang', 'my-plugin/', ['language' => 'aa_AA']);
    $lang->load('menu.lang', 'my-plugin/', ['language' => 'bb_BB']);

    expect($lang->languageFiles())->toBe([
        'my-plugin/' => [
            'menu.lang' => ['language' => 'aa_AA'],
        ],
    ]);
});

test('load uses the default-language provider\'s own value, not the app default, once InstallationFlag is active', function (): void {
    $installationFlag = new InstallationFlag();
    $installationFlag->mark();
    $lang = langTestMake(installationFlag: $installationFlag);
    $lang->setDefaultLanguageProvider(langTestMakeProvider(defaultLanguage: 'zz_ZZ'));
    $dirname = $this->langRoot . '/plugins/installed-default/';
    langTestWritePo($dirname . 'language/zz_ZZ/greeting.po', 'zz_ZZ', 'Hi (provider default)');

    $result = $lang->load('greeting.lang', $dirname, ['force_fallback' => true, 'no_fallback' => true]);

    expect($result)->toBeTrue()
        ->and($lang->t('Hello'))->toBe('Hi (provider default)');
});

test('load falls back to the app default language, not a fatal error, when InstallationFlag is active but no provider was ever set', function (): void {
    // Kills line 305's RemoveNullSafeOperator: a real request can reach
    // InstallationFlag::isActive() === true before
    // setDefaultLanguageProvider() has ever run (or in a context that
    // never sets one at all) -- $defaultLanguageProvider stays null, and a
    // bare `->getDefaultLanguage()` on null would fatal instead of
    // gracefully falling through to `?? AppInfo::DEFAULT_LANGUAGE`.
    $installationFlag = new InstallationFlag();
    $installationFlag->mark();
    $lang = langTestMake(installationFlag: $installationFlag);
    $dirname = $this->langRoot . '/plugins/installed-no-provider/';
    langTestWritePo($dirname . 'language/en_UK/greeting.po', 'en_UK', 'Hi (app default, no provider)');

    $result = $lang->load('greeting.lang', $dirname, ['force_fallback' => true, 'no_fallback' => true]);

    expect($result)->toBeTrue()
        ->and($lang->t('Hello'))->toBe('Hi (app default, no provider)');
});

test('load excludes every sentinel value from the sentinel list, not treating it as a real explicit language', function (): void {
    // Kills line 315's whole in_array() sentinel-list mutation cluster
    // (FalseToTrue, DecrementInteger/IncrementInteger on the int 0
    // entry, and one RemoveArrayItem per sentinel): each sentinel value
    // below stringifies to a specific "wrong" path (bool false and ''
    // both stringify to '', ints/numeric-strings 0 and '0' both
    // stringify to '0') -- a real fixture placed at that wrong path
    // would be picked up FIRST (before the real default language) if
    // the sentinel were ever wrongly treated as an explicit language. An
    // array sentinel can't have a "wrong fixture" (it would crash on
    // string concatenation instead), so its own test relies on the
    // absence of a crash rather than a wrong-file assertion.
    $dirname = $this->langRoot . '/plugins/lang-sentinels/';
    langTestWritePo($dirname . 'language/greeting.po', '', 'WRONG (bool/empty-string path)');
    langTestWritePo($dirname . 'language/0/greeting.po', '0', 'WRONG (int/string-zero path)');
    langTestWritePo($dirname . 'language/en_UK/greeting.po', 'en_UK', 'RIGHT (real default)');

    $lang = langTestMake();
    foreach ([false, 0, '0', '', []] as $sentinel) {
        // No no_fallback here -- the default language ('en_UK', the
        // "RIGHT" fixture) must stay a real candidate for this test to
        // distinguish "correctly excluded, falls to default" from
        // "wrongly included, finds the wrong fixture first".
        $result = langTestLoad($lang, 'greeting.lang', $dirname, ['language' => $sentinel]);

        expect($result)->toBeTrue()
            ->and($lang->t('Hello'))->toBe('RIGHT (real default)');
    }
});

test('load includes a real current-user language from the provider as a candidate before the default', function (): void {
    // Kills line 319's IfNegated and RemoveNot (both would flip whether
    // a real, present current_user_language is ever tried at all).
    $lang = langTestMake();
    $lang->setDefaultLanguageProvider(langTestMakeProvider(currentLanguage: 'de_DE'));
    $dirname = $this->langRoot . '/plugins/current-user-lang/';
    langTestWritePo($dirname . 'language/de_DE/greeting.po', 'de_DE', 'Hallo (current user)');

    $result = $lang->load('greeting.lang', $dirname, ['no_fallback' => true]);

    expect($result)->toBeTrue()
        ->and($lang->t('Hello'))->toBe('Hallo (current user)');
});

test('load excludes a null or empty-string current-user language the same as "no preference"', function (): void {
    // Kills line 319's own RemoveArrayItem (on both the null and the ''
    // sentinel) and EmptyStringToNotEmpty: a real fixture at the
    // stringified wrong path (both null and '' cast to '') would be
    // picked up first if either sentinel were ever wrongly excluded
    // from the guard's own sentinel list.
    $dirname = $this->langRoot . '/plugins/current-user-lang-empty/';
    langTestWritePo($dirname . 'language/greeting.po', '', 'WRONG (empty current-user path)');
    langTestWritePo($dirname . 'language/en_UK/greeting.po', 'en_UK', 'RIGHT');

    $lang = langTestMake();
    foreach ([null, ''] as $currentLanguage) {
        $lang->setDefaultLanguageProvider(langTestMakeProvider(currentLanguage: $currentLanguage));

        $result = $lang->load('greeting.lang', $dirname, []);

        expect($result)->toBeTrue()
            ->and($lang->t('Hello'))->toBe('RIGHT');
    }
});

test('load excludes the default language entirely when no_fallback is true, even with no other candidate available', function (): void {
    // Kills line 335's CoalesceRemoveLeft: without a real no_fallback
    // check, the default language would always be tried regardless of
    // what was actually requested.
    $lang = langTestMake();
    $dirname = $this->langRoot . '/plugins/default-excluded/';
    langTestWritePo($dirname . 'language/en_UK/greeting.po', 'en_UK', 'Hi (default, should not be reached)');

    $result = $lang->load('greeting.lang', $dirname, ['no_fallback' => true]);

    expect($result)->toBeFalse();
});

test('load uses the flat, no-subdirectory local naming convention when local is true', function (): void {
    $lang = langTestMake();
    $dirname = $this->langRoot . '/plugins/local-lang/';
    @mkdir($dirname . 'language/', 0o777, true);
    file_put_contents(
        $dirname . 'language/en_UK.greeting.po',
        "msgid \"\"\nmsgstr \"\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Language: en_UK\\n\"\n\nmsgid \"Hello\"\nmsgstr \"Hi (local)\"\n",
    );

    $result = $lang->load('greeting.lang', $dirname, ['local' => true, 'language' => 'en_UK', 'no_fallback' => true]);

    expect($result)->toBeTrue()
        ->and($lang->t('Hello'))->toBe('Hi (local)');
});

test('load correctly rejects a candidate whose file genuinely does not exist, trying the next one instead', function (): void {
    // Kills line 363's second BooleanAndToBooleanOr: a broken OR-chain
    // there would treat ANY candidate as "found" once its po-sibling
    // path merely differs from the raw path (true for basically every
    // real request), even when neither file actually exists on disk --
    // breaking the search loop early on a bogus match instead of
    // continuing to a LATER candidate that genuinely does exist.
    // (Line 363's FIRST BooleanAndToBooleanOr -- `$po_sibling !== null &&
    // $po_sibling !== $f` turned into `||` -- is confirmed-equivalent,
    // verified live: `$po_sibling !== null` is true for every reachable
    // input this loop ever sees (preg_replace() only returns null on a
    // regex-engine failure, not a plain no-match), so `&&`/`||` against
    // it agree for every real candidate; not chased further.)
    $lang = langTestMake();
    $dirname = $this->langRoot . '/plugins/bogus-first-candidate/';
    langTestWritePo($dirname . 'language/en_UK/greeting.po', 'en_UK', 'Hi (default, the real match)');

    $result = $lang->load('greeting.lang', $dirname, ['language' => 'xx_XX']);

    expect($result)->toBeTrue()
        ->and($lang->t('Hello'))->toBe('Hi (default, the real match)');
});

test('load stops at the FIRST matching candidate, not a later one that also matches', function (): void {
    $lang = langTestMake();
    $lang->setDefaultLanguageProvider(langTestMakeProvider(currentLanguage: 'bb_BB'));
    $dirname = $this->langRoot . '/plugins/first-match-wins/';
    langTestWritePo($dirname . 'language/aa_AA/greeting.po', 'aa_AA', 'First match');
    langTestWritePo($dirname . 'language/bb_BB/greeting.po', 'bb_BB', 'Second match');

    $result = $lang->load('greeting.lang', $dirname, ['language' => 'aa_AA', 'no_fallback' => true]);

    expect($result)->toBeTrue()
        ->and($lang->t('Hello'))->toBe('First match');
});

test('load populates langInfo from the just-loaded po file\'s own headers, not an empty array', function (): void {
    // Kills line 399's TernaryNegated and InstanceOfToFalse: forcing the
    // instanceof check to always take the "not a real Translations"
    // branch would leave langInfo() with nothing merged in from the po
    // file at all -- 'zero_plural' is unconditionally set by
    // poHeadersToLangInfo() for every real po load, so its mere presence
    // proves the headers were actually read. (InstanceOfToTrue on this
    // same instanceof check is confirmed-equivalent: by the time this
    // line runs, load()'s own is_readable($po_file) check a few lines
    // above already guarantees Translator::load() returns a real
    // Translations, never null -- forcing the check to true changes
    // nothing.)
    $lang = langTestMake();
    $dirname = $this->langRoot . '/plugins/langinfo-populated/';
    langTestWritePo($dirname . 'language/en_UK/greeting.po', 'en_UK', 'Hi');

    $lang->load('greeting.lang', $dirname, ['language' => 'en_UK', 'no_fallback' => true]);

    expect($lang->langInfo())->toHaveKey('zero_plural');
});

test('load actually loads translations from the po-header-recorded parent, not just resolving its name', function (): void {
    // The existing "chases a parent... still wins" test only proves the
    // CHILD's own key resolves (which would be true even if the parent
    // chase never actually ran, since the child's own po already
    // defines it) -- a key that ONLY the parent po defines is the only
    // way to prove Translator::load($parent_language, ...) (lines
    // 409/430) genuinely executed. Also kills every concat mutation
    // building $parent_po (line 416): any garbled path would fail to
    // resolve "Parent Only" too.
    //
    // line 411's own EmptyStringToNotEmpty (on `$load_lang_info_parent
    // !== ''`) and line 415's own RemoveArrayItem/EmptyStringToNotEmpty
    // (on the '' entry of `[null, '']`) are confirmed-equivalent,
    // verified live: poHeadersToLangInfo() only ever adds a 'parent' key
    // when the header value is non-null AND non-empty (its own `$value
    // !== null && $value !== ''` guard), so $load_lang_info_parent (and,
    // by the same structural argument, $lang_info_parent and the final
    // $parent_language) can only ever be null or a genuinely non-empty
    // string -- never the literal ''. Every '' check on these values is
    // dead code guarding against a value shape that can't occur; not
    // chased further.
    $lang = langTestMake();
    $dirname = $this->langRoot . '/plugins/po-parent-unique-key/';
    langTestWritePoMulti($dirname . 'language/de_DE/common.po', 'de_DE', ['Hello' => 'Hallo Welt'], parent: 'fr_FR');
    langTestWritePoMulti($dirname . 'language/fr_FR/common.po', 'fr_FR', ['Hello' => 'Bonjour le monde', 'Parent Only' => 'Seulement parent']);

    $result = $lang->load('common.lang', $dirname, ['language' => 'de_DE', 'no_fallback' => true]);

    expect($result)->toBeTrue()
        ->and($lang->t('Hello'))->toBe('Hallo Welt')
        ->and($lang->t('Parent Only'))->toBe('Seulement parent');
});

test('load falls back to the pre-existing langInfo\'s own recorded parent when the just-loaded po has none of its own', function (): void {
    // Distinct from the parent-chase test above -- that one resolves
    // parent_language from the just-*loaded* po's own header
    // (load_lang_info_parent). This one's po defines no X-Piwigo-Parent
    // header at all, proving lines 410/411/413's own fallback to the
    // pre-existing $langInfo['parent'] (lang_info_parent) still
    // triggers the chase.
    $lang = langTestMake();
    $lang->setLangInfo(['parent' => 'pt_PT']);
    $dirname = $this->langRoot . '/plugins/langinfo-parent-fallback/';
    langTestWritePoMulti($dirname . 'language/de_DE/common.po', 'de_DE', ['Hello' => 'Hallo Welt']);
    langTestWritePoMulti($dirname . 'language/pt_PT/common.po', 'pt_PT', ['Hello' => 'Ola Mundo', 'Parent Only' => 'So o pai']);

    $result = $lang->load('common.lang', $dirname, ['language' => 'de_DE', 'no_fallback' => true]);

    expect($result)->toBeTrue()
        ->and($lang->t('Hello'))->toBe('Hallo Welt')
        ->and($lang->t('Parent Only'))->toBe('So o pai');
});

test('load never chases a "parent" when none is recorded anywhere, not even a null-derived bogus path', function (): void {
    $lang = langTestMake();
    $dirname = $this->langRoot . '/plugins/no-parent-at-all/';
    langTestWritePo($dirname . 'language/en_UK/greeting.po', 'en_UK', 'Hi (child, no parent)');
    // A trap file at the exact bogus path a wrongly-unguarded null
    // parent_language would stringify to (dirname . '' . '/' . basename) --
    // if this ever gets loaded, its own translation would leak in.
    langTestWritePo($dirname . 'language/greeting.po', '', 'TRAP (should never load)');

    $result = $lang->load('greeting.lang', $dirname, ['language' => 'en_UK', 'no_fallback' => true]);

    expect($result)->toBeTrue()
        ->and($lang->t('Hello'))->toBe('Hi (child, no parent)');
});

test('load merges the newly-parsed lang_info into the pre-existing langInfo, not just discarding it', function (): void {
    // Kills line 435's UnwrapArrayMerge and line 436's RemoveMethodCall:
    // either would leave langInfo() showing only the pre-existing
    // ['country' => ...] value, missing 'zero_plural' entirely.
    $lang = langTestMake();
    $lang->setLangInfo(['country' => 'preexisting-gb']);
    $dirname = $this->langRoot . '/plugins/langinfo-merge/';
    langTestWritePo($dirname . 'language/en_UK/greeting.po', 'en_UK', 'Hi');

    $lang->load('greeting.lang', $dirname, ['language' => 'en_UK', 'no_fallback' => true]);

    expect($lang->langInfo()['country'] ?? null)->toBe('preexisting-gb')
        ->and($lang->langInfo())->toHaveKey('zero_plural');
});

test('reset clears isLangInfoInitialized back to false, not leaving it true', function (): void {
    $lang = langTestMake();
    $lang->setLangInfo(['parent' => 'pt_PT']);
    expect($lang->isLangInfoInitialized())->toBeTrue();

    $lang->reset();

    expect($lang->isLangInfoInitialized())->toBeFalse();
});

test('getParentLanguage(with an explicit lang_id) reads the X-Piwigo-Parent header from that language\'s own common.po', function (): void {
    $root = $this->langRoot . '/site-root-with-parent/';
    langTestWritePo($root . 'language/de_DE/common.po', 'de_DE', 'Hallo Welt', parent: 'fr_FR');
    $lang = langTestMake(root: $root);

    $method = new ReflectionMethod(Lang::class, 'getParentLanguage');
    $result = $method->invoke($lang, 'de_DE');

    expect($result)->toBe('fr_FR');
});

test('getParentLanguage(with an explicit lang_id) returns null when that language has no common.po at all', function (): void {
    $root = $this->langRoot . '/site-root-without-file/';
    mkdir($root, 0o777, true);
    $lang = langTestMake(root: $root);

    $method = new ReflectionMethod(Lang::class, 'getParentLanguage');
    $result = $method->invoke($lang, 'xx_XX');

    expect($result)->toBeNull();
});

test('getParentLanguage(with an explicit empty string) reads from langInfo, same as the null default', function (): void {
    // Kills line 534's EmptyStringToNotEmpty: an explicit '' must take
    // the exact same "read $langInfo" branch as an omitted argument, not
    // fall through to the file-based lookup with an empty lang_id segment.
    $lang = langTestMake();
    $lang->setLangInfo(['parent' => 'pt_PT']);

    $method = new ReflectionMethod(Lang::class, 'getParentLanguage');
    $result = $method->invoke($lang, '');

    expect($result)->toBe('pt_PT');
});

test('getParentLanguage(no argument) returns null for a non-string langInfo parent, not the raw value', function (): void {
    // Kills line 536's BooleanAndToBooleanOr: langInfo's own declared
    // type (string|bool) allows a real bool value here.
    $lang = langTestMake();
    $lang->setLangInfo(['parent' => true]);

    $method = new ReflectionMethod(Lang::class, 'getParentLanguage');
    $result = $method->invoke($lang);

    expect($result)->toBeNull();
});

test('getParentLanguage(no argument) returns null for an explicitly empty-string langInfo parent', function (): void {
    // Kills line 536's EmptyStringToNotEmpty.
    $lang = langTestMake();
    $lang->setLangInfo(['parent' => '']);

    $method = new ReflectionMethod(Lang::class, 'getParentLanguage');
    $result = $method->invoke($lang);

    expect($result)->toBeNull();
});

test('getParentLanguage(with an explicit lang_id) returns null when the common.po\'s own X-Piwigo-Parent header is explicitly empty', function (): void {
    // Kills line 545's BooleanAndToBooleanOr and EmptyStringToNotEmpty:
    // an explicitly-empty header value (as opposed to an absent one,
    // already covered by the "no common.po at all" test above) must
    // still resolve to null, not the empty string itself.
    $root = $this->langRoot . '/site-root-empty-parent-header/';
    langTestWritePo($root . 'language/de_DE/common.po', 'de_DE', 'Hallo Welt', parent: '');
    $lang = langTestMake(root: $root);

    $method = new ReflectionMethod(Lang::class, 'getParentLanguage');
    $result = $method->invoke($lang, 'de_DE');

    expect($result)->toBeNull();
});

test('poHeadersToLangInfo maps every X-Piwigo-* header to its own langInfo key', function (): void {
    // A single exact-array assertion kills every RemoveArrayItem mutant
    // on the $map literal (7, one per key) plus ForeachEmptyIterable --
    // any one of them would drop that key from the result entirely.
    $result = langTestCallPoHeadersToLangInfo(langTestMake(), [
        'X-Piwigo-Language-Name' => 'English (UK)',
        'X-Piwigo-Country' => 'gb',
        'X-Piwigo-Direction' => 'ltr',
        'X-Piwigo-Code' => 'en_UK',
        'X-Piwigo-Parent' => 'en_US',
        'X-Piwigo-Jquery-Code' => 'en',
        'X-Piwigo-Plupload-Code' => 'en',
        'X-Piwigo-Zero-Plural' => 'true',
    ]);

    expect($result)->toBe([
        'language_name' => 'English (UK)',
        'country' => 'gb',
        'direction' => 'ltr',
        'code' => 'en_UK',
        'parent' => 'en_US',
        'jquery_code' => 'en',
        'plupload_code' => 'en',
        'zero_plural' => true,
    ]);
});

test('poHeadersToLangInfo omits keys whose header is absent or explicitly empty', function (): void {
    // Kills line 576's IfNegated/NotIdenticalToIdentical (x2)/
    // BooleanAndToBooleanOr/EmptyStringToNotEmpty: an explicitly-empty
    // header (language_name) and a genuinely-absent one (country) must
    // both be excluded, while a real value (code) stays -- and line
    // 580's IdenticalToNotIdentical: an absent zero_plural header must
    // resolve to false, not true.
    $result = langTestCallPoHeadersToLangInfo(langTestMake(), [
        'X-Piwigo-Language-Name' => '',
        'X-Piwigo-Code' => 'en_UK',
    ]);

    expect($result)->toBe([
        'code' => 'en_UK',
        'zero_plural' => false,
    ]);
});

test('current() resolves the real container-shared instance once Kernel::boot() has run', function (): void {
    Kernel::boot(Paths::fromRoot(is_string($this->langRoot) ? $this->langRoot : sys_get_temp_dir()));

    $instance = Kernel::container()->get(Lang::class);

    expect(Lang::current())->toBe($instance);
});

test('current() throws when Kernel has not been booted -- no memoized pre-boot fallback, unlike e.g. CurrentUser::current()', function (): void {
    expect(fn () => Lang::current())
        ->toThrow(\LogicException::class, 'Kernel not booted — call Kernel::boot() first.');
});
