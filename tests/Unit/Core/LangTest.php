<?php

declare(strict_types=1);

use Piwigo\Core\CurrentPaths;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
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
    @mkdir(dirname($path), 0o777, true);
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

        public function accessDenied(\Piwigo\Core\RedirectServiceInterface $redirectService): never
        {
            throw new \RuntimeException('accessDenied');
        }

        public function badRequest(\Piwigo\Core\RedirectServiceInterface $redirectService, string $msg, ?string $alternateUrl = null): never
        {
            throw new \RuntimeException('badRequest');
        }

        public function pageNotFound(\Piwigo\Core\RedirectServiceInterface $redirectService, ?string $msg, ?string $alternateUrl = null): never
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

beforeEach(function (): void {
    Lang::reset();
    Translator::reset();
    $this->langRoot = sys_get_temp_dir() . '/piwigo-lang-test-' . bin2hex(random_bytes(8));
    mkdir($this->langRoot, 0o777, true);
});

afterEach(function (): void {
    Lang::reset();
    Translator::reset();
    CurrentPaths::reset();
    langTestRrmdir(is_string($this->langRoot) ? $this->langRoot : '');
});

test('t returns the key itself when nothing is loaded', function (): void {
    expect(Lang::t('Some_Untranslated_Key'))->toBe('Some_Untranslated_Key');
});

test('t formats sprintf-style args', function (): void {
    Translator::get()->loadArray(['Hello %s' => 'Bonjour %s']);

    expect(Lang::t('Hello %s', 'World'))->toBe('Bonjour World');
});

test('has reflects the loaded data set', function (): void {
    Lang::loadArray(['known' => 'value']);

    expect(Lang::has('known'))->toBeTrue()
        ->and(Lang::has('unknown'))->toBeFalse();
});

test('attachGlobals seeds from Translator\'s already-mirrored strings', function (): void {
    Translator::get()->loadArray(['greeting' => 'hi']);

    Lang::attachGlobals();

    expect(Lang::has('greeting'))->toBeTrue();
});

test('attachGlobals takes a one-time snapshot -- a later Translator mirror change is not retroactively visible', function (): void {
    Translator::get()->loadArray(['greeting' => 'hi']);
    Lang::attachGlobals();

    Translator::get()->loadArray(['greeting' => 'hi', 'legacy_key' => 'legacy value']);

    expect(Lang::has('legacy_key'))->toBeFalse();
});

test('day returns the day name at the given index', function (): void {
    Lang::loadArray(['day' => [0 => 'Sunday', 1 => 'Monday']]);

    expect(Lang::day(1))->toBe('Monday')
        ->and(Lang::day(9))->toBe('');
});

test('month returns the month name at the given index', function (): void {
    Lang::loadArray(['month' => [1 => 'January']]);

    expect(Lang::month(1))->toBe('January')
        ->and(Lang::month(13))->toBe('');
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
    $mirror = ['42' => 'forty-two', 'greeting' => 'hi'];
    new ReflectionProperty(Translator::class, 'mirror')->setValue(Translator::get(), $mirror);

    Lang::attachGlobals();

    expect(Lang::snapshot())->toBe(['greeting' => 'hi']);
});

test('buildArgs merges an array of positional args after the key', function (): void {
    expect(Lang::buildArgs('%s scored %d points', ['Alice', 42]))->toBe([
        'key_args' => ['%s scored %d points', 'Alice', 42],
    ]);
});

test('buildArgs re-indexes a string-keyed args array into a plain positional list', function (): void {
    expect(Lang::buildArgs('%s scored %d points', ['name' => 'Bob', 'score' => 7]))->toBe([
        'key_args' => ['%s scored %d points', 'Bob', 7],
    ]);
});

test('args throws via the default RuntimeException fallback when $key_args is not an array and no renderer is installed', function (): void {
    expect(fn () => Lang::args('not-an-array'))
        ->toThrow(\RuntimeException::class, 'Lang::args: Invalid arguments');
});

test('args delegates the fatal error to an installed HtmlRenderingInterface instead of throwing RuntimeException directly', function (): void {
    $capture = new stdClass();
    Lang::setHtmlRenderer(langTestMakeFatalRenderer($capture));

    expect(fn () => Lang::args(42))
        ->toThrow(\RuntimeException::class, 'renderer-fatal:Lang::args: Invalid arguments');

    expect($capture->lastMessage)->toBe('Lang::args: Invalid arguments');
});

test('args skips a key_args entry whose value is not itself an array', function (): void {
    Lang::loadArray(['Hello %s' => 'Bonjour %s']);

    $result = Lang::args([
        'key_args' => 'not-an-array-either',
        ['key_args' => ['Hello %s', 'World']],
    ]);

    expect($result)->toBe("\nBonjour World");
});

test('args skips a key_args entry whose shifted translation key is not a string', function (): void {
    Lang::loadArray(['Hello %s' => 'Bonjour %s']);

    $result = Lang::args([
        'key_args' => [42, 'ignored'],
        ['key_args' => ['Hello %s', 'World']],
    ]);

    expect($result)->toBe("\nBonjour World");
});

test('load tracks every plugin/theme language file it is asked for, keyed by dirname then filename, even when the file is never found', function (): void {
    $result = Lang::load('missing.lang', 'my-plugin/', ['language' => 'xx_XX']);

    expect($result)->toBeFalse()
        ->and(Lang::languageFiles())->toBe([
            'my-plugin/' => [
                'missing.lang' => ['language' => 'xx_XX'],
            ],
        ]);
});

test('load resolves the file through the parent language recorded in langInfo when nothing else is asked for', function (): void {
    Lang::setLangInfo(['parent' => 'pt_PT']);
    $dirname = $this->langRoot . '/plugins/parent-only/';
    langTestWritePo($dirname . 'language/pt_PT/menu.po', 'pt_PT', 'Ola Mundo');

    $result = Lang::load('menu.lang', $dirname, ['no_fallback' => true]);

    expect($result)->toBeTrue()
        ->and(Translator::get()->translate('Hello'))->toBe('Ola Mundo');
});

test('load converts a force_fallback of true into the application default language', function (): void {
    $dirname = $this->langRoot . '/plugins/default-fallback/';
    langTestWritePo($dirname . 'language/en_UK/greeting.po', 'en_UK', 'Hi (default language fixture)');

    $result = Lang::load('greeting.lang', $dirname, ['force_fallback' => true, 'no_fallback' => true]);

    expect($result)->toBeTrue()
        ->and(Translator::get()->translate('Hello'))->toBe('Hi (default language fixture)');
});

test('load returns false when the matched file\'s .po sibling is not readable', function (): void {
    $dirname = $this->langRoot . '/plugins/no-po-sibling/';
    $rawFile = $dirname . 'language/it_IT/orphan.lang.php';
    @mkdir(dirname($rawFile), 0o777, true);
    file_put_contents($rawFile, '<?php // legacy stub with no .po sibling');

    $result = Lang::load('orphan.lang', $dirname, ['language' => 'it_IT', 'no_fallback' => true]);

    expect($result)->toBeFalse();
});

test('load also loads an explicit force_fallback language distinct from the selected one', function (): void {
    $dirname = $this->langRoot . '/plugins/explicit-fallback/';
    langTestWritePo($dirname . 'language/fr_FR/common.po', 'fr_FR', 'Bonjour le monde');
    langTestWritePo($dirname . 'language/es_ES/common.po', 'es_ES', 'Hola Mundo');

    $result = Lang::load('common.lang', $dirname, [
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
        ->and(Translator::get()->translate('Hello'))->toBe('Hola Mundo');
});

test('load chases a parent language recorded in the loaded .po file\'s own X-Piwigo-Parent header, then re-loads the child so it still wins', function (): void {
    $dirname = $this->langRoot . '/plugins/po-parent/';
    langTestWritePo($dirname . 'language/de_DE/common.po', 'de_DE', 'Hallo Welt', parent: 'fr_FR');
    langTestWritePo($dirname . 'language/fr_FR/common.po', 'fr_FR', 'Bonjour le monde');

    $result = Lang::load('common.lang', $dirname, ['language' => 'de_DE', 'no_fallback' => true]);

    expect($result)->toBeTrue()
        ->and(Translator::get()->translate('Hello'))->toBe('Hallo Welt');
});

test('getParentLanguage(with an explicit lang_id) reads the X-Piwigo-Parent header from that language\'s own common.po', function (): void {
    $root = $this->langRoot . '/site-root-with-parent/';
    langTestWritePo($root . 'language/de_DE/common.po', 'de_DE', 'Hallo Welt', parent: 'fr_FR');
    CurrentPaths::set(Paths::fromRoot($root));

    $method = new ReflectionMethod(Lang::class, 'getParentLanguage');
    $result = $method->invoke(null, 'de_DE');

    expect($result)->toBe('fr_FR');
});

test('getParentLanguage(with an explicit lang_id) returns null when that language has no common.po at all', function (): void {
    $root = $this->langRoot . '/site-root-without-file/';
    mkdir($root, 0o777, true);
    CurrentPaths::set(Paths::fromRoot($root));

    $method = new ReflectionMethod(Lang::class, 'getParentLanguage');
    $result = $method->invoke(null, 'xx_XX');

    expect($result)->toBeNull();
});
